<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InsurancePolicy;
use App\Services\StellarPolicyService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Exception;

class StellarPolicyController extends Controller
{
    protected $stellarPolicyService;

    public function __construct(StellarPolicyService $stellarPolicyService)
    {
        $this->stellarPolicyService = $stellarPolicyService;
    }

    /**
     * Create a new policy on Stellar blockchain
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function createPolicy(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'farm_id' => 'required|exists:farms,id',
                'product_id' => 'required|exists:insurance_products,id',
                'premium_amount' => 'required|numeric|min:0.01',
                'coverage_amount' => 'required|numeric|min:0.01',
                'start_date' => 'required|date|after_or_equal:today',
                'end_date' => 'required|date|after:start_date',
                'parametric_triggers' => 'nullable|array',
                'policy_number' => 'nullable|string|unique:insurance_policies,policy_number',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = Auth::user();

            if (!$user->hasStellarEnabled()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stellar features not enabled for this user',
                ], 400);
            }

            // Prepare policy data
            $policyData = $request->all();
            $policyData['user_id'] = $user->id;
            $policyData['policy_number'] = $policyData['policy_number'] ?? InsurancePolicy::generatePolicyNumber();
            $policyData['status'] = 'pending_payment';
            $policyData['payment_status'] = 'pending';

            $result = $this->stellarPolicyService->createPolicyOnBlockchain($policyData);

            return response()->json([
                'success' => true,
                'message' => 'Policy created on blockchain successfully',
                'data' => $result,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create policy on blockchain',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Activate a policy on blockchain
     * 
     * @param Request $request
     * @param int $policyId
     * @return JsonResponse
     */
    public function activatePolicy(Request $request, int $policyId): JsonResponse
    {
        try {
            $user = Auth::user();
            $policy = InsurancePolicy::findOrFail($policyId);

            // Verify policy belongs to user
            // Use loose comparison to handle string/int type differences
            if ($policy->user_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Policy does not belong to authenticated user',
                ], 403);
            }

