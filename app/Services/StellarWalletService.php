<?php

namespace App\Services;

use App\Models\User;
use App\Models\StellarWallet;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Exception;

/**
 * Stellar Wallet Service for managing user wallets
 * 
 * This service handles wallet creation, management, and operations
 * for users on the Stellar network.
 */
class StellarWalletService
{
    protected $stellarService;
    protected $encryptKeys;

    public function __construct(StellarService $stellarService)
    {
        $this->stellarService = $stellarService;
        $this->encryptKeys = Config::get('stellar.security.encrypt_private_keys', true);
    }

    /**
     * Create a new Stellar wallet for a user
     * 
     * @param User $user The user to create a wallet for
     * @param bool $fundTestnet Whether to fund the account on testnet
     * @return StellarWallet The created wallet
     */
    public function createWallet(User $user, bool $fundTestnet = true): StellarWallet
    {
        try {
            // Check if user already has a wallet
            $existingWallet = StellarWallet::where('user_id', $user->id)->first();
            if ($existingWallet) {
                throw new Exception('User already has a Stellar wallet');
            }

            // Create new Stellar account
            $accountData = $this->stellarService->createAccount();

            // Encrypt secret key if required
            $secretKey = $this->encryptKeys 
                ? Crypt::encryptString($accountData['secret_key'])
                : $accountData['secret_key'];

            // Create wallet record
            $wallet = StellarWallet::create([
                'user_id' => $user->id,
                'public_key' => $accountData['public_key'],
                'secret_key' => $secretKey,
                'is_encrypted' => $this->encryptKeys,
                'status' => 'pending_activation',
                'network' => Config::get('stellar.default_network'),
                'created_at' => now(),
            ]);

            // Fund testnet account if requested and on testnet
            if ($fundTestnet && Config::get('stellar.default_network') === 'testnet') {
                $funded = $this->stellarService->fundTestnetAccount($accountData['public_key']);
                if ($funded) {
                    $wallet->update(['status' => 'active']);
                }
            }

            Log::channel('stellar')->info('Stellar wallet created', [
                'user_id' => $user->id,
                'public_key' => $accountData['public_key'],
                'network' => Config::get('stellar.default_network'),
                'funded' => $fundTestnet && Config::get('stellar.default_network') === 'testnet'
            ]);

            return $wallet;
        } catch (Exception $e) {
            Log::channel('stellar')->error('Failed to create Stellar wallet', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get or create a wallet for a user
     * 
     * @param User $user The user
     * @return StellarWallet The user's wallet
     */
    public function getOrCreateWallet(User $user): StellarWallet
    {
        $wallet = StellarWallet::where('user_id', $user->id)->first();
        
        if (!$wallet && Config::get('stellar.wallets.auto_create', true)) {
            $wallet = $this->createWallet($user);
        }

        if (!$wallet) {
            throw new Exception('No wallet found for user and auto-creation is disabled');
        }

        return $wallet;
    }

    /**
     * Get wallet balance
     * 
     * @param StellarWallet $wallet The wallet to check
     * @return array Balance information
     */
    public function getWalletBalance(StellarWallet $wallet): array
    {
        try {
            $accountInfo = $this->stellarService->getAccountInfo($wallet->public_key);
            
            $balances = [];
            foreach ($accountInfo['balances'] as $balance) {
                $balances[] = [
                    'asset_type' => $balance['asset_type'] ?? 'native',
                    'asset_code' => $balance['asset_code'] ?? 'XLM',
                    'asset_issuer' => $balance['asset_issuer'] ?? null,
                    'balance' => $balance['balance'] ?? '0',
                    'limit' => $balance['limit'] ?? null,
                ];
            }

            Log::channel('stellar')->debug('Wallet balance retrieved', [
                'wallet_id' => $wallet->id,
                'public_key' => $wallet->public_key,
                'balances_count' => count($balances)
            ]);

            return $balances;
        } catch (Exception $e) {
            Log::channel('stellar')->error('Failed to get wallet balance', [
                'wallet_id' => $wallet->id,
                'public_key' => $wallet->public_key,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Send payment from wallet
     * 
     * @param StellarWallet $wallet Source wallet
     * @param string $destinationId Destination account ID
     * @param string $amount Amount to send
     * @param string $assetCode Asset code (default: XLM)
     * @param string|null $assetIssuer Asset issuer (for non-native assets)
     * @param string|null $memo Transaction memo
     * @return array Transaction result
     */
    public function sendPayment(
        StellarWallet $wallet,
        string $destinationId,
        string $amount,
        string $assetCode = 'XLM',
        ?string $assetIssuer = null,
        ?string $memo = null
    ): array {
        try {
            // Decrypt secret key if encrypted
            $secretKey = $wallet->is_encrypted 
                ? Crypt::decryptString($wallet->secret_key)
                : $wallet->secret_key;

            // Send payment
            $result = $this->stellarService->sendPayment(
                $secretKey,
                $destinationId,
                $amount,
                $assetCode,
                $assetIssuer,
                $memo
            );

            Log::channel('stellar-transactions')->info('Payment sent from wallet', [
                'wallet_id' => $wallet->id,
                'source_account' => $wallet->public_key,
                'destination_account' => $destinationId,
                'amount' => $amount,
                'asset_code' => $assetCode,
                'transaction_hash' => $result['transaction_hash'] ?? null
            ]);

            return $result;
        } catch (Exception $e) {
            Log::channel('stellar-transactions')->error('Failed to send payment from wallet', [
                'wallet_id' => $wallet->id,
                'destination' => $destinationId,
                'amount' => $amount,
                'asset_code' => $assetCode,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create trustline for custom asset
     * 
     * @param StellarWallet $wallet The wallet to create trustline for
     * @param string $assetCode Asset code
     * @param string $assetIssuer Asset issuer account ID
     * @param string|null $limit Trust limit (optional)
     * @return array Transaction result
     */
    public function createTrustline(
        StellarWallet $wallet,
        string $assetCode,
        string $assetIssuer,
        ?string $limit = null
    ): array {
        try {
            // Decrypt secret key if encrypted
            $secretKey = $wallet->is_encrypted 
                ? Crypt::decryptString($wallet->secret_key)
                : $wallet->secret_key;

            // Create trustline
            $result = $this->stellarService->createTrustline(
                $secretKey,
                $assetCode,
                $assetIssuer,
                $limit
            );

            Log::channel('stellar-transactions')->info('Trustline created for wallet', [
                'wallet_id' => $wallet->id,
                'account_id' => $wallet->public_key,
                'asset_code' => $assetCode,
                'asset_issuer' => $assetIssuer,
                'limit' => $limit,
                'transaction_hash' => $result['transaction_hash'] ?? null
            ]);

            return $result;
        } catch (Exception $e) {
            Log::channel('stellar-transactions')->error('Failed to create trustline for wallet', [
                'wallet_id' => $wallet->id,
                'asset_code' => $assetCode,
                'asset_issuer' => $assetIssuer,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get wallet transaction history
     * 
     * @param StellarWallet $wallet The wallet to get history for
     * @param int $limit Number of transactions to retrieve
     * @return array Transaction history
     */
    public function getTransactionHistory(StellarWallet $wallet, int $limit = 50): array
    {
        try {
            // TODO: Implement with Stellar SDK
            /*
            $operations = $this->stellarService->getAccountOperations(
                $wallet->public_key,
                $limit
            );
            */

            $transactions = [
                // Placeholder transaction data
                [
                    'id' => 'placeholder_id',
                    'type' => 'payment',
                    'amount' => '10.0000000',
                    'asset_code' => 'XLM',
                    'from' => 'PLACEHOLDER_FROM',
                    'to' => 'PLACEHOLDER_TO',
                    'created_at' => now()->subHours(1),
                    'transaction_hash' => 'placeholder_hash',
                ]
            ];

            Log::channel('stellar')->debug('Transaction history retrieved', [
                'wallet_id' => $wallet->id,
                'public_key' => $wallet->public_key,
                'transactions_count' => count($transactions)
            ]);

            return $transactions;
        } catch (Exception $e) {
            Log::channel('stellar')->error('Failed to get transaction history', [
                'wallet_id' => $wallet->id,
                'public_key' => $wallet->public_key,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Activate a wallet by funding it
     *
     * @param StellarWallet $wallet The wallet to activate
     * @param string $fundingAmount Amount to fund (XLM)
     * @return bool Success status
     */
    public function activateWallet(StellarWallet $wallet, string $fundingAmount = null): bool
    {
        try {
            $fundingAmount = $fundingAmount ?? Config::get('stellar.wallets.funding_amount', '2');
            $network = Config::get('stellar.default_network');

            if ($network === 'testnet') {
                // Use Friendbot for testnet
                $funded = $this->stellarService->fundTestnetAccount($wallet->public_key);
            } elseif ($network === 'mainnet') {
                // For mainnet, fund from treasury hot wallet
                $funded = $this->fundMainnetAccount($wallet->public_key, $fundingAmount);
            } else {
                throw new Exception("Unsupported network: {$network}");
            }

            if ($funded) {
                $wallet->update(['status' => 'active']);

                Log::channel('stellar')->info('Wallet activated', [
                    'wallet_id' => $wallet->id,
                    'public_key' => $wallet->public_key,
                    'funding_amount' => $fundingAmount,
                    'network' => $network
                ]);
            }

            return $funded;
        } catch (Exception $e) {
            Log::channel('stellar')->error('Failed to activate wallet', [
                'wallet_id' => $wallet->id,
                'public_key' => $wallet->public_key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Fund a mainnet account from treasury hot wallet
     *
     * @param string $accountId The account to fund
     * @param string $amount Amount in XLM
     * @return bool Success status
     */
    protected function fundMainnetAccount(string $accountId, string $amount): bool
    {
        try {
            $hotWalletSecret = Config::get('stellar.treasury.hot_wallet_secret');
            $hotWalletPublic = Config::get('stellar.treasury.hot_wallet_public');

            if (!$hotWalletSecret || !$hotWalletPublic) {
                throw new Exception('Treasury hot wallet not configured');
            }

            // Check hot wallet balance first
            $hotWalletBalance = $this->stellarService->getAccountBalance($hotWalletPublic);
            if ($hotWalletBalance < floatval($amount) + 1) { // +1 for fees and reserve
                throw new Exception('Insufficient hot wallet balance');
            }

            // Create and fund the account
            $result = $this->stellarService->createAndFundAccount(
                $hotWalletSecret,
                $accountId,
                $amount
            );

            if ($result) {
                Log::channel('stellar')->info('Mainnet account funded', [
                    'account_id' => $accountId,
                    'amount' => $amount,
                    'hot_wallet' => $hotWalletPublic
                ]);
            }

            return $result;
        } catch (Exception $e) {
            Log::channel('stellar')->error('Failed to fund mainnet account', [
                'account_id' => $accountId,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Validate wallet status
     * 
     * @param StellarWallet $wallet The wallet to validate
     * @return array Validation result
     */
    public function validateWallet(StellarWallet $wallet): array
    {
        try {
            $accountInfo = $this->stellarService->getAccountInfo($wallet->public_key);
            
            $isActive = !empty($accountInfo['balances']);
            $hasMinimumBalance = false;
            
            foreach ($accountInfo['balances'] as $balance) {
                if ($balance['asset_type'] === 'native' && 
                    floatval($balance['balance']) >= Config::get('stellar.wallets.minimum_balance', 1)) {
                    $hasMinimumBalance = true;
                    break;
                }
            }

            $status = $isActive && $hasMinimumBalance ? 'active' : 'inactive';
            
            // Update wallet status if changed
            if ($wallet->status !== $status) {
                $wallet->update(['status' => $status]);
            }

            return [
                'is_active' => $isActive,
                'has_minimum_balance' => $hasMinimumBalance,
                'status' => $status,
                'balances' => $accountInfo['balances'],
            ];
        } catch (Exception $e) {
            Log::channel('stellar')->error('Failed to validate wallet', [
                'wallet_id' => $wallet->id,
                'public_key' => $wallet->public_key,
                'error' => $e->getMessage()
            ]);
            
            return [
                'is_active' => false,
                'has_minimum_balance' => false,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }
}
