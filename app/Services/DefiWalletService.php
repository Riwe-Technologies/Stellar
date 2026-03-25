<?php

namespace App\Services;

use App\Models\User;
use App\Models\DefiWallet;
use App\Models\DefiTransaction;
use App\Models\FiatOnramp;
use App\Models\StellarWallet;
use App\Services\StellarWalletService;
use App\Services\PaystackBankVerificationService;
use App\Services\BvnVerificationService;
use App\Services\CustodialAddressService;
use App\Services\StellarService;
use App\Services\PriceFeedService;
use App\Services\ConversionFeeService;
use App\Services\WalletPlusSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class DefiWalletService
{
    protected StellarWalletService $stellarWalletService;
    public PaystackBankVerificationService $paystackBankService;
    protected BvnVerificationService $bvnService;
    protected CustodialAddressService $custodialAddressService;
    protected StellarService $stellarService;
    protected PriceFeedService $priceFeedService;
    protected WalletPlusSettingsService $settingsService;

    public function __construct(
        StellarWalletService $stellarWalletService,
        PaystackBankVerificationService $paystackBankService,
        BvnVerificationService $bvnService,
        CustodialAddressService $custodialAddressService,
        StellarService $stellarService,
        PriceFeedService $priceFeedService,
        WalletPlusSettingsService $settingsService
    ) {
        $this->stellarWalletService = $stellarWalletService;
        $this->paystackBankService = $paystackBankService;
        $this->bvnService = $bvnService;
        $this->custodialAddressService = $custodialAddressService;
        $this->stellarService = $stellarService;
        $this->priceFeedService = $priceFeedService;
        $this->settingsService = $settingsService;
    }

    /**
     * Create or get a DeFi wallet for a user
     * Auto-enables wallet for users with email verification
     */
    public function createOrGetWallet(User $user): DefiWallet
    {
        $wallet = $user->defiWallet;

        if (!$wallet) {
            DB::beginTransaction();
            try {
                // Create Stellar wallet if user doesn't have one
                $stellarWallet = $user->stellarWallet;
                if (!$stellarWallet && $user->hasStellarEnabled()) {
                    $stellarWallet = $this->stellarWalletService->createWallet($user);
                }

                $kycLevel = $this->determineKycLevel($user);
                $isEligible = $user->hasVerifiedEmail(); // Only need email verification

                // Create DeFi wallet - auto-enable for eligible users
                $wallet = DefiWallet::create([
                    'user_id' => $user->id,
                    'stellar_wallet_id' => $stellarWallet?->id,
                    'status' => $isEligible ? 'active' : 'inactive',
                    'is_enabled' => $isEligible,
                    'enabled_at' => $isEligible ? now() : null,
                    'preferred_fiat_currency' => $user->preferred_currency ?? 'NGN',
                    'kyc_level' => $kycLevel,
                    'requires_kyc_for_fiat' => true,
                    'kyc_limit_threshold' => 50000, // 50K NGN
                    'daily_limit_fiat' => $this->getDailyLimitForKycLevel($kycLevel),
                    'monthly_limit_fiat' => $this->getMonthlyLimitForKycLevel($kycLevel),
                ]);

                // Generate custodial addresses for the wallet
                $addresses = $this->custodialAddressService->generateAddressesForWallet($wallet);

                // If we have a linked Stellar wallet, use its address instead of generating a new one
                if ($stellarWallet) {
                    $addresses['stellar_address'] = $stellarWallet->public_key;
                }

                $wallet->updateAddresses($addresses);

                DB::commit();

                Log::info('DeFi wallet created', [
                    'user_id' => $user->id,
                    'wallet_id' => $wallet->id,
                    'stellar_wallet_id' => $stellarWallet?->id,
                    'auto_enabled' => $isEligible,
                    'kyc_level' => $kycLevel
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Failed to create DeFi wallet', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }

        return $wallet;
    }

    /**
     * Comprehensive Stellar wallet synchronization
     */
    public function syncWithStellarWallet(DefiWallet $wallet): array
    {
        try {
            if (!$wallet->stellarWallet) {
                return [
                    'success' => false,
                    'message' => 'No Stellar wallet associated'
                ];
            }

            $stellarBalances = $this->stellarWalletService->getWalletBalance($wallet->stellarWallet);
            $updatedBalances = [];
            $hasUpdates = false;

            foreach ($stellarBalances as $balance) {
                $assetCode = $balance['asset_code'];
                $currentBalance = (float) $balance['balance'];

                // Update balances based on asset type
                switch ($assetCode) {
                    case 'XLM':
                        if ($wallet->balance_xlm != $currentBalance) {
                            $wallet->update(['balance_xlm' => $currentBalance]);
                            $hasUpdates = true;
                        }
                        $updatedBalances['xlm'] = $currentBalance;
                        break;

                    case 'USDC':
                        if ($wallet->balance_usd != $currentBalance) {
                            $wallet->update(['balance_usd' => $currentBalance]);
                            $hasUpdates = true;
                        }
                        $updatedBalances['usd'] = $currentBalance;
                        break;

                    default:
                        // Handle custom assets
                        $customAssets = $wallet->custom_asset_balances ?? [];
                        if (!isset($customAssets[$assetCode]) || $customAssets[$assetCode] != $currentBalance) {
                            $customAssets[$assetCode] = $currentBalance;
                            $wallet->update(['custom_asset_balances' => $customAssets]);
                            $hasUpdates = true;
                        }
                        $updatedBalances['custom_assets'][$assetCode] = $currentBalance;
                        break;
                }
            }

            // Update last sync timestamp
            if ($hasUpdates) {
                $wallet->update(['last_stellar_sync' => now()]);

                // TODO: Add audit service injection
                // $this->auditService->logBalanceSync($wallet->user, $wallet, $updatedBalances);
            }

            return [
                'success' => true,
                'balances' => $updatedBalances,
                'has_updates' => $hasUpdates,
                'sync_timestamp' => now()
            ];

        } catch (\Exception $e) {
            Log::error('Failed to sync with Stellar wallet', [
                'wallet_id' => $wallet->id,
                'stellar_wallet_id' => $wallet->stellar_wallet_id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Handle direct Stellar deposit
     */
    public function handleStellarDeposit(DefiWallet $wallet, array $depositData): array
    {
        try {
            DB::beginTransaction();

            // Validate deposit data
            $requiredFields = ['amount', 'asset_code', 'transaction_hash', 'from_address'];
            foreach ($requiredFields as $field) {
                if (!isset($depositData[$field])) {
                    throw new \Exception("Missing required field: {$field}");
                }
            }

            // Create DeFi transaction record
            $transaction = DefiTransaction::create([
                'user_id' => $wallet->user_id,
                'defi_wallet_id' => $wallet->id,
                'reference' => 'STELLAR-DEP-' . strtoupper(Str::random(8)),
                'type' => 'deposit_crypto',
                'status' => 'pending',
                'amount' => $depositData['amount'],
                'currency' => $depositData['asset_code'],
                'transaction_hash' => $depositData['transaction_hash'],
                'from_address' => $depositData['from_address'],
                'to_address' => $wallet->stellarWallet->public_key ?? null,
                'description' => "Direct Stellar deposit of {$depositData['amount']} {$depositData['asset_code']}",
                'network' => config('stellar.default_network'),
            ]);

            // Update wallet balance
            $this->updateWalletBalance($wallet, $depositData['asset_code'], $depositData['amount']);

            // Mark transaction as completed
            $transaction->update([
                'status' => 'completed',
                'completed_at' => now()
            ]);

            DB::commit();

            // Log the deposit
            // TODO: Add audit service injection
            // $this->auditService->logCryptoDeposit(
            //     $wallet->user,
            //     $wallet,
            //     $depositData['amount'],
            //     $depositData['asset_code'],
            //     $depositData['transaction_hash']
            // );

            return [
                'success' => true,
                'transaction' => $transaction,
                'message' => 'Stellar deposit processed successfully'
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to process Stellar deposit', [
                'wallet_id' => $wallet->id,
                'deposit_data' => $depositData,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to process deposit: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Auto-create DeFi wallet for new users
     */
    public function autoCreateWalletForUser(User $user): DefiWallet
    {
        return $this->createOrGetWallet($user);
    }

    /**
     * Enable DeFi wallet for a user
     */
    public function enableWallet(User $user): array
    {
        try {
            $wallet = $this->createOrGetWallet($user);

            // Check if user meets requirements
            $requirements = $this->checkWalletRequirements($user);
            if (!$requirements['eligible']) {
                return [
                    'success' => false,
                    'message' => 'User does not meet wallet requirements',
                    'requirements' => $requirements
                ];
            }

            // Enable Stellar if not already enabled
            if (!$user->hasStellarEnabled()) {
                $user->enableStellar();
            }

            // Create or activate Stellar wallet
            $stellarWallet = $user->stellarWallet;
            if (!$stellarWallet) {
                $stellarWallet = $this->stellarWalletService->createWallet($user);
                $wallet->update([
                    'stellar_wallet_id' => $stellarWallet->id,
                    'stellar_address' => $stellarWallet->public_key
                ]);
            } elseif (!$wallet->stellar_wallet_id || $wallet->stellar_address !== $stellarWallet->public_key) {
                // Ensure DeFi wallet is properly linked to existing Stellar wallet
                $wallet->update([
                    'stellar_wallet_id' => $stellarWallet->id,
                    'stellar_address' => $stellarWallet->public_key
                ]);
            }

            // Enable the DeFi wallet
            $wallet->enable();

            return [
                'success' => true,
                'message' => 'DeFi wallet enabled successfully',
                'wallet' => $wallet
            ];

        } catch (\Exception $e) {
            Log::error('Failed to enable DeFi wallet', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to enable DeFi wallet: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get wallet balance in multiple currencies
     */
    public function getWalletBalance(DefiWallet $wallet): array
    {
        try {
            $balances = [
                'xlm' => (float) $wallet->balance_xlm,
                'usd' => (float) $wallet->balance_usd,
                'ngn' => (float) $wallet->balance_ngn,
                'custom_assets' => $wallet->custom_asset_balances ?? []
            ];

            // Comprehensive Stellar wallet sync
            if ($wallet->stellarWallet) {
                $syncResult = $this->syncWithStellarWallet($wallet);
                if ($syncResult['success']) {
                    $balances = array_merge($balances, $syncResult['balances']);
                }
            }

            return [
                'success' => true,
                'balances' => $balances,
                'total_usd' => $this->calculateTotalUsdValue($balances),
                'last_updated' => now()
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get wallet balance', [
                'wallet_id' => $wallet->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to retrieve wallet balance',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Initiate fiat deposit
     */
    public function initiateFiatDeposit(DefiWallet $wallet, array $depositData): array
    {
        try {
            DB::beginTransaction();

            // Validate deposit amount and limits
            $validation = $this->validateFiatTransaction($wallet, $depositData['amount'], 'deposit');
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => $validation['message']
                ];
            }

            // Create DeFi transaction record
            $transaction = DefiTransaction::create([
                'user_id' => $wallet->user_id,
                'defi_wallet_id' => $wallet->id,
                'reference' => 'DEFI-DEP-' . strtoupper(Str::random(8)),
                'type' => 'deposit_fiat',
                'status' => 'pending',
                'amount' => $depositData['amount'],
                'currency' => 'XLM', // Will be converted to XLM
                'amount_fiat' => $depositData['amount'],
                'fiat_currency' => $depositData['currency'] ?? 'NGN',
                'exchange_rate' => $this->getCurrentExchangeRate('NGN', 'XLM'),
                'rate_source' => 'internal',
                'rate_timestamp' => now(),
                'payment_method' => 'paystack',
                'description' => 'Fiat deposit to DeFi wallet',
                'expires_at' => now()->addHours(1),
            ]);

            // Create fiat onramp record
            $onramp = FiatOnramp::create([
                'user_id' => $wallet->user_id,
                'defi_wallet_id' => $wallet->id,
                'defi_transaction_id' => $transaction->id,
                'provider' => 'paystack',
                'provider_reference' => 'PS-' . $transaction->reference,
                'type' => 'deposit',
                'status' => 'initiated',
                'fiat_amount' => $depositData['amount'],
                'fiat_currency' => $depositData['currency'] ?? 'NGN',
                'crypto_amount' => $depositData['amount'] / $transaction->exchange_rate,
                'crypto_currency' => 'XLM',
                'exchange_rate' => $transaction->exchange_rate,
                'rate_source' => 'internal',
                'provider_fee' => $this->calculateProviderFee($depositData['amount']),
                'platform_fee' => $this->calculatePlatformFee($depositData['amount']),
                'expires_at' => now()->addHours(1),
            ]);

            $onramp->total_fees = $onramp->provider_fee + $onramp->platform_fee;
            $onramp->save();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Fiat deposit initiated',
                'transaction' => $transaction,
                'onramp' => $onramp,
                'next_step' => 'payment_authorization'
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to initiate fiat deposit', [
                'wallet_id' => $wallet->id,
                'deposit_data' => $depositData,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to initiate deposit: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process Paystack payment for fiat deposit
     */
    public function processPaystackDeposit(FiatOnramp $onramp, array $paystackData): array
    {
        try {
            DB::beginTransaction();

            // Update onramp with Paystack data
            $onramp->update([
                'paystack_authorization_url' => $paystackData['authorization_url'] ?? null,
                'paystack_access_code' => $paystackData['access_code'] ?? null,
                'paystack_response' => $paystackData,
                'status' => 'pending_payment',
                'payment_initiated_at' => now(),
            ]);

            // Update the associated transaction
            $onramp->defiTransaction->update([
                'status' => 'processing',
                'external_reference' => $paystackData['reference'] ?? null,
                'payment_details' => $paystackData,
                'initiated_at' => now(),
            ]);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Payment processing initiated',
                'authorization_url' => $paystackData['authorization_url'] ?? null,
                'onramp' => $onramp
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to process Paystack deposit', [
                'onramp_id' => $onramp->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to process payment: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check wallet requirements for enabling
     */
    public function checkWalletRequirements(User $user): array
    {
        $requirements = [
            'email_verified' => $user->hasVerifiedEmail(),
            'phone_verified' => !empty($user->phone_number),
            // Removed BVN requirement for wallet creation
            // BVN is only needed for higher transaction limits
        ];

        $eligible = array_reduce($requirements, function ($carry, $item) {
            return $carry && $item;
        }, true);

        return [
            'eligible' => $eligible,
            'requirements' => $requirements,
            'missing' => array_keys(array_filter($requirements, fn($req) => !$req))
        ];
    }

    /**
     * Determine KYC level based on user verification status
     */
    public function determineKycLevel(User $user): string
    {
        if ($user->bvn_verified && $user->kyc_status === 'verified') {
            return 'enhanced';
        } elseif ($user->kyc_status === 'verified') {
            return 'basic';
        } elseif ($user->hasVerifiedEmail()) {
            return 'basic'; // Email verified users get basic level
        }
        return 'none';
    }

    /**
     * Validate fiat transaction against limits and requirements
     */
    protected function validateFiatTransaction(DefiWallet $wallet, float $amount, string $type): array
    {
        // Check if wallet can perform fiat operations
        if (!$wallet->canPerformFiatOperations()) {
            return [
                'valid' => false,
                'message' => 'Wallet is not enabled for fiat operations'
            ];
        }

        // Check KYC requirements
        if ($wallet->requiresKycForAmount($amount)) {
            if ($wallet->kyc_level === 'none') {
                return [
                    'valid' => false,
                    'message' => 'KYC verification required for this amount'
                ];
            }
        }

        // Check daily limits
        if ($wallet->hasExceededDailyLimit($amount)) {
            return [
                'valid' => false,
                'message' => 'Daily transaction limit exceeded'
            ];
        }

        // Check monthly limits
        if ($wallet->hasExceededMonthlyLimit($amount)) {
            return [
                'valid' => false,
                'message' => 'Monthly transaction limit exceeded'
            ];
        }

        return ['valid' => true];
    }

    /**
     * Calculate current exchange rate between currencies
     */
    protected function getCurrentExchangeRate(string $from, string $to): float
    {
        // This would integrate with a real exchange rate API
        // For now, using mock rates
        $rates = [
            'NGN_XLM' => 0.0012, // 1 NGN = 0.0012 XLM (example)
            'USD_XLM' => 8.5,    // 1 USD = 8.5 XLM (example)
        ];

        $pair = $from . '_' . $to;
        return $rates[$pair] ?? 1.0;
    }

    /**
     * Calculate provider fee (Paystack fee)
     */
    protected function calculateProviderFee(float $amount): float
    {
        // Get settings from database
        $percentage = (float) setting('defi_deposit_paystack_fee_percentage', 1.5);
        $fixed = (float) setting('defi_deposit_paystack_fee_fixed', 100);
        $cap = (float) setting('defi_deposit_paystack_fee_cap', 2000);

        // Paystack fee structure: percentage + fixed amount, capped at maximum
        $fee = ($amount * $percentage / 100) + $fixed;
        return min($fee, $cap);
    }

    /**
     * Calculate platform fee
     */
    protected function calculatePlatformFee(float $amount): float
    {
        // Get platform fee percentage from settings
        $percentage = (float) setting('defi_deposit_platform_fee_percentage', 0.5);
        return $amount * $percentage / 100;
    }

    /**
     * Calculate total USD value of all balances
     */
    protected function calculateTotalUsdValue(array $balances): float
    {
        $total = $balances['usd'];
        
        // Add XLM value (mock rate: 1 XLM = $0.12)
        $total += $balances['xlm'] * 0.12;
        
        // Add NGN value (mock rate: 1 USD = 800 NGN)
        $total += $balances['ngn'] / 800;

        return $total;
    }



    /**
     * Get daily limit based on KYC level
     */
    protected function getDailyLimitForKycLevel(string $kycLevel): float
    {
        return match ($kycLevel) {
            'enhanced' => 2000000,  // 2M NGN
            'basic' => 500000,      // 500K NGN
            'none' => 100000,       // 100K NGN
            default => 100000
        };
    }

    /**
     * Get monthly limit based on KYC level
     */
    protected function getMonthlyLimitForKycLevel(string $kycLevel): float
    {
        return match ($kycLevel) {
            'enhanced' => 10000000, // 10M NGN
            'basic' => 2000000,     // 2M NGN
            'none' => 500000,       // 500K NGN
            default => 500000
        };
    }

    /**
     * Initiate fiat withdrawal
     */
    public function initiateFiatWithdrawal(DefiWallet $wallet, array $withdrawalData): array
    {
        try {
            DB::beginTransaction();

            // Get conversion fee service and calculate real fees
            $conversionFeeService = app(ConversionFeeService::class);

            // Determine crypto currency and amount from withdrawal data
            $cryptoCurrency = $withdrawalData['crypto_currency'] ?? 'USDC';
            $fiatCurrency = $withdrawalData['fiat_currency'] ?? 'NGN';

            // Handle both crypto_amount and fiat amount scenarios
            if (isset($withdrawalData['crypto_amount'])) {
                // Direct crypto amount specified
                $cryptoAmount = $withdrawalData['crypto_amount'];

                // Calculate equivalent fiat amount directly
                $cryptoPriceInFiat = $this->priceFeedService->getPrice($cryptoCurrency, $fiatCurrency);
                if (!$cryptoPriceInFiat) {
                    throw new Exception("Unable to get price for {$cryptoCurrency} in {$fiatCurrency}");
                }
                $fiatAmount = $cryptoAmount * $cryptoPriceInFiat;

            } else {
                // Fiat amount specified, convert to crypto
                $fiatAmount = $withdrawalData['amount'] ?? $withdrawalData['fiat_amount'];
                if (!$fiatAmount) {
                    throw new Exception("Either crypto_amount or fiat amount must be specified");
                }

                // Convert fiat to crypto directly
                $cryptoPriceInFiat = $this->priceFeedService->getPrice($cryptoCurrency, $fiatCurrency);
                if (!$cryptoPriceInFiat) {
                    throw new Exception("Unable to get price for {$cryptoCurrency} in {$fiatCurrency}");
                }
                $cryptoAmount = $fiatAmount / $cryptoPriceInFiat;
            }

            // Validate withdrawal with calculated fiat amount
            $validation = $this->validateFiatTransaction($wallet, $fiatAmount, 'withdrawal');
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => $validation['message']
                ];
            }

            // Calculate fees
            $feeCalculation = $conversionFeeService->calculateCryptoToFiatFees(
                $cryptoAmount,
                $cryptoCurrency,
                $fiatCurrency
            );

            // Check if user has sufficient balance (including fees)
            $balances = $this->getWalletBalance($wallet);
            $availableCrypto = $balances['balances'][strtolower($cryptoCurrency)] ?? 0;

            if ($availableCrypto < $cryptoAmount) {
                return [
                    'success' => false,
                    'message' => "Insufficient {$cryptoCurrency} balance for withdrawal"
                ];
            }

            // Validate withdrawal limits
            $limitValidation = $conversionFeeService->validateWithdrawalLimits(
                $wallet->user_id,
                $feeCalculation['gross_usd_value']
            );

            if (!$limitValidation['valid']) {
                return [
                    'success' => false,
                    'message' => $limitValidation['message']
                ];
            }

            // Verify bank account
            $bankVerification = $this->paystackBankService->verifyBankAccount(
                $withdrawalData['account_number'],
                $withdrawalData['bank_code']
            );

            if (!$bankVerification['success']) {
                return [
                    'success' => false,
                    'message' => 'Bank account verification failed: ' . $bankVerification['message']
                ];
            }

            // Create transfer recipient
            $recipientData = $this->paystackBankService->createTransferRecipient([
                'account_name' => $bankVerification['data']['account_name'],
                'account_number' => $withdrawalData['account_number'],
                'bank_code' => $withdrawalData['bank_code'],
                'currency' => 'NGN'
            ]);

            if (!$recipientData['success']) {
                return [
                    'success' => false,
                    'message' => 'Failed to create transfer recipient: ' . $recipientData['message']
                ];
            }

            // Debit user's wallet first
            $debitResult = $this->debitWallet($wallet, $cryptoAmount, $cryptoCurrency);

            if (!$debitResult['success']) {
                throw new Exception('Failed to debit wallet: ' . $debitResult['message']);
            }

            // Create DeFi transaction
            $transaction = DefiTransaction::create([
                'user_id' => $wallet->user_id,
                'defi_wallet_id' => $wallet->id,
                'reference' => 'DEFI-WTH-' . strtoupper(Str::random(8)),
                'type' => 'withdraw_fiat',
                'status' => 'processing',
                'amount' => $cryptoAmount,
                'currency' => $cryptoCurrency,
                'amount_fiat' => $feeCalculation['net_fiat_value'],
                'fiat_currency' => $fiatCurrency,
                'exchange_rate' => $feeCalculation['exchange_rate_usd_to_fiat'],
                'rate_source' => 'coingecko',
                'rate_timestamp' => now(),
                'payment_method' => 'paystack',
                'bank_account_id' => $withdrawalData['account_number'],
                'description' => 'Fiat withdrawal from DeFi wallet',
                'requires_approval' => $feeCalculation['gross_usd_value'] > 5000, // Require approval for > $5K USD
                'stellar_transaction_hash' => $debitResult['stellar_hash'],
                'fees_usd' => $feeCalculation['fees']['total_fees_usd'],
                'fees_fiat' => $feeCalculation['fees']['total_fees_fiat'],
            ]);

            // Create fiat onramp record
            $onramp = FiatOnramp::create([
                'user_id' => $wallet->user_id,
                'defi_wallet_id' => $wallet->id,
                'defi_transaction_id' => $transaction->id,
                'provider' => 'paystack',
                'provider_reference' => 'PS-' . $transaction->reference,
                'type' => 'withdrawal',
                'status' => 'processing',
                'fiat_amount' => $feeCalculation['net_fiat_value'],
                'gross_fiat_amount' => $feeCalculation['gross_fiat_value'],
                'fiat_currency' => $fiatCurrency,
                'crypto_amount' => $cryptoAmount,
                'crypto_currency' => $cryptoCurrency,
                'exchange_rate' => $feeCalculation['exchange_rate_usd_to_fiat'],
                'bank_name' => $this->paystackBankService->getBankNameByCode($withdrawalData['bank_code']),
                'bank_code' => $withdrawalData['bank_code'],
                'account_number' => $withdrawalData['account_number'],
                'account_name' => $bankVerification['data']['account_name'],
                'account_verified' => true,
                'account_verified_at' => now(),
                'paystack_recipient_code' => $recipientData['data']['recipient_code'],
                'stellar_transaction_hash' => $debitResult['stellar_hash'],
                'fees_usd' => $feeCalculation['fees']['total_fees_usd'],
                'fees_fiat' => $feeCalculation['fees']['total_fees_fiat'],
                'conversion_fee_usd' => $feeCalculation['fees']['conversion_fee_usd'],
                'gas_fee_usd' => $feeCalculation['fees']['gas_fees_usd'],
                'spread_fee_usd' => $feeCalculation['fees']['spread_fees_usd'],
                'metadata' => json_encode([
                    'fee_calculation' => $feeCalculation,
                    'stellar_transaction' => $debitResult['stellar_transaction']
                ]),
            ]);

            DB::commit();

            // Process withdrawal asynchronously
            $this->processWithdrawalAsync($onramp);

            return [
                'success' => true,
                'message' => 'Withdrawal initiated successfully',
                'transaction' => $transaction,
                'onramp' => $onramp,
                'requires_approval' => $transaction->requires_approval,
                'fee_calculation' => $feeCalculation,
                'stellar_hash' => $debitResult['stellar_hash']
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to initiate fiat withdrawal', [
                'wallet_id' => $wallet->id,
                'withdrawal_data' => $withdrawalData,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to initiate withdrawal: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send crypto to another wallet
     */
    public function sendCrypto(DefiWallet $wallet, array $sendData): array
    {
        try {
            DB::beginTransaction();

            // Validate send amount
            $balances = $this->getWalletBalance($wallet);
            $availableAmount = $balances['balances'][$sendData['currency']] ?? 0;

            if ($availableAmount < $sendData['amount']) {
                return [
                    'success' => false,
                    'message' => 'Insufficient balance'
                ];
            }

            // Create transaction record
            $transaction = DefiTransaction::create([
                'user_id' => $wallet->user_id,
                'defi_wallet_id' => $wallet->id,
                'reference' => 'DEFI-SEND-' . strtoupper(Str::random(8)),
                'type' => 'send_crypto',
                'status' => 'pending',
                'amount' => $sendData['amount'],
                'currency' => $sendData['currency'],
                'to_address' => $sendData['destination_address'],
                'destination_tag' => $sendData['memo'] ?? null,
                'description' => $sendData['description'] ?? 'Crypto transfer',
                'network_fee' => $this->calculateNetworkFee($sendData['currency']),
                'platform_fee' => 0, // No platform fee for crypto sends
            ]);

            // Send via Stellar if it's a Stellar asset
            if (in_array($sendData['currency'], ['XLM', 'USDC']) && $wallet->stellarWallet) {
                $stellarResult = $this->stellarWalletService->sendPayment(
                    $wallet->stellarWallet,
                    $sendData['destination_address'],
                    $sendData['amount'],
                    $sendData['currency'],
                    null,
                    $sendData['memo'] ?? null
                );

                if ($stellarResult['success']) {
                    $transaction->update([
                        'status' => 'completed',
                        'transaction_hash' => $stellarResult['transaction_hash'] ?? null,
                        'completed_at' => now(),
                    ]);

                    // Update wallet balance
                    $this->updateWalletBalance($wallet, $sendData['currency'], -$sendData['amount']);
                } else {
                    $transaction->markAsFailed($stellarResult['message'] ?? 'Stellar transaction failed');
                }
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Crypto sent successfully',
                'transaction' => $transaction
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to send crypto', [
                'wallet_id' => $wallet->id,
                'send_data' => $sendData,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to send crypto: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get transaction history for a wallet
     */
    public function getTransactionHistory(DefiWallet $wallet, array $filters = []): array
    {
        try {
            $query = $wallet->transactions()->with(['user', 'fiatOnramp']);

            // Apply filters
            if (!empty($filters['type'])) {
                $query->where('type', $filters['type']);
            }

            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (!empty($filters['currency'])) {
                $query->where('currency', $filters['currency']);
            }

            if (!empty($filters['date_from'])) {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            }

            $transactions = $query->orderBy('created_at', 'desc')
                ->paginate($filters['per_page'] ?? 20);

            return [
                'success' => true,
                'transactions' => $transactions
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get transaction history', [
                'wallet_id' => $wallet->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to retrieve transaction history'
            ];
        }
    }

    /**
     * Calculate withdrawal fee using admin settings
     */
    protected function calculateWithdrawalFee(float $amount): float
    {
        // Get withdrawal fee percentage from settings
        $percentage = (float) setting('defi_withdrawal_fee_percentage', 0.5);
        return $amount * $percentage / 100;
    }

    /**
     * Calculate network fee for crypto transactions
     */
    protected function calculateNetworkFee(string $currency): float
    {
        // Get network fees from settings
        $fees = [
            'XLM' => (float) setting('crypto_network_fee_xlm', 0.00001),
            'USDC' => (float) setting('crypto_network_fee_usdc', 0.00001),
        ];

        return $fees[$currency] ?? 0;
    }

    /**
     * Update wallet balance for a specific currency
     */
    protected function updateWalletBalance(DefiWallet $wallet, string $currency, float $amount): void
    {
        $field = match ($currency) {
            'XLM' => 'balance_xlm',
            'USD', 'USDC' => 'balance_usd',
            'NGN' => 'balance_ngn',
            default => null
        };

        if ($field) {
            $wallet->increment($field, $amount);
            $wallet->updateLastActivity();
        }
    }

    /**
     * Generate or regenerate addresses for a DeFi wallet.
     */
    public function generateAddressesForWallet(DefiWallet $wallet): array
    {
        try {
            $addresses = $this->custodialAddressService->generateAddressesForWallet($wallet);
            $wallet->updateAddresses($addresses);

            Log::info('Generated addresses for DeFi wallet', [
                'user_id' => $wallet->user_id,
                'wallet_id' => $wallet->id,
                'networks' => $wallet->getSupportedNetworks(),
            ]);

            return $addresses;

        } catch (\Exception $e) {
            Log::error('Failed to generate addresses for DeFi wallet', [
                'user_id' => $wallet->user_id,
                'wallet_id' => $wallet->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get wallet addresses with network information.
     * Filters addresses based on admin-enabled networks.
     */
    public function getWalletAddressesWithNetworkInfo(DefiWallet $wallet, bool $includeTestnet = false): array
    {
        $addresses = $wallet->getAllAddresses($includeTestnet);
        $supportedNetworks = $this->custodialAddressService->getSupportedNetworks();
        $enabledNetworks = $this->settingsService->getEnabledNetworks();

        $result = [];
        foreach ($addresses as $network => $address) {
            $networkKey = str_replace(['_testnet', '_address'], '', $network);
            $isTestnet = str_contains($network, 'testnet');

            // Only include networks that are both supported and enabled by admin
            if (isset($supportedNetworks[$networkKey]) && in_array($networkKey, $enabledNetworks)) {
                $result[] = [
                    'network' => $networkKey,
                    'network_name' => $supportedNetworks[$networkKey]['name'],
                    'symbol' => $supportedNetworks[$networkKey]['symbol'],
                    'address' => $address,
                    'is_testnet' => $isTestnet,
                    'is_primary' => $address === $wallet->getPrimaryAddress(),
                ];
            }
        }

        return $result;
    }

    /**
     * Validate if a wallet has all required addresses.
     */
    public function validateWalletAddresses(DefiWallet $wallet): array
    {
        $issues = [];
        $supportedNetworks = $this->custodialAddressService->getSupportedNetworks();

        foreach ($supportedNetworks as $network => $info) {
            if (!$wallet->hasAddressForNetwork($network)) {
                $issues[] = "Missing {$info['name']} address";
            }
        }

        if (!$wallet->hasAddresses()) {
            $issues[] = "Addresses have not been generated";
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'addresses_count' => count($wallet->getAllAddresses()),
            'networks_supported' => count($supportedNetworks),
        ];
    }

    /**
     * Add trustline for custom asset
     */
    public function addTrustline(DefiWallet $wallet, string $assetCode, string $assetIssuer, ?string $limit = null): array
    {
        try {
            if (!$wallet->stellarWallet) {
                return [
                    'success' => false,
                    'message' => 'No Stellar wallet associated'
                ];
            }

            $result = $this->stellarWalletService->createTrustline(
                $wallet->stellarWallet,
                $assetCode,
                $assetIssuer,
                $limit
            );

            if ($result['success']) {
                // Update custom asset balances
                $customAssets = $wallet->custom_asset_balances ?? [];
                $customAssets[$assetCode] = 0; // Initialize with 0 balance
                $wallet->update(['custom_asset_balances' => $customAssets]);

                // TODO: Add audit service injection
                // $this->auditService->logTrustlineCreation(
                //     $wallet->user,
                //     $wallet,
                //     $assetCode,
                //     $assetIssuer,
                //     $limit
                // );
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('Failed to add trustline', [
                'wallet_id' => $wallet->id,
                'asset_code' => $assetCode,
                'asset_issuer' => $assetIssuer,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to add trustline: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Remove trustline for custom asset
     */
    public function removeTrustline(DefiWallet $wallet, string $assetCode, string $assetIssuer): array
    {
        try {
            if (!$wallet->stellarWallet) {
                return [
                    'success' => false,
                    'message' => 'No Stellar wallet associated'
                ];
            }

            // Check if user has balance in this asset
            $customAssets = $wallet->custom_asset_balances ?? [];
            if (isset($customAssets[$assetCode]) && $customAssets[$assetCode] > 0) {
                return [
                    'success' => false,
                    'message' => 'Cannot remove trustline with non-zero balance'
                ];
            }

            $result = $this->stellarWalletService->createTrustline(
                $wallet->stellarWallet,
                $assetCode,
                $assetIssuer,
                "0" // Set limit to 0 to remove trustline
            );

            if ($result['success']) {
                // Remove from custom asset balances
                $customAssets = $wallet->custom_asset_balances ?? [];
                unset($customAssets[$assetCode]);
                $wallet->update(['custom_asset_balances' => $customAssets]);

                // TODO: Add audit service injection
                // $this->auditService->logTrustlineRemoval(
                //     $wallet->user,
                //     $wallet,
                //     $assetCode,
                //     $assetIssuer
                // );
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('Failed to remove trustline', [
                'wallet_id' => $wallet->id,
                'asset_code' => $assetCode,
                'asset_issuer' => $assetIssuer,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to remove trustline: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get wallet trustlines
     */
    public function getWalletTrustlines(DefiWallet $wallet): array
    {
        try {
            if (!$wallet->stellarWallet) {
                return [
                    'success' => false,
                    'message' => 'No Stellar wallet associated',
                    'trustlines' => []
                ];
            }

            $result = $this->stellarService->getAccountTrustlines($wallet->stellarWallet->public_key);

            return $result;

        } catch (\Exception $e) {
            Log::error('Failed to get wallet trustlines', [
                'wallet_id' => $wallet->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to get trustlines: ' . $e->getMessage(),
                'trustlines' => []
            ];
        }
    }

    /**
     * Debit wallet and transfer crypto to treasury
     */
    public function debitWallet(DefiWallet $wallet, float $amount, string $currency): array
    {
        try {
            // Lock wallet for update to prevent race conditions
            $wallet = DefiWallet::where('id', $wallet->id)->lockForUpdate()->first();

            // Get current balance
            $balanceField = 'balance_' . strtolower($currency);
            $currentBalance = $wallet->$balanceField;

            if ($currentBalance < $amount) {
                return [
                    'success' => false,
                    'message' => "Insufficient {$currency} balance"
                ];
            }

            // Get user's Stellar wallet secret key
            $stellarWallet = $wallet->stellarWallet;
            if (!$stellarWallet || !$stellarWallet->secret_key) {
                return [
                    'success' => false,
                    'message' => 'Stellar wallet not found or secret key missing'
                ];
            }

            // Transfer crypto to treasury
            $stellarTransaction = $this->stellarService->transferToTreasury(
                $stellarWallet->secret_key,
                $amount,
                $currency
            );

            if (!$stellarTransaction['success']) {
                return [
                    'success' => false,
                    'message' => 'Failed to transfer crypto to treasury'
                ];
            }

            // Debit wallet balance
            $wallet->decrement($balanceField, $amount);

            // Create debit transaction record
            $debitTransaction = DefiTransaction::create([
                'user_id' => $wallet->user_id,
                'defi_wallet_id' => $wallet->id,
                'reference' => 'DEBIT-' . strtoupper(Str::random(8)),
                'type' => 'debit_crypto',
                'status' => 'completed',
                'amount' => $amount,
                'currency' => $currency,
                'description' => "Wallet debit for {$currency} conversion",
                'stellar_transaction_hash' => $stellarTransaction['transaction_hash'],
            ]);

            return [
                'success' => true,
                'stellar_hash' => $stellarTransaction['transaction_hash'],
                'stellar_transaction' => $stellarTransaction,
                'debit_transaction_id' => $debitTransaction->id,
                'new_balance' => $wallet->fresh()->$balanceField
            ];

        } catch (Exception $e) {
            Log::error('Failed to debit wallet', [
                'wallet_id' => $wallet->id,
                'amount' => $amount,
                'currency' => $currency,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to debit wallet: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process withdrawal asynchronously
     */
    protected function processWithdrawalAsync(FiatOnramp $onramp): void
    {
        // This would typically dispatch a job to process the withdrawal
        // For now, we'll just log that it should be processed
        Log::info('Withdrawal queued for processing', [
            'onramp_id' => $onramp->id,
            'amount' => $onramp->fiat_amount,
            'currency' => $onramp->fiat_currency,
            'provider' => $onramp->provider
        ]);

        // TODO: Dispatch job to process withdrawal via Paystack/MoneyGram
        // dispatch(new ProcessWithdrawalJob($onramp));
    }
}
