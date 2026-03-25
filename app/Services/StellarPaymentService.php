<?php

namespace App\Services;

use App\Models\User;
use App\Models\InsurancePolicy;
use App\Models\StellarWallet;
use App\Models\StellarTransaction;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * Stellar Payment Service for insurance premium payments
 * 
 * This service handles premium payments using Stellar Lumens (XLM)
 * and other Stellar assets, integrating with the existing payment system.
 */
class StellarPaymentService
{
    protected $stellarService;
    protected $stellarWalletService;
    protected $stellarSmartContractService;

    public function __construct(
        StellarService $stellarService,
        StellarWalletService $stellarWalletService,
        StellarSmartContractService $stellarSmartContractService
    ) {
        $this->stellarService = $stellarService;
        $this->stellarWalletService = $stellarWalletService;
        $this->stellarSmartContractService = $stellarSmartContractService;
    }

    /**
     * Process premium payment using Stellar
     * 
     * @param InsurancePolicy $policy The insurance policy
     * @param User $user The user making the payment
     * @param string $amount Payment amount in XLM
     * @param string $assetCode Asset code (default: XLM)
     * @param string|null $assetIssuer Asset issuer (for non-native assets)
     * @return array Payment result
     */
    public function processPremiumPayment(
        InsurancePolicy $policy,
        User $user,
        string $amount,
        string $assetCode = 'XLM',
        ?string $assetIssuer = null
    ): array {
        try {
            DB::beginTransaction();

            // Ensure user has Stellar enabled and wallet
            if (!$user->hasStellarEnabled()) {
                throw new Exception('User does not have Stellar enabled');
            }

            $wallet = $user->getOrCreateStellarWallet();
            if (!$wallet) {
                throw new Exception('Failed to create or retrieve Stellar wallet');
            }

            // Validate wallet has sufficient balance
            $balances = $this->stellarWalletService->getWalletBalance($wallet);
            $hasBalance = $this->validateSufficientBalance($balances, $amount, $assetCode, $assetIssuer);
            
            if (!$hasBalance) {
                throw new Exception('Insufficient balance for payment');
            }

            // Get insurance company's Stellar account
            $insuranceAccount = config('stellar.insurance.master_account_id');
            if (!$insuranceAccount) {
                throw new Exception('Insurance company Stellar account not configured');
            }

            // Create transaction record first
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'type' => 'policy_payment',
                'status' => 'pending',
                'payment_method' => 'stellar',
                'description' => 'Premium payment for policy ' . $policy->policy_number,
                'reference' => 'STELLAR-' . time() . '-' . rand(1000, 9999),
                'metadata' => [
                    'policy_id' => $policy->id,
                    'asset_code' => $assetCode,
                    'asset_issuer' => $assetIssuer,
                    'stellar_wallet_id' => $wallet->id,
                ]
            ]);

            // Send payment via Stellar
            $stellarResult = $this->stellarWalletService->sendPayment(
                $wallet,
                $insuranceAccount,
                $amount,
                $assetCode,
                $assetIssuer,
                'Premium payment for policy ' . $policy->policy_number
            );

            // Create Stellar transaction record
            $stellarTransaction = StellarTransaction::create([
                'stellar_wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'transaction_hash' => $stellarResult['transaction_hash'],
                'operation_type' => 'payment',
                'source_account' => $wallet->public_key,
                'destination_account' => $insuranceAccount,
                'asset_code' => $assetCode,
                'asset_issuer' => $assetIssuer,
                'amount' => $amount,
                'fee' => $stellarResult['fee'] ?? '0.00001',
                'memo' => 'Premium payment for policy ' . $policy->policy_number,
                'memo_type' => 'text',
                'status' => 'success',
                'successful' => true,
                'insurance_policy_id' => $policy->id,
                'metadata' => [
                    'transaction_id' => $transaction->id,
                    'policy_number' => $policy->policy_number,
                ]
            ]);

            // Process payment through smart contract if available
            if ($policy->stellar_contract_id) {
                $contractResult = $this->stellarSmartContractService->processPremiumPayment(
                    $policy->stellar_policy_id,
                    $wallet->public_key,
                    $amount
                );

                $stellarTransaction->addMetadata('contract_result', $contractResult);
            }

            // Update transaction status
            $transaction->update([
                'status' => 'completed',
                'metadata' => array_merge($transaction->metadata, [
                    'stellar_transaction_hash' => $stellarResult['transaction_hash'],
                    'stellar_transaction_id' => $stellarTransaction->id,
                ])
            ]);

            // Update policy payment status
            $this->updatePolicyPaymentStatus($policy, $amount);

            // Update policy with Stellar transaction info if not already set
            if (!$policy->stellar_transaction_hash) {
                $policy->update([
                    'stellar_wallet_id' => $wallet->id,
                    'stellar_transaction_hash' => $stellarResult['transaction_hash'],
                ]);
            }

            DB::commit();

