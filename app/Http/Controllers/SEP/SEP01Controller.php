<?php

namespace App\Http\Controllers\SEP;

use App\Http\Controllers\Controller;
use App\Services\SEP\SEP01Service;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * SEP-1: stellar.toml Controller
 * 
 * Handles the stellar.toml endpoint for service discovery.
 * This endpoint is accessed by other Stellar services to discover your capabilities.
 * 
 * @see https://github.com/stellar/stellar-protocol/blob/master/ecosystem/sep-0001.md
 */
class SEP01Controller extends Controller
{
    private SEP01Service $sep01Service;

    public function __construct(SEP01Service $sep01Service)
    {
        $this->sep01Service = $sep01Service;
    }

    /**
     * GET /.well-known/stellar.toml
     * 
     * Returns the stellar.toml file for service discovery.
     * This endpoint should be publicly accessible without authentication.
     * 
     * @return Response
     */
    public function stellarToml(): Response
    {
        try {
            $tomlContent = $this->sep01Service->generateStellarToml();
            
            return response($tomlContent, 200, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'public, max-age=3600', // Cache for 1 hour
            ]);
        } catch (\Exception $e) {
            return response('Error generating stellar.toml', 500, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }
    }

    /**
     * GET /api/sep/toml/validate
     * 
     * Validates stellar.toml content (for internal use/debugging).
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function validateToml(Request $request)
    {
        $request->validate([
            'toml_content' => 'required|string',
        ]);

        try {
            $validation = $this->sep01Service->validateToml($request->toml_content);
            
            return response()->json([
                'success' => true,
                'validation' => $validation,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/sep/toml/info
     * 
     * Returns information about the stellar.toml configuration.
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function tomlInfo()
    {
        try {
            $tomlContent = $this->sep01Service->generateStellarToml();
            $validation = $this->sep01Service->validateToml($tomlContent);
            
            return response()->json([
                'success' => true,
                'info' => [
                    'version' => '2.5.0',
                    'last_updated' => now()->toISOString(),
                    'endpoints' => [
                        'stellar_toml' => url('/.well-known/stellar.toml'),
                        'web_auth' => url('/api/sep/auth'),
                        'transfer_server' => url('/api/sep/transfer'),
                        'interactive_server' => url('/api/sep/interactive'),
                        'kyc_server' => url('/api/sep/kyc'),
                        'direct_payment_server' => url('/api/sep/payments'),
                        'federation_server' => url('/api/sep/federation'),
                    ],
                    'validation' => $validation,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get TOML info',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
