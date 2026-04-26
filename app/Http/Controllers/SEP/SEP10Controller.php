<?php

namespace App\Http\Controllers\SEP;

use App\Http\Controllers\Controller;
use App\Services\SEP\SEP10Service;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Exception;

/**
 * SEP-10: Web Authentication Controller
 * 
 * Handles challenge-response authentication using Stellar accounts.
 * Provides endpoints for generating challenges and validating signed responses.
 * 
 * @see https://github.com/stellar/stellar-protocol/blob/master/ecosystem/sep-0010.md
 */
class SEP10Controller extends Controller
{
    private SEP10Service $sep10Service;

    public function __construct(SEP10Service $sep10Service)
    {
        $this->sep10Service = $sep10Service;
    }

    /**
     * GET /api/sep/auth
     * 
     * Generate authentication challenge for a Stellar account.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getChallenge(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'account' => 'required|string|size:56',
                'home_domain' => 'nullable|string|max:255',
                'client_domain' => 'nullable|string|max:255',
                'memo' => 'nullable|string|max:28',
            ]);

            $challenge = $this->sep10Service->generateChallenge(
                $request->account,
                $request->home_domain,
                $request->client_domain,
                $request->memo
            );

            return response()->json($challenge);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'challenge_generation_failed',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * POST /api/sep/auth
     * 
     * Validate signed challenge and return JWT token.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function validateChallenge(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'challenge_id' => 'required|string',
                'transaction' => 'required|string',
            ]);

            $result = $this->sep10Service->validateChallengeAndGenerateToken(
                $request->challenge_id,
                $request->transaction
            );

            return response()->json($result);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'challenge_validation_failed',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * GET /api/sep/auth/info
     * 
     * Get authentication endpoint information.
     * 
     * @return JsonResponse
     */
    public function getAuthInfo(): JsonResponse
    {
        try {
            $info = $this->sep10Service->getAuthInfo();
            return response()->json($info);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'info_retrieval_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/sep/auth/validate
     * 
     * Validate an existing JWT token.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function validateToken(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'token' => 'required|string',
            ]);

            $validation = $this->sep10Service->validateToken($request->token);

            if ($validation['valid']) {
                return response()->json($validation);
            } else {
                return response()->json($validation, 401);
            }
        } catch (Exception $e) {
            return response()->json([
                'error' => 'token_validation_failed',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * POST /api/sep/auth/revoke
     * 
     * Revoke a JWT token.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function revokeToken(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'token' => 'required|string',
            ]);

            $success = $this->sep10Service->revokeToken($request->token);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Token revoked successfully',
                ]);
            } else {
                return response()->json([
                    'error' => 'token_revocation_failed',
                    'message' => 'Failed to revoke token',
                ], 400);
            }
        } catch (Exception $e) {
            return response()->json([
                'error' => 'token_revocation_failed',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Middleware to validate SEP-10 JWT token
     * 
     * @param Request $request
     * @return array|null Token validation result or null if invalid
     */
    public function validateSEPToken(Request $request): ?array
    {
        $authHeader = $request->header('Authorization');
        
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);
        $validation = $this->sep10Service->validateToken($token);

        return $validation['valid'] ? $validation : null;
    }
}