            Log::channel('stellar-transactions')->info('Premium payment processed successfully', [
                'user_id' => $user->id,
                'policy_id' => $policy->id,
                'amount' => $amount,
                'asset_code' => $assetCode,
                'transaction_hash' => $stellarResult['transaction_hash'],
            ]);

            return [
                'success' => true,
                'transaction_id' => $transaction->id,
                'stellar_transaction_id' => $stellarTransaction->id,
                'stellar_transaction_hash' => $stellarResult['transaction_hash'],
                'amount' => $amount,
                'asset_code' => $assetCode,
                'policy_id' => $policy->id,
            ];

        } catch (Exception $e) {
            DB::rollBack();

            Log::channel('stellar-transactions')->error('Premium payment failed', [
                'user_id' => $user->id,
                'policy_id' => $policy->id,
                'amount' => $amount,
                'asset_code' => $assetCode,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Validate if wallet has sufficient balance for payment
     * 
     * @param array $balances Wallet balances
     * @param string $amount Required amount
     * @param string $assetCode Asset code
     * @param string|null $assetIssuer Asset issuer
     * @return bool
     */
    protected function validateSufficientBalance(
        array $balances,
        string $amount,
        string $assetCode,
        ?string $assetIssuer = null
    ): bool {
        foreach ($balances as $balance) {
            if ($balance['asset_code'] === $assetCode) {
                // For native XLM, no issuer check needed
                if ($assetCode === 'XLM' || $balance['asset_issuer'] === $assetIssuer) {
                    return floatval($balance['balance']) >= floatval($amount);
                }
            }
        }

        return false;
    }

    /**
     * Update policy payment status based on payment
     * 
     * @param InsurancePolicy $policy The policy to update
     * @param string $amount Payment amount
     * @return void
     */
    protected function updatePolicyPaymentStatus(InsurancePolicy $policy, string $amount): void
    {
        $currentPaid = $policy->amount_paid ?? 0;
        $newPaid = $currentPaid + floatval($amount);
        
        $policy->amount_paid = $newPaid;
        
        if ($newPaid >= $policy->premium_amount) {
            $policy->payment_status = 'paid';
            $policy->status = 'active';
        } elseif ($newPaid > 0) {
            $policy->payment_status = 'partial';
        }
        
        $policy->save();
    }

    /**
     * Get payment quote in XLM for a given USD amount
     * 
     * @param string $usdAmount Amount in USD
     * @return array Quote information
     */
    public function getPaymentQuote(string $usdAmount): array
    {
        try {
            // TODO: Implement real-time XLM/USD exchange rate
            // For now, using a placeholder rate
            $xlmUsdRate = 0.12; // 1 XLM = $0.12 USD (placeholder)
            $xlmAmount = floatval($usdAmount) / $xlmUsdRate;
            
            return [
                'usd_amount' => $usdAmount,
                'xlm_amount' => number_format($xlmAmount, 7),
                'exchange_rate' => $xlmUsdRate,
                'fee_xlm' => '0.00001', // Base Stellar fee
                'total_xlm' => number_format($xlmAmount + 0.00001, 7),
                'expires_at' => now()->addMinutes(5), // Quote valid for 5 minutes
            ];
        } catch (Exception $e) {
            Log::channel('stellar')->error('Failed to get payment quote', [
                'usd_amount' => $usdAmount,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Create a trustline for insurance token
     * 
     * @param User $user The user
     * @return array Trustline creation result
     */
    public function createInsuranceTokenTrustline(User $user): array
    {
        try {
            $wallet = $user->getOrCreateStellarWallet();
            if (!$wallet) {
                throw new Exception('User does not have a Stellar wallet');
            }

            $tokenCode = config('stellar.insurance.insurance_token.code');
            $tokenIssuer = config('stellar.insurance.insurance_token.issuer');

            if (!$tokenCode || !$tokenIssuer) {
                throw new Exception('Insurance token not configured');
            }

            $result = $this->stellarWalletService->createTrustline(
                $wallet,
                $tokenCode,
                $tokenIssuer
            );

            Log::channel('stellar-transactions')->info('Insurance token trustline created', [
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'token_code' => $tokenCode,
                'token_issuer' => $tokenIssuer,
                'transaction_hash' => $result['transaction_hash'] ?? null,
            ]);

            return $result;

        } catch (Exception $e) {
            Log::channel('stellar-transactions')->error('Failed to create insurance token trustline', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get supported payment assets
     * 
     * @return array List of supported assets
     */
    public function getSupportedAssets(): array
    {
        return [
            [
                'code' => 'XLM',
                'name' => 'Stellar Lumens',
                'issuer' => null,
                'type' => 'native',
                'decimals' => 7,
            ],
            [
                'code' => config('stellar.insurance.insurance_token.code', 'INSURE'),
                'name' => 'Insurance Token',
                'issuer' => config('stellar.insurance.insurance_token.issuer'),
                'type' => 'custom',
                'decimals' => 7,
            ],
        ];
    }
}
