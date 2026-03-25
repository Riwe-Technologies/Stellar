<?php

namespace App\Services;

use App\Models\InsurancePolicy;
use App\Models\User;
use App\Models\Farm;
use App\Models\StellarSmartContract;
use App\Models\StellarTransaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * Stellar Policy Service for managing insurance policies on blockchain
 * 
 * This service handles the creation, activation, and management of
 * insurance policies using Stellar smart contracts.
 */
class StellarPolicyService
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
     * Create a new insurance policy on the Stellar blockchain
     * 
     * @param array $policyData Policy data
     * @return array Policy creation result
     */
    public function createPolicyOnBlockchain(array $policyData): array
    {
        try {
            DB::beginTransaction();

            // Validate required data
            $this->validatePolicyData($policyData);

            // Get or create user's Stellar wallet
            $user = User::findOrFail($policyData['user_id']);
            $wallet = $user->getOrCreateStellarWallet();
            
            if (!$wallet) {
                throw new Exception('Failed to create or retrieve user Stellar wallet');
            }

            // Get the policy smart contract
            $policyContract = $this->getPolicySmartContract();
            
            // Generate unique policy ID for blockchain
            $stellarPolicyId = $this->generateStellarPolicyId($policyData);

            // Prepare policy data for smart contract
            $contractPolicyData = $this->preparePolicyDataForContract($policyData, $stellarPolicyId);

            // Create policy on smart contract
            $contractResult = $this->stellarSmartContractService->createPolicy($contractPolicyData);

            // Create or update the database policy record
            $policy = $this->createOrUpdatePolicy($policyData, $wallet, $policyContract, $contractResult);

            // Record the transaction
            $this->recordPolicyTransaction($policy, $wallet, $contractResult);

            DB::commit();

            Log::channel('stellar-smart-contracts')->info('Policy created on blockchain', [
                'policy_id' => $policy->id,
                'stellar_policy_id' => $stellarPolicyId,
                'user_id' => $user->id,
                'contract_id' => $policyContract->contract_id,
                'transaction_hash' => $contractResult['transaction_hash'],
            ]);

            return [
                'success' => true,
                'policy_id' => $policy->id,
                'stellar_policy_id' => $stellarPolicyId,
                'contract_id' => $policyContract->contract_id,
                'transaction_hash' => $contractResult['transaction_hash'],
                'policy' => $policy,
            ];

        } catch (Exception $e) {
            DB::rollBack();

            Log::channel('stellar-smart-contracts')->error('Failed to create policy on blockchain', [
                'policy_data' => $policyData,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Activate a policy on the blockchain after premium payment
     * 
     * @param InsurancePolicy $policy The policy to activate
     * @return array Activation result
     */
    public function activatePolicyOnBlockchain(InsurancePolicy $policy): array
    {
        try {
            if (!$policy->isOnStellar()) {
                throw new Exception('Policy is not on Stellar blockchain');
            }

            if ($policy->payment_status !== 'paid') {
                throw new Exception('Policy premium must be paid before activation');
            }

            // Get policy status from smart contract
            $contractStatus = $this->stellarSmartContractService->getPolicyStatus($policy->stellar_policy_id);

            if ($contractStatus['status'] === 'active') {
                return [
                    'success' => true,
                    'message' => 'Policy already active on blockchain',
                    'status' => $contractStatus,
                ];
            }

            // Activate policy on smart contract
            $activationResult = $this->stellarSmartContractService->invokeContract(
                $policy->stellarContract->contract_id,
                'activate_policy',
                [
                    'policy_id' => $policy->stellar_policy_id,
                    'activation_date' => now()->timestamp,
                ]
            );

            // Update policy status
            $policy->update(['status' => 'active']);

            // Record activation transaction
            $this->recordActivationTransaction($policy, $activationResult);

            Log::channel('stellar-smart-contracts')->info('Policy activated on blockchain', [
                'policy_id' => $policy->id,
                'stellar_policy_id' => $policy->stellar_policy_id,
                'transaction_hash' => $activationResult['transaction_hash'] ?? null,
            ]);

            return [
                'success' => true,
                'policy_id' => $policy->id,
                'stellar_policy_id' => $policy->stellar_policy_id,
                'activation_result' => $activationResult,
            ];

        } catch (Exception $e) {
            Log::channel('stellar-smart-contracts')->error('Failed to activate policy on blockchain', [
                'policy_id' => $policy->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Update policy on blockchain
     * 
     * @param InsurancePolicy $policy The policy to update
     * @param array $updateData Data to update
     * @return array Update result
     */
    public function updatePolicyOnBlockchain(InsurancePolicy $policy, array $updateData): array
    {
        try {
            if (!$policy->isOnStellar()) {
                throw new Exception('Policy is not on Stellar blockchain');
            }

            // Prepare update data for smart contract
            $contractUpdateData = array_merge([
                'policy_id' => $policy->stellar_policy_id,
                'updated_at' => now()->timestamp,
            ], $updateData);

            // Update policy on smart contract
            $updateResult = $this->stellarSmartContractService->invokeContract(
                $policy->stellarContract->contract_id,
                'update_policy',
                $contractUpdateData
            );

            // Update policy metadata
            $policy->addStellarMetadata('last_blockchain_update', now());
            $policy->addStellarMetadata('update_transaction_hash', $updateResult['transaction_hash'] ?? null);

            Log::channel('stellar-smart-contracts')->info('Policy updated on blockchain', [
                'policy_id' => $policy->id,
                'stellar_policy_id' => $policy->stellar_policy_id,
                'update_data' => $updateData,
                'transaction_hash' => $updateResult['transaction_hash'] ?? null,
            ]);

            return [
                'success' => true,
                'policy_id' => $policy->id,
                'stellar_policy_id' => $policy->stellar_policy_id,
                'update_result' => $updateResult,
            ];

        } catch (Exception $e) {
            Log::channel('stellar-smart-contracts')->error('Failed to update policy on blockchain', [
                'policy_id' => $policy->id,
                'update_data' => $updateData,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Suspend policy on blockchain
     * 
     * @param InsurancePolicy $policy The policy to suspend
     * @param string $reason Suspension reason
     * @return array Suspension result
     */
    public function suspendPolicyOnBlockchain(InsurancePolicy $policy, string $reason): array
    {
        try {
            if (!$policy->isOnStellar()) {
                throw new Exception('Policy is not on Stellar blockchain');
            }

            // Suspend policy on smart contract
            $suspensionResult = $this->stellarSmartContractService->invokeContract(
                $policy->stellarContract->contract_id,
                'suspend_policy',
                [
                    'policy_id' => $policy->stellar_policy_id,
                    'reason' => $reason,
                    'suspended_at' => now()->timestamp,
                ]
            );

            // Update policy status
            $policy->update(['status' => 'suspended']);
            $policy->addStellarMetadata('suspension_reason', $reason);
            $policy->addStellarMetadata('suspended_at', now());

            Log::channel('stellar-smart-contracts')->info('Policy suspended on blockchain', [
                'policy_id' => $policy->id,
                'stellar_policy_id' => $policy->stellar_policy_id,
                'reason' => $reason,
                'transaction_hash' => $suspensionResult['transaction_hash'] ?? null,
            ]);

            return [
                'success' => true,
                'policy_id' => $policy->id,
                'stellar_policy_id' => $policy->stellar_policy_id,
                'suspension_result' => $suspensionResult,
            ];

        } catch (Exception $e) {
            Log::channel('stellar-smart-contracts')->error('Failed to suspend policy on blockchain', [
                'policy_id' => $policy->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get policy status from blockchain
     * 
     * @param InsurancePolicy $policy The policy to check
     * @return array Policy status
     */
    public function getPolicyStatusFromBlockchain(InsurancePolicy $policy): array
    {
        try {
            if (!$policy->isOnStellar()) {
                throw new Exception('Policy is not on Stellar blockchain');
            }

            $status = $this->stellarSmartContractService->getPolicyStatus($policy->stellar_policy_id);

            return [
                'success' => true,
                'policy_id' => $policy->id,
                'stellar_policy_id' => $policy->stellar_policy_id,
                'blockchain_status' => $status,
                'database_status' => $policy->status,
                'in_sync' => $status['status'] === $policy->status,
            ];

        } catch (Exception $e) {
            Log::channel('stellar-smart-contracts')->error('Failed to get policy status from blockchain', [
                'policy_id' => $policy->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Validate policy data before blockchain creation
     * 
     * @param array $policyData Policy data to validate
     * @throws Exception
     */
    protected function validatePolicyData(array $policyData): void
    {
        $required = ['user_id', 'farm_id', 'product_id', 'premium_amount', 'coverage_amount', 'start_date', 'end_date'];
        
        foreach ($required as $field) {
            if (!isset($policyData[$field]) || empty($policyData[$field])) {
                throw new Exception("Required field '{$field}' is missing or empty");
            }
        }

        // Validate user exists and has Stellar enabled
        $user = User::find($policyData['user_id']);
        if (!$user || !$user->hasStellarEnabled()) {
            throw new Exception('User not found or Stellar not enabled');
        }

        // Validate farm exists and belongs to user
        $farm = Farm::find($policyData['farm_id']);
        // Use loose comparison to handle string/int type differences
        if (!$farm || $farm->user_id != $policyData['user_id']) {
            throw new Exception('Farm not found or does not belong to user');
        }
    }

    /**
     * Get the policy smart contract
     * 
     * @return StellarSmartContract
     * @throws Exception
     */
    protected function getPolicySmartContract(): StellarSmartContract
    {
        $contractId = config('stellar.insurance.policy_contract_id');
        
        if (!$contractId) {
            throw new Exception('Policy smart contract not configured');
        }

        $contract = StellarSmartContract::where('contract_id', $contractId)
            ->where('contract_type', 'insurance_policy')
            ->where('status', 'active')
            ->first();

        if (!$contract) {
            throw new Exception('Policy smart contract not found or not active');
        }

        return $contract;
    }

    /**
     * Generate unique Stellar policy ID
     * 
     * @param array $policyData Policy data
     * @return string
     */
    protected function generateStellarPolicyId(array $policyData): string
    {
        return 'POL-' . $policyData['user_id'] . '-' . time() . '-' . rand(1000, 9999);
    }

    /**
     * Prepare policy data for smart contract
     * 
     * @param array $policyData Original policy data
     * @param string $stellarPolicyId Stellar policy ID
     * @return array
     */
    protected function preparePolicyDataForContract(array $policyData, string $stellarPolicyId): array
    {
        return [
            'policy_id' => $stellarPolicyId,
            'user_id' => $policyData['user_id'],
            'farm_id' => $policyData['farm_id'],
            'product_id' => $policyData['product_id'],
            'premium_amount' => $policyData['premium_amount'],
            'coverage_amount' => $policyData['coverage_amount'],
            'start_date' => strtotime($policyData['start_date']),
            'end_date' => strtotime($policyData['end_date']),
            'parametric_triggers' => $policyData['parametric_triggers'] ?? [],
            'created_at' => now()->timestamp,
        ];
    }

    /**
     * Create or update policy in database
     * 
     * @param array $policyData Original policy data
     * @param $wallet User's Stellar wallet
     * @param StellarSmartContract $contract Policy contract
     * @param array $contractResult Contract creation result
     * @return InsurancePolicy
     */
    protected function createOrUpdatePolicy(
        array $policyData,
        $wallet,
        StellarSmartContract $contract,
        array $contractResult
    ): InsurancePolicy {
        $policyData['stellar_wallet_id'] = $wallet->id;
        $policyData['stellar_contract_id'] = $contract->id;
        $policyData['stellar_transaction_hash'] = $contractResult['transaction_hash'];
        $policyData['stellar_policy_id'] = $contractResult['policy_id'];
        $policyData['stellar_metadata'] = [
            'created_on_blockchain' => true,
            'contract_creation_result' => $contractResult,
            'created_at' => now(),
        ];

        if (isset($policyData['id'])) {
            $policy = InsurancePolicy::findOrFail($policyData['id']);
            $policy->update($policyData);
        } else {
            $policy = InsurancePolicy::create($policyData);
        }

        return $policy;
    }

    /**
     * Record policy creation transaction
     * 
     * @param InsurancePolicy $policy The policy
     * @param $wallet User's wallet
     * @param array $contractResult Contract result
     */
    protected function recordPolicyTransaction(InsurancePolicy $policy, $wallet, array $contractResult): void
    {
        StellarTransaction::create([
            'stellar_wallet_id' => $wallet->id,
            'user_id' => $policy->user_id,
            'transaction_hash' => $contractResult['transaction_hash'],
            'operation_type' => 'invoke_host_function',
            'source_account' => $wallet->public_key,
            'status' => 'success',
            'successful' => true,
            'insurance_policy_id' => $policy->id,
            'contract_id' => $policy->stellarContract->contract_id,
            'contract_method' => 'create_policy',
            'metadata' => [
                'policy_creation' => true,
                'stellar_policy_id' => $policy->stellar_policy_id,
                'contract_result' => $contractResult,
            ],
        ]);
    }

    /**
     * Record policy activation transaction
     * 
     * @param InsurancePolicy $policy The policy
     * @param array $activationResult Activation result
     */
    protected function recordActivationTransaction(InsurancePolicy $policy, array $activationResult): void
    {
        StellarTransaction::create([
            'stellar_wallet_id' => $policy->stellar_wallet_id,
            'user_id' => $policy->user_id,
            'transaction_hash' => $activationResult['transaction_hash'] ?? 'PLACEHOLDER_HASH',
            'operation_type' => 'invoke_host_function',
            'source_account' => $policy->stellarWallet->public_key,
            'status' => 'success',
            'successful' => true,
            'insurance_policy_id' => $policy->id,
            'contract_id' => $policy->stellarContract->contract_id,
            'contract_method' => 'activate_policy',
            'metadata' => [
                'policy_activation' => true,
                'stellar_policy_id' => $policy->stellar_policy_id,
                'activation_result' => $activationResult,
            ],
        ]);
    }
}
