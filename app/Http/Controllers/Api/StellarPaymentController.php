<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InsurancePolicy;
use App\Services\StellarPaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Exception;

class StellarPaymentController extends Controller
{
    protected $stellarPaymentService;

    public function __construct(StellarPaymentService $stellarPaymentService)
    {
        $this->stellarPaymentService = $stellarPaymentService;
    }

    /**
     * Get payment quote for premium payment
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getQuote(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:0.01',
                'currency' => 'required|string|in:USD,XLM',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $amount = $request->amount;
            $currency = $request->currency;

            if ($currency === 'USD') {
                $quote = $this->stellarPaymentService->getPaymentQuote($amount);
            } else {
                // If already in XLM, return as-is
                $quote = [
                    'usd_amount' => null,
                    'xlm_amount' => $amount,
                    'exchange_rate' => null,
                    'fee_xlm' => '0.00001',
                    'total_xlm' => number_format(floatval($amount) + 0.00001, 7),
                    'expires_at' => now()->addMinutes(5),
                ];
            }

            return response()->json([
                'success' => true,
                'quote' => $quote,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get payment quote',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process premium payment using Stellar
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function processPremiumPayment(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'policy_id' => 'required|exists:insurance_policies,id',
                'amount' => 'required|numeric|min:0.01',
                'asset_code' => 'required|string|max:12',
                'asset_issuer' => 'nullable|string|max:56',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = Auth::user();
            $policy = InsurancePolicy::findOrFail($request->policy_id);

            // Verify policy belongs to user
            // Use loose comparison to handle string/int type differences
            if ($policy->user_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Policy does not belong to authenticated user',
                ], 403);
            }

            // Check if user has Stellar enabled
            if (!$user->hasStellarEnabled()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stellar payments not enabled for this user',
                ], 400);
            }

            $result = $this->stellarPaymentService->processPremiumPayment(
                $policy,
                $user,
                $request->amount,
                $request->asset_code,
                $request->asset_issuer
            );

            return response()->json([
                'success' => true,
                'message' => 'Premium payment processed successfully',
                'data' => $result,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process premium payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create trustline for insurance token
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function createTrustline(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user->hasStellarEnabled()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stellar features not enabled for this user',
                ], 400);
            }

            $result = $this->stellarPaymentService->createInsuranceTokenTrustline($user);

            return response()->json([
                'success' => true,
                'message' => 'Trustline created successfully',
                'data' => $result,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create trustline',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get supported payment assets
     * 
     * @return JsonResponse
     */
    public function getSupportedAssets(): JsonResponse
    {
        try {
            $assets = $this->stellarPaymentService->getSupportedAssets();

            return response()->json([
                'success' => true,
                'assets' => $assets,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get supported assets',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user's Stellar wallet balance
     * 
     * @return JsonResponse
     */
    public function getWalletBalance(): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user->hasStellarEnabled()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stellar features not enabled for this user',
                ], 400);
            }

            if (!$user->hasStellarWallet()) {
                return response()->json([
                    'success' => false,
                    'message' => 'User does not have a Stellar wallet',
                ], 400);
            }

            $balances = $user->getStellarBalance();

            return response()->json([
                'success' => true,
                'balances' => $balances,
                'wallet' => [
                    'public_key' => $user->stellarWallet->public_key,
                    'network' => $user->stellarWallet->network,
                    'status' => $user->stellarWallet->status,
                ],
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get wallet balance',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Stellar transaction history for user
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getTransactionHistory(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user->hasStellarWallet()) {
                return response()->json([
                    'success' => false,
                    'message' => 'User does not have a Stellar wallet',
                ], 400);
            }

            $limit = $request->get('limit', 50);
            $limit = min($limit, 100); // Max 100 transactions

            $transactions = $user->stellarTransactions()
                ->with(['insurancePolicy', 'claim'])
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'transactions' => $transactions,
                'count' => $transactions->count(),
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get transaction history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Enable Stellar features for user
     * 
     * @return JsonResponse
     */
    public function enableStellar(): JsonResponse
    {
        try {
            $user = Auth::user();

            if ($user->hasStellarEnabled()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stellar features already enabled',
                ], 400);
            }

            $user->enableStellar();

            // Create wallet if auto-creation is enabled
            $wallet = $user->getOrCreateStellarWallet();

            return response()->json([
                'success' => true,
                'message' => 'Stellar features enabled successfully',
                'wallet_created' => !is_null($wallet),
                'wallet' => $wallet ? [
                    'public_key' => $wallet->public_key,
                    'network' => $wallet->network,
                    'status' => $wallet->status,
                ] : null,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to enable Stellar features',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Disable Stellar features for user
     * 
     * @return JsonResponse
     */
    public function disableStellar(): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user->hasStellarEnabled()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stellar features not enabled',
                ], 400);
            }

            $user->disableStellar();

            return response()->json([
                'success' => true,
                'message' => 'Stellar features disabled successfully',
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to disable Stellar features',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
