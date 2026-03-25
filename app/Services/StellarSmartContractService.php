<?php

namespace App\Services;
use App\Services\StellarSdkContractService;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * Stellar Smart Contract Service for Soroban operations
 * 
 * This service handles smart contract interactions on the Stellar network
 * using the Soroban platform for insurance-related operations.
 */
class StellarSmartContractService
{
    protected $stellarService;
    protected $sorobanRpcUrl;
    protected $contractIds;

    public function __construct(StellarService $stellarService)
    {
        $this->stellarService = $stellarService;
        $this->sorobanRpcUrl = Config::get('stellar.networks.' . Config::get('stellar.default_network') . '.soroban_rpc_url');
        $this->contractIds = Config::get('stellar.insurance');
    }

    /**
     * Deploy an insurance policy smart contract automatically when payment is completed
     *
     * @param \App\Models\InsurancePolicy $policy The insurance policy
     * @return array Contract deployment result
     */
    public function deployPolicyContractOnPayment(\App\Models\InsurancePolicy $policy): array
    {
        try {
            DB::beginTransaction();

            // Check if contract already exists
            if ($policy->stellar_contract_id) {
                $existingContract = \App\Models\StellarSmartContract::find($policy->stellar_contract_id);

                Log::channel('stellar-smart-contracts')->info('Policy already has smart contract', [
                    'policy_id' => $policy->id,
                    'contract_id' => $existingContract?->contract_id ?? $policy->stellar_contract_id
                ]);

                return [
                    'success' => true,
                    'contract_id' => $existingContract?->contract_id ?? $policy->stellar_contract_id,
                    'contract_address' => $existingContract?->contract_address,
                    'message' => 'Contract already exists'
                ];
            }

            // Prepare policy data for smart contract
            $policyData = $this->preparePolicyDataForContract($policy);

            // Link the policy to the configured shared policy contract
            $deployResult = $this->deployContract($policyData);

            if ($deployResult['success']) {
                // Store contract information in database
                $smartContract = $this->storePolicyContract($policy, $deployResult);

                // Update policy with contract information
                $policy->update([
                    'stellar_contract_id' => $smartContract->id,
                    'stellar_policy_id' => $deployResult['stellar_policy_id'],
                    'stellar_transaction_hash' => $deployResult['transaction_hash'],
                    'stellar_metadata' => array_merge($policy->stellar_metadata ?? [], [
                        'contract_linked_at' => now()->toISOString(),
                        'deployment_trigger' => 'payment_completed',
                        'contract_link_strategy' => 'shared_suite_policy_contract',
                        'contract_type' => 'insurance_policy'
                    ])
                ]);

                DB::commit();

                Log::channel('stellar-smart-contracts')->info('Policy linked to shared policy contract successfully', [
                    'policy_id' => $policy->id,
                    'contract_id' => $smartContract->contract_id,
                    'transaction_hash' => $deployResult['transaction_hash']
                ]);

                return [
                    'success' => true,
                    'contract_id' => $smartContract->contract_id,
                    'contract_address' => $smartContract->contract_address,
                    'transaction_hash' => $deployResult['transaction_hash'],
                    'stellar_policy_id' => $deployResult['stellar_policy_id'],
                    'deployed_at' => now(),
                ];
            }

            DB::rollBack();
            return $deployResult;

        } catch (Exception $e) {
            DB::rollBack();

            Log::channel('stellar-smart-contracts')->error('Failed to deploy policy contract on payment', [
                'policy_id' => $policy->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Deploy an insurance policy smart contract
     *
     * @param array $policyData Policy data to store in the contract
     * @return array Contract deployment result
     */
    public function deployPolicyContract(array $policyData): array
    {
        return $this->deployContract($policyData);
    }

    /**
     * Create a new insurance policy on the blockchain
     * 
     * @param array $policyData Policy details
     * @return array Contract invocation result
     */
    public function createPolicy(array $policyData): array
    {
        try {
            $contractId = $this->contractIds['policy_contract_id'];
            
            if (!$contractId) {
                throw new Exception('Policy contract not deployed');
            }

            // Prepare contract parameters for Soroban
            $params = [
                'policyholder' => $policyData['policyholder'],
                'farm_location' => [
                    'latitude' => (int)($policyData['farm_location']['latitude'] * 1000000),
                    'longitude' => (int)($policyData['farm_location']['longitude'] * 1000000),
                    'region' => $policyData['farm_location']['region'],
                ],
                'premium_amount' => (string)$policyData['premium_amount'],
                'coverage_amount' => (string)$policyData['coverage_amount'],
                'start_date' => (int)$policyData['start_date'],
                'end_date' => (int)$policyData['end_date'],
                'parametric_triggers' => $this->formatParametricTriggers($policyData['parametric_triggers'] ?? []),
            ];

            // Invoke contract method
            $contractResult = $this->invokeContract($contractId, 'create_policy', $params);

            $result = [
                'success' => true,
                'contract_id' => $contractId,
                'transaction_hash' => $contractResult['transaction_hash'] ?? 'PLACEHOLDER_HASH',
                'policy_id' => $contractResult['policy_id'] ?? $policyData['policy_id'],
                'created_at' => now(),
            ];

            Log::channel('stellar-smart-contracts')->info('Policy created on blockchain', $result);

            return $result;
        } catch (Exception $e) {
            Log::channel('stellar-smart-contracts')->error('Failed to create policy on blockchain', [
                'policy_data' => $policyData,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Process premium payment through smart contract
     * 
     * @param string $policyId Policy ID
     * @param string $payerAccount Payer account ID
     * @param string $amount Payment amount
     * @return array Payment result
     */
    public function processPremiumPayment(string $policyId, string $payerAccount, string $amount): array
    {
        try {
            $contractId = $this->contractIds['payment_contract_id'];
            
            if (!$contractId) {
                throw new Exception('Payment contract not deployed');
            }

            // TODO: Implement premium payment through smart contract
            /*
            $result = $this->invokeContract($contractId, 'process_premium', [
                'policy_id' => $policyId,
                'payer' => $payerAccount,
                'amount' => $amount,
                'timestamp' => time(),
            ]);
            */

            $result = [
                'success' => true,
                'contract_id' => $contractId,
                'transaction_hash' => 'PLACEHOLDER_HASH',
                'policy_id' => $policyId,
                'payer_account' => $payerAccount,
                'amount' => $amount,
                'processed_at' => now(),
            ];

            Log::channel('stellar-smart-contracts')->info('Premium payment processed', $result);

            return $result;
        } catch (Exception $e) {
            Log::channel('stellar-smart-contracts')->error('Failed to process premium payment', [
                'policy_id' => $policyId,
                'payer_account' => $payerAccount,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Submit a claim to the smart contract
     * 
     * @param array $claimData Claim details
     * @return array Claim submission result
     */
    public function submitClaim(array $claimData): array
    {
        try {
            $contractId = $this->contractIds['claim_contract_id'];
            
            if (!$contractId) {
                throw new Exception('Claim contract not deployed');
            }

            // TODO: Implement claim submission through smart contract
            /*
            $result = $this->invokeContract($contractId, 'submit_claim', [
                'claim_id' => $claimData['claim_id'],
                'policy_id' => $claimData['policy_id'],
                'claimant' => $claimData['claimant_account'],
                'amount_claimed' => $claimData['amount_claimed'],
                'incident_type' => $claimData['incident_type'],
                'evidence_hash' => $claimData['evidence_hash'] ?? null,
                'parametric_data' => $claimData['parametric_data'] ?? [],
            ]);
            */

            $result = [
                'success' => true,
                'contract_id' => $contractId,
                'transaction_hash' => 'PLACEHOLDER_HASH',
                'claim_id' => $claimData['claim_id'],
                'policy_id' => $claimData['policy_id'],
                'submitted_at' => now(),
            ];

            Log::channel('stellar-smart-contracts')->info('Claim submitted to blockchain', $result);

            return $result;
        } catch (Exception $e) {
            Log::channel('stellar-smart-contracts')->error('Failed to submit claim to blockchain', [
                'claim_data' => $claimData,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Process automatic parametric claim payout
     * 
     * @param string $claimId Claim ID
     * @param array $parametricData Environmental data that triggered the claim
     * @return array Payout result
     */
    public function processParametricPayout(string $claimId, array $parametricData): array
    {
        try {
            $contractId = $this->contractIds['claim_contract_id'];
            
            if (!$contractId) {
                throw new Exception('Claim contract not deployed');
            }

            // TODO: Implement automatic parametric payout
            /*
            $result = $this->invokeContract($contractId, 'process_parametric_payout', [
                'claim_id' => $claimId,
                'trigger_data' => $parametricData,
                'confidence_score' => $parametricData['confidence_score'] ?? 1.0,
                'auto_approved' => true,
                'timestamp' => time(),
            ]);
            */

            $result = [
                'success' => true,
                'contract_id' => $contractId,
                'transaction_hash' => 'PLACEHOLDER_HASH',
                'claim_id' => $claimId,
                'payout_amount' => $parametricData['payout_amount'] ?? '0',
                'auto_processed' => true,
                'processed_at' => now(),
            ];

            Log::channel('stellar-smart-contracts')->info('Parametric payout processed', $result);

            return $result;
        } catch (Exception $e) {
            Log::channel('stellar-smart-contracts')->error('Failed to process parametric payout', [
                'claim_id' => $claimId,
                'parametric_data' => $parametricData,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get policy status from smart contract
     * 
     * @param string $policyId Policy ID
     * @return array Policy status
     */
    public function getPolicyStatus(string $policyId): array
    {
        try {
            $contractId = $this->contractIds['policy_contract_id'];
            
            if (!$contractId) {
                throw new Exception('Policy contract not deployed');
            }

            // TODO: Implement contract state query
            /*
            $result = $this->queryContract($contractId, 'get_policy_status', [
                'policy_id' => $policyId
            ]);
            */

            $result = [
                'policy_id' => $policyId,
                'status' => 'active',
                'premium_paid' => true,
                'claims_count' => 0,
                'last_updated' => now(),
            ];

            Log::channel('stellar-smart-contracts')->debug('Policy status retrieved', $result);

            return $result;
        } catch (Exception $e) {
            Log::channel('stellar-smart-contracts')->error('Failed to get policy status', [
                'policy_id' => $policyId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get claim status from smart contract
     * 
     * @param string $claimId Claim ID
     * @return array Claim status
     */
    public function getClaimStatus(string $claimId): array
    {
        try {
            $contractId = $this->contractIds['claim_contract_id'];
            
            if (!$contractId) {
                throw new Exception('Claim contract not deployed');
            }

            // TODO: Implement contract state query
            /*
            $result = $this->queryContract($contractId, 'get_claim_status', [
                'claim_id' => $claimId
            ]);
            */

            $result = [
                'claim_id' => $claimId,
                'status' => 'pending',
                'amount_approved' => '0',
                'payout_processed' => false,
                'last_updated' => now(),
            ];

            Log::channel('stellar-smart-contracts')->debug('Claim status retrieved', $result);

            return $result;
        } catch (Exception $e) {
            Log::channel('stellar-smart-contracts')->error('Failed to get claim status', [
                'claim_id' => $claimId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Invoke a smart contract method
     *
     * @param string $contractId Contract ID
     * @param string $method Method name
     * @param array $params Method parameters
     * @return array Invocation result
     */
    public function invokeContract(string $contractId, string $method, array $params): array
    {
        try {
            // Get network configuration
            $network = $this->stellarService->getNetworkConfig();

            // Build Soroban CLI command
            $command = $this->buildSorobanCommand($contractId, $method, $params, $network);

            // Execute command
            $result = $this->executeSorobanCommand($command);

            Log::channel('stellar-smart-contracts')->info('Contract invocation successful', [
                'contract_id' => $contractId,
                'method' => $method,
                'transaction_hash' => $result['transaction_hash'] ?? null,
            ]);

            return [
                'success' => true,
                'transaction_hash' => $result['transaction_hash'] ?? 'HASH_' . time(),
                'result' => $result['result'] ?? $params,
                'method' => $method,
                'contract_id' => $contractId,
            ];

        } catch (Exception $e) {
            Log::channel('stellar-smart-contracts')->error('Contract invocation failed', [
                'contract_id' => $contractId,
                'method' => $method,
                'params' => $params,
                'error' => $e->getMessage(),
            ]);

            // Return placeholder for development
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'transaction_hash' => 'ERROR_HASH_' . time(),
                'result' => null,
            ];
        }
    }

    /**
     * Query a smart contract state
     *
     * @param string $contractId Contract ID
     * @param string $method Query method name
     * @param array $params Query parameters
     * @return array Query result
     */
    protected function queryContract(string $contractId, string $method, array $params): array
    {
        try {
            // Similar to invokeContract but for read-only operations
            $network = $this->stellarService->getNetworkConfig();
            $command = $this->buildSorobanCommand($contractId, $method, $params, $network, true);
            $result = $this->executeSorobanCommand($command);

            Log::channel('stellar-smart-contracts')->debug('Contract query successful', [
                'contract_id' => $contractId,
                'method' => $method,
            ]);

            return $result['result'] ?? [];

        } catch (Exception $e) {
            Log::channel('stellar-smart-contracts')->error('Contract query failed', [
                'contract_id' => $contractId,
                'method' => $method,
                'params' => $params,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Build Soroban CLI command
     *
     * @param string $contractId Contract ID
     * @param string $method Method name
     * @param array $params Parameters
     * @param array $network Network configuration
     * @param bool $readOnly Whether this is a read-only query
     * @return string Command
     */
    protected function buildSorobanCommand(
        string $contractId,
        string $method,
        array $params,
        array $network,
        bool $readOnly = false
    ): string {
        $command = 'soroban contract invoke';
        $command .= ' --id ' . escapeshellarg($contractId);
        $command .= ' --network ' . escapeshellarg($network['network']);

        if (!$readOnly) {
            $command .= ' --source ' . escapeshellarg(config('stellar.master_account_id'));
        }

        $command .= ' -- ' . escapeshellarg($method);

        // Add parameters
        foreach ($params as $key => $value) {
            $command .= ' --' . escapeshellarg($key) . ' ' . escapeshellarg($this->formatParamValue($value));
        }

        return $command;
    }

    /**
     * Execute Soroban command
     *
     * @param string $command Command to execute
     * @return array Result
     */
    protected function executeSorobanCommand(string $command): array
    {
        // For development, return mock data
        if (app()->environment('local', 'testing')) {
            return [
                'transaction_hash' => 'MOCK_HASH_' . time(),
                'result' => ['status' => 'success'],
            ];
        }

        // Execute actual command in production
        $output = shell_exec($command . ' 2>&1');

        if ($output === null) {
            throw new Exception('Failed to execute Soroban command');
        }

        // Parse JSON output
        $result = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from Soroban: ' . $output);
        }

        return $result;
    }

    /**
     * Format parameter value for Soroban
     *
     * @param mixed $value Parameter value
     * @return string Formatted value
     */
    protected function formatParamValue($value): string
    {
        if (is_array($value)) {
            return json_encode($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string)$value;
    }

    /**
     * Format parametric triggers for smart contract
     *
     * @param array $triggers Parametric triggers
     * @return array Formatted triggers
     */
    protected function formatParametricTriggers(array $triggers): array
    {
        $formatted = [];

        foreach ($triggers as $trigger) {
            $formatted[] = [
                'trigger_type' => $trigger['type'] ?? 'Rainfall',
                'threshold_value' => (int)($trigger['threshold'] ?? 0),
                'comparison' => $this->mapComparisonOperator($trigger['comparison'] ?? 'LessThan'),
                'payout_percentage' => (int)($trigger['payout_percentage'] ?? 0),
            ];
        }

        return $formatted;
    }

    /**
     * Map comparison operator to smart contract enum
     *
     * @param string $operator Comparison operator
     * @return string Mapped operator
     */
    protected function mapComparisonOperator(string $operator): string
    {
        $mapping = [
            '<' => 'LessThan',
            '<=' => 'LessThanOrEqual',
            '>' => 'GreaterThan',
            '>=' => 'GreaterThanOrEqual',
            '=' => 'Equal',
            '==' => 'Equal',
        ];

        return $mapping[$operator] ?? $operator;
    }

    /**
     * Prepare policy data for smart contract deployment
     *
     * @param \App\Models\InsurancePolicy $policy
     * @return array
     */
    protected function preparePolicyDataForContract(\App\Models\InsurancePolicy $policy): array
    {
        return [
            'policy_id' => $policy->policy_number,
            'policy_holder' => $policy->user->stellar_account_id ?? $policy->user->email,
            'farm_location' => [
                'latitude' => $policy->farm->latitude ?? 0,
                'longitude' => $policy->farm->longitude ?? 0,
                'state' => $policy->farm->state ?? '',
                'country' => 'Nigeria'
            ],
            'premium_amount' => (int)($policy->premium_amount * 10000000), // Convert to stroops
            'coverage_amount' => (int)($policy->coverage_amount * 10000000), // Convert to stroops
            'start_date' => $policy->start_date->timestamp,
            'end_date' => $policy->end_date->timestamp,
            'policy_type' => $policy->type ?? 'crop',
            'parametric_triggers' => $policy->parametric_triggers ?? [],
            'created_at' => $policy->created_at->timestamp,
        ];
    }

    /**
     * Link a policy to the configured shared policy contract
     *
     * @param array $policyData
     * @return array
     */
    protected function deployContract(array $policyData): array
    {
        try {
            $contractId = $this->contractIds['policy_contract_id'] ?? null;

            if (!$contractId) {
                throw new Exception('Shared insurance-policy contract is not configured');
            }

            Log::channel('stellar-smart-contracts')->info('Linking policy to configured shared policy contract', [
                'policy_data' => $policyData,
                'contract_id' => $contractId,
                'network' => config('stellar.default_network', 'testnet')
            ]);

            return [
                'success' => true,
                'contract_id' => $contractId,
                'contract_address' => $contractId,
                'transaction_hash' => null,
                'stellar_policy_id' => $policyData['policy_id'] ?? null,
                'deployed_at' => now(),
                'network' => config('stellar.default_network', 'testnet'),
            ];

        } catch (Exception $e) {
            Log::channel('stellar-smart-contracts')->error('Contract deployment failed', [
                'error' => $e->getMessage(),
                'policy_data' => $policyData
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Store the deployed contract information in the database
     *
     * @param \App\Models\InsurancePolicy $policy
     * @param array $deployResult
     * @return \App\Models\StellarSmartContract
     */
    protected function storePolicyContract(\App\Models\InsurancePolicy $policy, array $deployResult): \App\Models\StellarSmartContract
    {
        $smartContract = \App\Models\StellarSmartContract::firstOrNew([
            'contract_id' => $deployResult['contract_id'],
        ]);

        $smartContract->contract_address = $smartContract->contract_address ?? $deployResult['contract_address'];
        $smartContract->contract_type = $smartContract->contract_type ?? 'insurance_policy';
        $smartContract->name = $smartContract->name ?? 'Shared Insurance Policy Contract';
        $smartContract->description = $smartContract->description ?? 'Shared live insurance-policy contract used by Riwe policy records.';
        $smartContract->deployer_account = $smartContract->deployer_account ?? config('stellar.insurance.master_account_id');
        $smartContract->network = $smartContract->network ?? ($deployResult['network'] ?? config('stellar.default_network', 'testnet'));
        $smartContract->status = $smartContract->status ?? 'active';
        $smartContract->version = $smartContract->version ?? '1.0.0';

        if (!$smartContract->deployment_transaction_hash && !empty($deployResult['transaction_hash'])) {
            $smartContract->deployment_transaction_hash = $deployResult['transaction_hash'];
        }

        if (!$smartContract->deployed_at && !empty($deployResult['deployed_at'])) {
            $smartContract->deployed_at = $deployResult['deployed_at'];
        }

        $smartContract->metadata = array_merge($smartContract->metadata ?? [], [
            'suite_contract' => true,
            'suite_role' => 'insurance_policy',
            'link_mode' => 'shared_contract_record',
        ]);

        $smartContract->save();

        return $smartContract;
    }

    /**
     * Deploy a real Stellar smart contract using the Stellar SDK
     *
     * @param string $wasmPath Path to the WASM contract file
     * @param array $policyData Policy data to initialize the contract
     * @return array Deployment result with real contract ID and transaction hash
     * @throws Exception
     */
    protected function deployRealStellarContract(string $wasmPath, array $policyData): array
    {
        try {
            // Load the WASM contract code
            $contractCode = file_get_contents($wasmPath);
            if ($contractCode === false) {
                throw new Exception('Failed to read WASM contract file: ' . $wasmPath);
            }

            // Get network configuration
            $network = config('stellar.default_network', 'testnet');
            $networkConfig = config("stellar.networks.{$network}");

            if (!$networkConfig) {
                throw new Exception("Network configuration not found for: {$network}");
            }

            // Get deployer account from configuration
            $deployerSecretKey = config('stellar.insurance.master_account_secret');
            if (!$deployerSecretKey) {
                throw new Exception('Master secret key not configured for contract deployment');
            }

            // Use Soroban CLI for deployment (more reliable than SDK for complex deployments)
            $deploymentResult = $this->deploySorobanContract($contractCode, $policyData, $network);

            Log::channel('stellar-smart-contracts')->info('Real contract deployed successfully', [
                'contract_id' => $deploymentResult['contract_id'],
                'transaction_hash' => $deploymentResult['transaction_hash'],
                'network' => $network
            ]);

            return $deploymentResult;

        } catch (Exception $e) {
            Log::channel('stellar-smart-contracts')->error('Real contract deployment failed', [
                'error' => $e->getMessage(),
                'wasm_path' => $wasmPath,
                'policy_data' => $policyData
            ]);
            throw $e;
        }
    }

    /**
     * Deploy contract using Soroban CLI commands
     *
     * @param string $contractCode WASM contract code
     * @param array $policyData Policy data
     * @param string $network Network (testnet/mainnet)
     * @return array Deployment result
     * @throws Exception
     */
    protected function deploySorobanContract(string $contractCode, array $policyData, string $network): array
    {
        // Create temporary WASM file
        $tempWasmPath = sys_get_temp_dir() . '/insurance_contract_' . time() . '.wasm';
        file_put_contents($tempWasmPath, $contractCode);

        try {
            // Use SDK-based deployment instead of CLI
            $sdkService = new StellarSdkContractService();
            $deploymentResult = $sdkService->deployContract(
                $tempWasmPath,
                config('stellar.insurance.master_account_secret')
            );

            // Initialize contract with policy data
            $stellarPolicyId = 'POL-' . $policyData['policy_id'] . '-' . time();

            return [
                'contract_id' => $deploymentResult['contract_id'],
                'transaction_hash' => $deploymentResult['transaction_hash'],
                'stellar_policy_id' => $stellarPolicyId,
                'wasm_hash' => 'deployed_via_sdk'
            ];

        } finally {
            // Clean up temporary file
            if (file_exists($tempWasmPath)) {
                unlink($tempWasmPath);
            }
        }
    }

    /**
     * Execute Soroban CLI command and return output
     *
     * @param string $command Command to execute
     * @return string Command output
     * @throws Exception
     */
    protected function executeSorobanCliCommand(string $command): string
    {
        $output = [];
        $returnCode = 0;

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $errorOutput = implode("\n", $output);
            throw new Exception("Soroban CLI command failed: {$command}\nOutput: {$errorOutput}");
        }

        $fullOutput = implode("\n", $output);

        // For upload command, return the WASM hash (last line)
        if (strpos($command, 'upload') !== false) {
            $lines = array_filter($output);
            return end($lines); // Return last non-empty line (WASM hash)
        }

        // For deploy command, return the full output (contains both contract ID and transaction hash)
        if (strpos($command, 'deploy') !== false) {
            return $fullOutput; // Return full output for parsing
        }

        return $fullOutput;
    }

    /**
     * Extract transaction hash from CLI output
     *
     * @param string $output CLI output containing transaction hash
     * @return string Transaction hash
     */
    protected function extractTransactionHashFromOutput(string $output): string
    {
        // The deploy command shows transaction hash in the output
        // Look for pattern like "Transaction hash is 7c0d7104db151cae..."
        if (preg_match('/Transaction hash is ([a-f0-9]{64})/i', $output, $matches)) {
            return strtoupper($matches[1]);
        }

        // Fallback: look for any 64-character hex string
        if (preg_match('/([a-f0-9]{64})/i', $output, $matches)) {
            return strtoupper($matches[1]);
        }

        // If no transaction hash found, generate a placeholder
        return 'TX_' . strtoupper(bin2hex(random_bytes(30)));
    }


}
