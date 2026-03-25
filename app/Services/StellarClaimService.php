<?php

namespace App\Services;

use App\Models\Claim;
use App\Models\InsurancePolicy;
use App\Models\StellarSmartContract;
use App\Models\StellarTransaction;
use App\Services\WeatherService;
use App\Services\DataServices\CopernicusService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

/**
 * Stellar Claim Service for automated claim processing
 * 
 * This service handles automated claim processing using Stellar smart contracts,
 * including parametric claim evaluation and automatic payouts.
 */
class StellarClaimService
{
    protected $stellarService;
    protected $stellarWalletService;
    protected $stellarSmartContractService;
    protected $weatherService;
    protected $copernicusService;

    public function __construct(
        StellarService $stellarService,
        StellarWalletService $stellarWalletService,
        StellarSmartContractService $stellarSmartContractService,
        WeatherService $weatherService,
        CopernicusService $copernicusService
    ) {
        $this->stellarService = $stellarService;
        $this->stellarWalletService = $stellarWalletService;
        $this->stellarSmartContractService = $stellarSmartContractService;
        $this->weatherService = $weatherService;
        $this->copernicusService = $copernicusService;
    }

    /**
     * Submit a claim to the Stellar blockchain
     * 
     * @param array $claimData Claim data
     * @return array Claim submission result
     */
    public function submitClaimToBlockchain(array $claimData): array
    {
        try {
            DB::beginTransaction();

            // Validate claim data
            $this->validateClaimData($claimData);

            // Get the policy and verify it's on Stellar
            $policy = InsurancePolicy::findOrFail($claimData['insurance_policy_id']);
            if (!$policy->isOnStellar()) {
                throw new Exception('Policy is not on Stellar blockchain');
            }

            // Generate unique claim ID for blockchain
            $stellarClaimId = $this->generateStellarClaimId($claimData);

            // Prepare claim data for smart contract
            $contractClaimData = $this->prepareClaimDataForContract($claimData, $stellarClaimId);

            // Submit claim to smart contract
            $contractResult = $this->stellarSmartContractService->submitClaim($contractClaimData);

            // Create or update the database claim record
            $claim = $this->createOrUpdateClaim($claimData, $policy, $contractResult);

            // Record the transaction
            $this->recordClaimTransaction($claim, $policy, $contractResult);

            DB::commit();

            Log::channel('stellar-smart-contracts')->info('Claim submitted to blockchain', [
                'claim_id' => $claim->id,
                'stellar_claim_id' => $stellarClaimId,
                'policy_id' => $policy->id,
                'transaction_hash' => $contractResult['transaction_hash'],
            ]);

            return [
                'success' => true,
                'claim_id' => $claim->id,
                'stellar_claim_id' => $stellarClaimId,
                'transaction_hash' => $contractResult['transaction_hash'],
                'claim' => $claim,
            ];

        } catch (Exception $e) {
            DB::rollBack();

            Log::channel('stellar-smart-contracts')->error('Failed to submit claim to blockchain', [
                'claim_data' => $claimData,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Process parametric claims automatically
     * 
     * @return array Processing statistics
     */
    public function processParametricClaims(): array
    {
        $stats = [
            'policies_checked' => 0,
            'claims_created' => 0,
            'payouts_processed' => 0,
            'errors' => 0,
        ];

        try {
            // Get all active parametric policies on Stellar
            $policies = InsurancePolicy::whereHas('product', function($query) {
                $query->where('is_parametric', true);
            })
            ->where('status', 'active')
            ->whereNotNull('stellar_contract_id')
            ->whereNotNull('stellar_policy_id')
            ->with(['product', 'farm', 'stellarWallet'])
            ->get();

            Log::info('Processing parametric claims on Stellar', ['count' => $policies->count()]);
            $stats['policies_checked'] = $policies->count();

            foreach ($policies as $policy) {
                try {
                    $result = $this->processParametricClaimForPolicy($policy);
                    
                    if ($result['claim_created']) {
                        $stats['claims_created']++;
                    }
                    
                    if ($result['payout_processed']) {
                        $stats['payouts_processed']++;
                    }

                } catch (Exception $e) {
                    $stats['errors']++;
                    Log::channel('stellar-smart-contracts')->error('Error processing parametric claim', [
                        'policy_id' => $policy->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Parametric claims processing completed', $stats);

            return $stats;

        } catch (Exception $e) {
            Log::channel('stellar-smart-contracts')->error('Failed to process parametric claims', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Process parametric claim for a specific policy
     * 
     * @param InsurancePolicy $policy The policy to process
     * @return array Processing result
     */
    protected function processParametricClaimForPolicy(InsurancePolicy $policy): array
    {
        $result = [
            'claim_created' => false,
            'payout_processed' => false,
            'triggers_exceeded' => [],
        ];

        // Get environmental data for the farm
        $environmentalData = $this->getEnvironmentalDataForFarm($policy->farm);

        // Check parametric triggers
        $exceededTriggers = $this->checkParametricTriggers($policy, $environmentalData);

        if (empty($exceededTriggers)) {
            return $result;
        }

        $result['triggers_exceeded'] = $exceededTriggers;

        // Check if claim already exists for this trigger event
        $existingClaim = $this->findExistingParametricClaim($policy, $exceededTriggers);
        if ($existingClaim) {
            return $result;
        }

        // Create automatic claim
        $claim = $this->createAutomaticParametricClaim($policy, $exceededTriggers, $environmentalData);
        $result['claim_created'] = true;

        // Process automatic payout if confidence is high enough
        $confidenceScore = $this->calculateConfidenceScore($exceededTriggers, $environmentalData);
        $autoPayoutThreshold = config('stellar.insurance.parametric.auto_claim_threshold', 0.8);

        if ($confidenceScore >= $autoPayoutThreshold) {
            $payoutResult = $this->processAutomaticPayout($claim, $exceededTriggers, $environmentalData);
            $result['payout_processed'] = $payoutResult['success'];
        }

        return $result;
    }

    /**
     * Process automatic payout for a claim
     * 
     * @param Claim $claim The claim to process
     * @param array $exceededTriggers Exceeded triggers
     * @param array $environmentalData Environmental data
     * @return array Payout result
     */
    public function processAutomaticPayout(Claim $claim, array $exceededTriggers, array $environmentalData): array
    {
        try {
            DB::beginTransaction();

            // Calculate payout amount based on triggers
            $payoutAmount = $this->calculatePayoutAmount($claim, $exceededTriggers);

            // Prepare parametric data for smart contract
            $parametricData = [
                'claim_id' => $claim->stellar_claim_id,
                'triggers' => $exceededTriggers,
                'environmental_data' => $environmentalData,
                'payout_amount' => $payoutAmount,
                'confidence_score' => $this->calculateConfidenceScore($exceededTriggers, $environmentalData),
                'auto_processed' => true,
            ];

            // Process payout through smart contract
            $contractResult = $this->stellarSmartContractService->processParametricPayout(
                $claim->stellar_claim_id,
                $parametricData
            );

            // Send actual payment to user's wallet
            $paymentResult = $this->sendPayoutToUser($claim, $payoutAmount);

            // Update claim status
            $claim->update([
                'status' => 'approved',
                'amount_approved' => $payoutAmount,
                'stellar_payout_hash' => $paymentResult['transaction_hash'],
                'approved_at' => now(),
                'disbursement_date' => now(),
            ]);

            // Add metadata
            $claim->addStellarMetadata('automatic_payout', true);
            $claim->addStellarMetadata('payout_transaction', $paymentResult);
            $claim->addStellarMetadata('contract_result', $contractResult);

            // Record payout transaction
            $this->recordPayoutTransaction($claim, $paymentResult, $contractResult);

            DB::commit();

            Log::channel('stellar-smart-contracts')->info('Automatic payout processed', [
                'claim_id' => $claim->id,
                'stellar_claim_id' => $claim->stellar_claim_id,
                'payout_amount' => $payoutAmount,
                'payout_hash' => $paymentResult['transaction_hash'],
            ]);

            return [
                'success' => true,
                'claim_id' => $claim->id,
                'payout_amount' => $payoutAmount,
                'transaction_hash' => $paymentResult['transaction_hash'],
                'contract_result' => $contractResult,
            ];

        } catch (Exception $e) {
            DB::rollBack();

            Log::channel('stellar-smart-contracts')->error('Failed to process automatic payout', [
                'claim_id' => $claim->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get claim status from blockchain
     * 
     * @param Claim $claim The claim to check
     * @return array Claim status
     */
    public function getClaimStatusFromBlockchain(Claim $claim): array
    {
        try {
            if (!$claim->isOnStellar()) {
                throw new Exception('Claim is not on Stellar blockchain');
            }

            $status = $this->stellarSmartContractService->getClaimStatus($claim->stellar_claim_id);

            return [
                'success' => true,
                'claim_id' => $claim->id,
                'stellar_claim_id' => $claim->stellar_claim_id,
                'blockchain_status' => $status,
                'database_status' => $claim->status,
                'in_sync' => $status['status'] === $claim->status,
            ];

        } catch (Exception $e) {
            Log::channel('stellar-smart-contracts')->error('Failed to get claim status from blockchain', [
                'claim_id' => $claim->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Validate claim data before blockchain submission
     * 
     * @param array $claimData Claim data to validate
     * @throws Exception
     */
    protected function validateClaimData(array $claimData): void
    {
        $required = ['insurance_policy_id', 'incident_type', 'amount_claimed', 'incident_date'];
        
        foreach ($required as $field) {
            if (!isset($claimData[$field]) || empty($claimData[$field])) {
                throw new Exception("Required field '{$field}' is missing or empty");
            }
        }
    }

    /**
     * Generate unique Stellar claim ID
     * 
     * @param array $claimData Claim data
     * @return string
     */
    protected function generateStellarClaimId(array $claimData): string
    {
        return 'CLM-' . $claimData['insurance_policy_id'] . '-' . time() . '-' . rand(1000, 9999);
    }

    /**
     * Prepare claim data for smart contract
     * 
     * @param array $claimData Original claim data
     * @param string $stellarClaimId Stellar claim ID
     * @return array
     */
    protected function prepareClaimDataForContract(array $claimData, string $stellarClaimId): array
    {
        return [
            'claim_id' => $stellarClaimId,
            'policy_id' => $claimData['insurance_policy_id'],
            'claimant_account' => $claimData['claimant_account'] ?? null,
            'amount_claimed' => $claimData['amount_claimed'],
            'incident_type' => $claimData['incident_type'],
            'incident_date' => strtotime($claimData['incident_date']),
            'evidence_hash' => $claimData['evidence_hash'] ?? null,
            'parametric_data' => $claimData['parametric_data'] ?? [],
            'created_at' => now()->timestamp,
        ];
    }

    /**
     * Create or update claim in database
     * 
     * @param array $claimData Original claim data
     * @param InsurancePolicy $policy The policy
     * @param array $contractResult Contract submission result
     * @return Claim
     */
    protected function createOrUpdateClaim(
        array $claimData,
        InsurancePolicy $policy,
        array $contractResult
    ): Claim {
        $claimData['stellar_contract_id'] = $policy->stellar_contract_id;
        $claimData['stellar_claim_id'] = $contractResult['claim_id'];
        $claimData['stellar_transaction_hash'] = $contractResult['transaction_hash'];
        $claimData['stellar_metadata'] = [
            'submitted_to_blockchain' => true,
            'contract_submission_result' => $contractResult,
            'submitted_at' => now(),
        ];

        if (isset($claimData['id'])) {
            $claim = Claim::findOrFail($claimData['id']);
            $claim->update($claimData);
        } else {
            $claimData['claim_number'] = $claimData['claim_number'] ?? Claim::generateClaimNumber();
            $claim = Claim::create($claimData);
        }

        return $claim;
    }

    /**
     * Get environmental data for a farm
     * 
     * @param $farm The farm
     * @return array Environmental data
     */
    protected function getEnvironmentalDataForFarm($farm): array
    {
        try {
            // Get weather data
            $weatherData = $this->weatherService->getCurrentWeather($farm->latitude, $farm->longitude);
            
            // Get satellite data
            $satelliteData = $this->copernicusService->getVegetationIndices($farm->latitude, $farm->longitude);

            return [
                'weather' => $weatherData,
                'satellite' => $satelliteData,
                'location' => [
                    'latitude' => $farm->latitude,
                    'longitude' => $farm->longitude,
                ],
                'retrieved_at' => now(),
            ];

        } catch (Exception $e) {
            Log::channel('stellar-smart-contracts')->warning('Failed to get environmental data', [
                'farm_id' => $farm->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'weather' => [],
                'satellite' => [],
                'location' => [
                    'latitude' => $farm->latitude,
                    'longitude' => $farm->longitude,
                ],
                'error' => $e->getMessage(),
                'retrieved_at' => now(),
            ];
        }
    }

    /**
     * Check parametric triggers for a policy
     * 
     * @param InsurancePolicy $policy The policy
     * @param array $environmentalData Environmental data
     * @return array Exceeded triggers
     */
    protected function checkParametricTriggers(InsurancePolicy $policy, array $environmentalData): array
    {
        $exceededTriggers = [];
        $triggers = $policy->product->parametric_triggers ?? [];

        foreach ($triggers as $triggerType => $threshold) {
            $exceeded = $this->evaluateTrigger($triggerType, $threshold, $environmentalData);
            if ($exceeded) {
                $exceededTriggers[] = [
                    'type' => $triggerType,
                    'threshold' => $threshold,
                    'actual_value' => $exceeded['actual_value'],
                    'severity' => $exceeded['severity'],
                ];
            }
        }

        return $exceededTriggers;
    }

    /**
     * Evaluate a specific trigger
     * 
     * @param string $triggerType Trigger type
     * @param mixed $threshold Threshold value
     * @param array $environmentalData Environmental data
     * @return array|false Trigger result or false if not exceeded
     */
    protected function evaluateTrigger(string $triggerType, $threshold, array $environmentalData)
    {
        // This is a simplified implementation
        // In a real system, this would be more sophisticated
        
        switch ($triggerType) {
            case 'rainfall':
                $actualRainfall = $environmentalData['weather']['precipitation'] ?? 0;
                if ($actualRainfall < $threshold) {
                    return [
                        'actual_value' => $actualRainfall,
                        'severity' => ($threshold - $actualRainfall) / $threshold,
                    ];
                }
                break;
                
            case 'temperature':
                $actualTemp = $environmentalData['weather']['temperature'] ?? 25;
                if ($actualTemp > $threshold) {
                    return [
                        'actual_value' => $actualTemp,
                        'severity' => ($actualTemp - $threshold) / $threshold,
                    ];
                }
                break;
        }

        return false;
    }

    /**
     * Calculate confidence score for parametric triggers
     * 
     * @param array $exceededTriggers Exceeded triggers
     * @param array $environmentalData Environmental data
     * @return float Confidence score (0-1)
     */
    protected function calculateConfidenceScore(array $exceededTriggers, array $environmentalData): float
    {
        if (empty($exceededTriggers)) {
            return 0.0;
        }

        $totalSeverity = 0;
        foreach ($exceededTriggers as $trigger) {
            $totalSeverity += $trigger['severity'];
        }

        $averageSeverity = $totalSeverity / count($exceededTriggers);
        
        // Adjust confidence based on data quality
        $dataQuality = $this->assessDataQuality($environmentalData);
        
        return min(1.0, $averageSeverity * $dataQuality);
    }

    /**
     * Assess the quality of environmental data
     * 
     * @param array $environmentalData Environmental data
     * @return float Quality score (0-1)
     */
    protected function assessDataQuality(array $environmentalData): float
    {
        $score = 0.5; // Base score

        // Increase score if we have weather data
        if (!empty($environmentalData['weather'])) {
            $score += 0.3;
        }

        // Increase score if we have satellite data
        if (!empty($environmentalData['satellite'])) {
            $score += 0.2;
        }

        return min(1.0, $score);
    }

    /**
     * Find existing parametric claim for the same trigger event
     * 
     * @param InsurancePolicy $policy The policy
     * @param array $exceededTriggers Exceeded triggers
     * @return Claim|null
     */
    protected function findExistingParametricClaim(InsurancePolicy $policy, array $exceededTriggers): ?Claim
    {
        // Look for claims in the last 24 hours for the same trigger types
        $triggerTypes = array_column($exceededTriggers, 'type');
        
        return Claim::where('insurance_policy_id', $policy->id)
            ->where('is_parametric', true)
            ->where('created_at', '>=', now()->subDay())
            ->whereJsonContains('parametric_triggers', $triggerTypes)
            ->first();
    }

    /**
     * Create automatic parametric claim
     * 
     * @param InsurancePolicy $policy The policy
     * @param array $exceededTriggers Exceeded triggers
     * @param array $environmentalData Environmental data
     * @return Claim
     */
    protected function createAutomaticParametricClaim(
        InsurancePolicy $policy,
        array $exceededTriggers,
        array $environmentalData
    ): Claim {
        $incidentType = $this->determineIncidentType($exceededTriggers);
        $claimAmount = $this->calculateClaimAmount($policy, $exceededTriggers);
        
        $claimData = [
            'farm_id' => $policy->farm_id,
            'user_id' => $policy->user_id,
            'insurance_policy_id' => $policy->id,
            'claim_number' => Claim::generateClaimNumber(),
            'incident_date' => now(),
            'incident_type' => $incidentType,
            'description' => 'Automatic parametric claim based on environmental triggers',
            'amount_claimed' => $claimAmount,
            'status' => 'pending_review',
            'weather_data' => $environmentalData['weather'],
            'satellite_data' => $environmentalData['satellite'],
            'parametric_triggers' => $exceededTriggers,
            'is_parametric' => true,
        ];

        $result = $this->submitClaimToBlockchain($claimData);
        return $result['claim'];
    }

    /**
     * Determine incident type from exceeded triggers
     * 
     * @param array $exceededTriggers Exceeded triggers
     * @return string
     */
    protected function determineIncidentType(array $exceededTriggers): string
    {
        $types = array_column($exceededTriggers, 'type');
        
        if (in_array('rainfall', $types)) {
            return 'drought';
        }
        
        if (in_array('temperature', $types)) {
            return 'heat_stress';
        }
        
        return 'weather_event';
    }

    /**
     * Calculate claim amount based on triggers
     * 
     * @param InsurancePolicy $policy The policy
     * @param array $exceededTriggers Exceeded triggers
     * @return string
     */
    protected function calculateClaimAmount(InsurancePolicy $policy, array $exceededTriggers): string
    {
        $maxSeverity = max(array_column($exceededTriggers, 'severity'));
        $payoutPercentage = min(1.0, $maxSeverity);
        
        return number_format($policy->coverage_amount * $payoutPercentage, 2, '.', '');
    }

    /**
     * Calculate payout amount for a claim
     * 
     * @param Claim $claim The claim
     * @param array $exceededTriggers Exceeded triggers
     * @return string
     */
    protected function calculatePayoutAmount(Claim $claim, array $exceededTriggers): string
    {
        // For automatic claims, use the claimed amount
        return $claim->amount_claimed;
    }

    /**
     * Send payout to user's wallet
     * 
     * @param Claim $claim The claim
     * @param string $amount Payout amount
     * @return array Payment result
     */
    protected function sendPayoutToUser(Claim $claim, string $amount): array
    {
        $policy = $claim->insurancePolicy;
        $userWallet = $policy->stellarWallet;
        
        if (!$userWallet) {
            throw new Exception('User does not have a Stellar wallet');
        }

        // Get insurance company's master account
        $masterSecret = config('stellar.insurance.master_account_secret');
        if (!$masterSecret) {
            throw new Exception('Insurance master account not configured');
        }

        // Send payment from insurance company to user
        return $this->stellarService->sendPayment(
            $masterSecret,
            $userWallet->public_key,
            $amount,
            'XLM',
            null,
            'Insurance claim payout for claim ' . $claim->claim_number
        );
    }

    /**
     * Record claim submission transaction
     * 
     * @param Claim $claim The claim
     * @param InsurancePolicy $policy The policy
     * @param array $contractResult Contract result
     */
    protected function recordClaimTransaction(Claim $claim, InsurancePolicy $policy, array $contractResult): void
    {
        StellarTransaction::create([
            'stellar_wallet_id' => $policy->stellar_wallet_id,
            'user_id' => $claim->user_id,
            'transaction_hash' => $contractResult['transaction_hash'],
            'operation_type' => 'invoke_host_function',
            'source_account' => $policy->stellarWallet->public_key,
            'status' => 'success',
            'successful' => true,
            'insurance_policy_id' => $policy->id,
            'claim_id' => $claim->id,
            'contract_id' => $policy->stellarContract->contract_id,
            'contract_method' => 'submit_claim',
            'metadata' => [
                'claim_submission' => true,
                'stellar_claim_id' => $claim->stellar_claim_id,
                'contract_result' => $contractResult,
            ],
        ]);
    }

    /**
     * Record payout transaction
     * 
     * @param Claim $claim The claim
     * @param array $paymentResult Payment result
     * @param array $contractResult Contract result
     */
    protected function recordPayoutTransaction(Claim $claim, array $paymentResult, array $contractResult): void
    {
        StellarTransaction::create([
            'stellar_wallet_id' => $claim->insurancePolicy->stellar_wallet_id,
            'user_id' => $claim->user_id,
            'transaction_hash' => $paymentResult['transaction_hash'],
            'operation_type' => 'payment',
            'source_account' => $paymentResult['source_account'],
            'destination_account' => $paymentResult['destination_account'],
            'asset_code' => 'XLM',
            'amount' => $paymentResult['amount'],
            'memo' => $paymentResult['memo'] ?? null,
            'memo_type' => 'text',
            'status' => 'success',
            'successful' => true,
            'insurance_policy_id' => $claim->insurance_policy_id,
            'claim_id' => $claim->id,
            'metadata' => [
                'claim_payout' => true,
                'automatic_payout' => true,
                'stellar_claim_id' => $claim->stellar_claim_id,
                'payment_result' => $paymentResult,
                'contract_result' => $contractResult,
            ],
        ]);
    }
}
