<?php

namespace App\Services;

use App\Models\DefiWallet;
use App\Models\DefiTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DefiSmartContractService
{
    protected $stellarService;
    protected $auditService;

    public function __construct(
        StellarService $stellarService,
        DefiWalletAuditService $auditService
    ) {
        $this->stellarService = $stellarService;
        $this->auditService = $auditService;
    }

    protected function unsupportedLegacyInsuranceFlow(string $operation, DefiWallet $wallet): array
    {
        Log::warning('Blocked deprecated wallet-driven smart contract flow', [
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'operation' => $operation,
        ]);

        return [
            'success' => false,
            'operation' => $operation,
            'message' => 'This wallet-driven single-contract insurance flow is deprecated and incompatible with the live modular Soroban suite. Use the current policy, claim, payment, and oracle integrations instead.',
        ];
    }

    /**
     * Initialize smart contract integration for DeFi wallet
     */
    public function initializeSmartContractIntegration(DefiWallet $wallet): array
    {
        return $this->unsupportedLegacyInsuranceFlow('initialize_smart_contract_integration', $wallet);
    }

    /**
     * Create insurance policy using smart contract
     */
    public function createInsurancePolicy(DefiWallet $wallet, array $policyData): array
    {
        return $this->unsupportedLegacyInsuranceFlow('create_insurance_policy', $wallet);
    }

    /**
     * Process insurance claim using smart contract
     */
    public function processInsuranceClaim(DefiWallet $wallet, array $claimData): array
    {
        return $this->unsupportedLegacyInsuranceFlow('process_insurance_claim', $wallet);
    }

    /**
     * Initialize contract with wallet
     */
    protected function initializeContract(DefiWallet $wallet, string $contractId): array
    {
        try {
            if (!$wallet->stellarWallet) {
                throw new \Exception('No Stellar wallet associated');
            }

            // Initialize the contract with the admin account
            $result = $this->stellarService->invokeContract(
                $contractId,
                'initialize',
                ['admin' => config('stellar.insurance.master_account_id')],
                config('stellar.insurance.master_account_secret')
            );

            return [
                'success' => $result['success'] ?? false,
                'transaction_hash' => $result['transaction_hash'] ?? null,
                'message' => $result['message'] ?? 'Contract initialized'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to initialize contract: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Invoke smart contract method
     */
    protected function invokeContract(DefiWallet $wallet, string $contractId, string $method, array $params): array
    {
        try {
            if (!$wallet->stellarWallet) {
                throw new \Exception('No Stellar wallet associated');
            }

            $result = $this->stellarService->invokeContract(
                $contractId,
                $method,
                $params,
                $wallet->stellarWallet->private_key
            );

            return [
                'success' => $result['success'] ?? false,
                'transaction_hash' => $result['transaction_hash'] ?? null,
                'policy_id' => $result['policy_id'] ?? null,
                'claim_approved' => $result['claim_approved'] ?? false,
                'payout_amount' => $result['payout_amount'] ?? 0,
                'message' => $result['message'] ?? 'Contract invoked successfully'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to invoke contract: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Deduct premium from wallet
     */
    protected function deductPremium(DefiWallet $wallet, float $amount): void
    {
        $wallet->decrement('balance_xlm', $amount);
        
        $this->auditService->logBalanceUpdate(
            $wallet->user,
            $wallet,
            'XLM',
            $wallet->balance_xlm + $amount,
            $wallet->balance_xlm,
            'Premium payment deduction'
        );
    }

    /**
     * Credit claim payout to wallet
     */
    protected function creditClaimPayout(DefiWallet $wallet, float $amount): void
    {
        $wallet->increment('balance_xlm', $amount);
        
        $this->auditService->logBalanceUpdate(
            $wallet->user,
            $wallet,
            'XLM',
            $wallet->balance_xlm - $amount,
            $wallet->balance_xlm,
            'Insurance claim payout'
        );
    }
}
