<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\InsurancePolicy;
use App\Services\StellarClaimService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Exception;

class StellarClaimController extends Controller
{
    protected $stellarClaimService;

    public function __construct(StellarClaimService $stellarClaimService)
    {
        $this->stellarClaimService = $stellarClaimService;
    }

    /**
     * Submit a claim to Stellar blockchain
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function submitClaim(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'insurance_policy_id' => 'required|exists:insurance_policies,id',
                'incident_type' => 'required|string|max:100',
                'incident_date' => 'required|date|before_or_equal:today',
                'amount_claimed' => 'required|numeric|min:0.01',
                'description' => 'required|string|max:1000',
                'evidence_files' => 'nullable|array',
                'evidence_files.*' => 'string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = Auth::user();
            $policy = InsurancePolicy::findOrFail($request->insurance_policy_id);

            // Verify policy belongs to user
            // Use loose comparison to handle string/int type differences
            if ($policy->user_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Policy does not belong to authenticated user',
                ], 403);
            }

            // Check if policy is on Stellar
            if (!$policy->isOnStellar()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Policy is not on Stellar blockchain',
                ], 400);
            }

            // Prepare claim data
            $claimData = $request->all();
            $claimData['user_id'] = $user->id;
            $claimData['farm_id'] = $policy->farm_id;
            $claimData['status'] = 'pending_review';

            $result = $this->stellarClaimService->submitClaimToBlockchain($claimData);

            return response()->json([
                'success' => true,
                'message' => 'Claim submitted to blockchain successfully',
                'data' => $result,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit claim to blockchain',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process automatic parametric claims
     * 
     * @return JsonResponse
     */
    public function processParametricClaims(): JsonResponse
    {
        try {
            // This endpoint should be restricted to admin users or system processes
            $user = Auth::user();
            if (!$user->hasRole('admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access',
                ], 403);
            }

            $stats = $this->stellarClaimService->processParametricClaims();

            return response()->json([
                'success' => true,
                'message' => 'Parametric claims processed successfully',
                'stats' => $stats,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process parametric claims',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process automatic payout for a claim
     * 
     * @param Request $request
     * @param int $claimId
     * @return JsonResponse
     */
    public function processAutomaticPayout(Request $request, int $claimId): JsonResponse
    {
        try {
            $user = Auth::user();
            $claim = Claim::findOrFail($claimId);

            // Verify user has permission (claim owner or admin)
            // Use loose comparison to handle string/int type differences
            if ($claim->user_id != $user->id && !$user->hasRole('admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to process payout for this claim',
                ], 403);
            }

            if (!$claim->isOnStellar()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Claim is not on Stellar blockchain',
                ], 400);
            }

            if (!$claim->is_parametric) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only parametric claims can be automatically processed',
                ], 400);
            }

            $environmentalData = [
                'weather' => $claim->weather_data ?? [],
                'satellite' => $claim->satellite_data ?? [],
                'location' => [
                    'latitude' => $claim->farm?->latitude,
                    'longitude' => $claim->farm?->longitude,
                ],
            ];

            $result = $this->stellarClaimService->processAutomaticPayout($claim, $environmentalData);

            return response()->json([
                'success' => true,
                'message' => 'Automatic payout processed successfully',
                'data' => $result,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process automatic payout',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get claim status from blockchain
     * 
     * @param int $claimId
     * @return JsonResponse
     */
    public function getClaimStatus(int $claimId): JsonResponse
    {
        try {
            $user = Auth::user();
            $claim = Claim::findOrFail($claimId);

            // Verify user has permission (claim owner or admin)
            // Use loose comparison to handle string/int type differences
            if ($claim->user_id != $user->id && !$user->hasRole('admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this claim',
                ], 403);
            }

            if (!$claim->isOnStellar()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Claim is not on Stellar blockchain',
                ], 400);
            }

            $result = $this->stellarClaimService->getClaimStatusFromBlockchain($claim);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get claim status from blockchain',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user's claims on Stellar blockchain
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getUserClaims(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            $claims = Claim::where('user_id', $user->id)
                ->whereNotNull('stellar_contract_id')
                ->whereNotNull('stellar_claim_id')
                ->with(['insurancePolicy', 'stellarContract', 'stellarTransactions'])
                ->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            return response()->json([
                'success' => true,
                'claims' => $claims,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get user claims',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get claim details including blockchain information
     * 
     * @param int $claimId
     * @return JsonResponse
     */
    public function getClaimDetails(int $claimId): JsonResponse
    {
        try {
            $user = Auth::user();
            $claim = Claim::with([
                'insurancePolicy.stellarWallet',
                'stellarContract',
                'stellarTransactions',
                'farm'
            ])->findOrFail($claimId);

            // Verify user has permission (claim owner or admin)
            // Use loose comparison to handle string/int type differences
            if ($claim->user_id != $user->id && !$user->hasRole('admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this claim',
                ], 403);
            }

            $claimData = $claim->toArray();

            // Add blockchain status if claim is on Stellar
            if ($claim->isOnStellar()) {
                try {
                    $blockchainStatus = $this->stellarClaimService->getClaimStatusFromBlockchain($claim);
                    $claimData['blockchain_status'] = $blockchainStatus['blockchain_status'];
                    $claimData['blockchain_sync'] = $blockchainStatus['in_sync'];
                } catch (Exception $e) {
                    $claimData['blockchain_status'] = null;
                    $claimData['blockchain_sync'] = false;
                    $claimData['blockchain_error'] = $e->getMessage();
                }
            }

            return response()->json([
                'success' => true,
                'claim' => $claimData,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get claim details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get parametric claims statistics
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getParametricStats(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            // Get user's parametric claims
            $query = Claim::where('user_id', $user->id)
                ->where('is_parametric', true)
                ->whereNotNull('stellar_claim_id');

            // Apply date filter if provided
            if ($request->has('start_date')) {
                $query->where('created_at', '>=', $request->start_date);
            }
            if ($request->has('end_date')) {
                $query->where('created_at', '<=', $request->end_date);
            }

            $claims = $query->get();

            $stats = [
                'total_claims' => $claims->count(),
                'approved_claims' => $claims->where('status', 'approved')->count(),
                'pending_claims' => $claims->where('status', 'pending_review')->count(),
                'rejected_claims' => $claims->where('status', 'rejected')->count(),
                'total_claimed' => $claims->sum('amount_claimed'),
                'total_approved' => $claims->where('status', 'approved')->sum('amount_approved'),
                'automatic_payouts' => $claims->filter(function($claim) {
                    return $claim->getStellarMetadata('automatic_payout', false);
                })->count(),
                'avg_processing_time' => $this->calculateAverageProcessingTime($claims),
            ];

            return response()->json([
                'success' => true,
                'stats' => $stats,
                'claims' => $claims->take(10), // Return latest 10 claims
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get parametric stats',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate average processing time for claims
     * 
     * @param $claims Collection of claims
     * @return float Average processing time in hours
     */
    protected function calculateAverageProcessingTime($claims): float
    {
        $processedClaims = $claims->whereIn('status', ['approved', 'rejected']);
        
        if ($processedClaims->isEmpty()) {
            return 0;
        }

        $totalHours = 0;
        $count = 0;

        foreach ($processedClaims as $claim) {
            $processedAt = $claim->approved_at ?? $claim->rejected_at;
            if ($processedAt) {
                $hours = $claim->created_at->diffInHours($processedAt);
                $totalHours += $hours;
                $count++;
            }
        }

        return $count > 0 ? round($totalHours / $count, 2) : 0;
    }
}