            if (!$policy->isOnStellar()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Policy is not on Stellar blockchain',
                ], 400);
            }

            $result = $this->stellarPolicyService->activatePolicyOnBlockchain($policy);

            return response()->json([
                'success' => true,
                'message' => 'Policy activated on blockchain successfully',
                'data' => $result,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate policy on blockchain',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a policy on blockchain
     * 
     * @param Request $request
     * @param int $policyId
     * @return JsonResponse
     */
    public function updatePolicy(Request $request, int $policyId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'coverage_amount' => 'nullable|numeric|min:0.01',
                'end_date' => 'nullable|date|after:start_date',
                'parametric_triggers' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = Auth::user();
            $policy = InsurancePolicy::findOrFail($policyId);

            // Verify policy belongs to user
            // Use loose comparison to handle string/int type differences
            if ($policy->user_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Policy does not belong to authenticated user',
                ], 403);
            }

            if (!$policy->isOnStellar()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Policy is not on Stellar blockchain',
                ], 400);
            }

            $updateData = array_filter($request->only([
                'coverage_amount',
                'end_date',
                'parametric_triggers'
            ]));

            if (empty($updateData)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid update data provided',
                ], 400);
            }

            $result = $this->stellarPolicyService->updatePolicyOnBlockchain($policy, $updateData);

            return response()->json([
                'success' => true,
                'message' => 'Policy updated on blockchain successfully',
                'data' => $result,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update policy on blockchain',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Suspend a policy on blockchain
     * 
     * @param Request $request
     * @param int $policyId
     * @return JsonResponse
     */
    public function suspendPolicy(Request $request, int $policyId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'reason' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = Auth::user();
            $policy = InsurancePolicy::findOrFail($policyId);

            // Verify policy belongs to user or user is admin
            // Use loose comparison to handle string/int type differences
            if ($policy->user_id != $user->id && !$user->hasRole('admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to suspend this policy',
                ], 403);
            }

            if (!$policy->isOnStellar()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Policy is not on Stellar blockchain',
                ], 400);
            }

            $result = $this->stellarPolicyService->suspendPolicyOnBlockchain($policy, $request->reason);

            return response()->json([
                'success' => true,
                'message' => 'Policy suspended on blockchain successfully',
                'data' => $result,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to suspend policy on blockchain',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get policy status from blockchain
     * 
     * @param int $policyId
     * @return JsonResponse
     */
    public function getPolicyStatus(int $policyId): JsonResponse
    {
        try {
            $user = Auth::user();
            $policy = InsurancePolicy::findOrFail($policyId);

            // Verify policy belongs to user or user is admin
            // Use loose comparison to handle string/int type differences
            if ($policy->user_id != $user->id && !$user->hasRole('admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this policy',
                ], 403);
            }

            if (!$policy->isOnStellar()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Policy is not on Stellar blockchain',
                ], 400);
            }

            $result = $this->stellarPolicyService->getPolicyStatusFromBlockchain($policy);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get policy status from blockchain',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user's policies on Stellar blockchain
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getUserPolicies(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            $policies = InsurancePolicy::where('user_id', $user->id)
                ->whereNotNull('stellar_contract_id')
                ->whereNotNull('stellar_policy_id')
                ->with(['stellarWallet', 'stellarContract', 'product', 'farm'])
                ->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            return response()->json([
                'success' => true,
                'policies' => $policies,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get user policies',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get policy details including blockchain information
     * 
     * @param int $policyId
     * @return JsonResponse
     */
    public function getPolicyDetails(int $policyId): JsonResponse
    {
        try {
            $user = Auth::user();
            $policy = InsurancePolicy::with([
                'stellarWallet',
                'stellarContract',
                'stellarTransactions',
                'product',
                'farm',
                'claims'
            ])->findOrFail($policyId);

            // Verify policy belongs to user or user is admin
            // Use loose comparison to handle string/int type differences
            if ($policy->user_id != $user->id && !$user->hasRole('admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this policy',
                ], 403);
            }

            $policyData = $policy->toArray();

            // Add blockchain status if policy is on Stellar
            if ($policy->isOnStellar()) {
                try {
                    $blockchainStatus = $this->stellarPolicyService->getPolicyStatusFromBlockchain($policy);
                    $policyData['blockchain_status'] = $blockchainStatus['blockchain_status'];
                    $policyData['blockchain_sync'] = $blockchainStatus['in_sync'];
                } catch (Exception $e) {
                    $policyData['blockchain_status'] = null;
                    $policyData['blockchain_sync'] = false;
                    $policyData['blockchain_error'] = $e->getMessage();
                }
            }

            return response()->json([
                'success' => true,
                'policy' => $policyData,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get policy details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync policy status between database and blockchain
     * 
     * @param int $policyId
     * @return JsonResponse
     */
    public function syncPolicyStatus(int $policyId): JsonResponse
    {
        try {
            $user = Auth::user();
            $policy = InsurancePolicy::findOrFail($policyId);

            // Verify policy belongs to user or user is admin
            // Use loose comparison to handle string/int type differences
            if ($policy->user_id != $user->id && !$user->hasRole('admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to sync this policy',
                ], 403);
            }

            if (!$policy->isOnStellar()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Policy is not on Stellar blockchain',
                ], 400);
            }

            $statusResult = $this->stellarPolicyService->getPolicyStatusFromBlockchain($policy);
            $blockchainStatus = $statusResult['blockchain_status'];

            // Update database status if different
            if ($blockchainStatus['status'] !== $policy->status) {
                $policy->update(['status' => $blockchainStatus['status']]);
                $policy->addStellarMetadata('last_sync', now());
                $policy->addStellarMetadata('synced_status', $blockchainStatus['status']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Policy status synchronized successfully',
                'data' => [
                    'previous_status' => $statusResult['database_status'],
                    'current_status' => $blockchainStatus['status'],
                    'was_synced' => $blockchainStatus['status'] !== $statusResult['database_status'],
                    'blockchain_status' => $blockchainStatus,
                ],
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync policy status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
