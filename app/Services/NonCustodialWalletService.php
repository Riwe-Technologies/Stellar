<?php

namespace App\Services;

use App\Models\User;
use App\Models\StellarWallet;
use App\Models\NonCustodialWallet;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class NonCustodialWalletService
{
    protected $stellarService;
    protected $sep10Service;

    public function __construct(
        StellarService $stellarService,
        SEP\SEP10Service $sep10Service
    ) {
        $this->stellarService = $stellarService;
        $this->sep10Service = $sep10Service;
    }

    /**
     * Connect external wallet via SEP-10 authentication
     */
    public function connectExternalWallet(User $user, string $publicKey, string $signedChallenge): array
    {
        try {
            DB::beginTransaction();

            // Validate the signed challenge via SEP-10
            $authResult = $this->sep10Service->validateChallengeAndGenerateToken(
                $signedChallenge,
                $publicKey
            );

            if (!$authResult['valid']) {
                throw new Exception('Invalid wallet authentication');
            }

            // Check if wallet is already connected
            $existingWallet = NonCustodialWallet::where('public_key', $publicKey)->first();
            // Use loose comparison to handle string/int type differences
            if ($existingWallet && $existingWallet->user_id != $user->id) {
                throw new Exception('Wallet is already connected to another account');
            }

            // Create or update non-custodial wallet record
            $wallet = NonCustodialWallet::updateOrCreate(
                ['public_key' => $publicKey],
                [
                    'user_id' => $user->id,
                    'wallet_type' => 'external',
                    'connection_method' => 'sep10',
                    'last_authenticated_at' => now(),
                    'auth_token' => $authResult['token'],
                    'status' => 'connected',
                    'metadata' => [
                        'connected_at' => now()->toISOString(),
                        'user_agent' => request()->userAgent(),
                        'ip_address' => request()->ip(),
                    ]
                ]
            );

            // Verify account exists on Stellar network
            $accountInfo = $this->stellarService->getAccountInfo($publicKey);
            if ($accountInfo) {
                $wallet->update([
                    'is_funded' => true,
                    'balance_xlm' => $accountInfo['balance_xlm'] ?? 0,
                    'last_sync_at' => now(),
                ]);
            }

            DB::commit();

            Log::info('External wallet connected', [
                'user_id' => $user->id,
                'public_key' => $publicKey,
                'wallet_id' => $wallet->id,
            ]);

            return [
                'success' => true,
                'wallet' => $wallet,
                'message' => 'Wallet connected successfully'
            ];

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to connect external wallet', [
                'user_id' => $user->id,
                'public_key' => $publicKey,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate wallet connection challenge
     */
    public function generateConnectionChallenge(string $publicKey): array
    {
        try {
            $challenge = $this->sep10Service->generateChallenge(
                $publicKey,
                config('app.url'),
                null,
                'Connect wallet to ' . config('app.name')
            );

            return [
                'success' => true,
                'challenge' => $challenge,
                'instructions' => [
                    'Sign the challenge transaction with your wallet',
                    'Submit the signed transaction to complete connection',
                    'Your private keys never leave your device'
                ]
            ];

        } catch (Exception $e) {
            Log::error('Failed to generate connection challenge', [
                'public_key' => $publicKey,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to generate challenge: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Disconnect external wallet
     */
    public function disconnectWallet(User $user, string $publicKey): array
    {
        try {
            $wallet = NonCustodialWallet::where('user_id', $user->id)
                ->where('public_key', $publicKey)
                ->first();

            if (!$wallet) {
                return [
                    'success' => false,
                    'message' => 'Wallet not found'
                ];
            }

            $wallet->update([
                'status' => 'disconnected',
                'disconnected_at' => now(),
                'auth_token' => null,
            ]);

            Log::info('External wallet disconnected', [
                'user_id' => $user->id,
                'public_key' => $publicKey,
                'wallet_id' => $wallet->id,
            ]);

            return [
                'success' => true,
                'message' => 'Wallet disconnected successfully'
            ];

        } catch (Exception $e) {
            Log::error('Failed to disconnect wallet', [
                'user_id' => $user->id,
                'public_key' => $publicKey,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to disconnect wallet'
            ];
        }
    }

    /**
     * Get user's connected wallets
     */
    public function getUserWallets(User $user): array
    {
        try {
            $custodialWallets = StellarWallet::where('user_id', $user->id)
                ->where('status', 'active')
                ->get()
                ->map(function ($wallet) {
                    return [
                        'id' => $wallet->id,
                        'type' => 'custodial',
                        'public_key' => $wallet->public_key,
                        'balance_xlm' => $wallet->balance_xlm,
                        'network' => $wallet->network,
                        'created_at' => $wallet->created_at,
                        'is_primary' => true,
                    ];
                });

            $nonCustodialWallets = NonCustodialWallet::where('user_id', $user->id)
                ->where('status', 'connected')
                ->get()
                ->map(function ($wallet) {
                    return [
                        'id' => $wallet->id,
                        'type' => 'non_custodial',
                        'public_key' => $wallet->public_key,
                        'balance_xlm' => $wallet->balance_xlm,
                        'connection_method' => $wallet->connection_method,
                        'last_authenticated_at' => $wallet->last_authenticated_at,
                        'is_primary' => false,
                    ];
                });

            return [
                'success' => true,
                'wallets' => [
                    'custodial' => $custodialWallets,
                    'non_custodial' => $nonCustodialWallets,
                    'total' => $custodialWallets->count() + $nonCustodialWallets->count(),
                ]
            ];

        } catch (Exception $e) {
            Log::error('Failed to get user wallets', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to retrieve wallets'
            ];
        }
    }

    /**
     * Sync non-custodial wallet balances
     */
    public function syncWalletBalances(User $user): array
    {
        try {
            $wallets = NonCustodialWallet::where('user_id', $user->id)
                ->where('status', 'connected')
                ->get();

            $syncResults = [];

            foreach ($wallets as $wallet) {
                try {
                    $accountInfo = $this->stellarService->getAccountInfo($wallet->public_key);
                    
                    if ($accountInfo) {
                        $wallet->update([
                            'balance_xlm' => $accountInfo['balance_xlm'] ?? 0,
                            'last_sync_at' => now(),
                        ]);

                        $syncResults[] = [
                            'wallet_id' => $wallet->id,
                            'public_key' => $wallet->public_key,
                            'balance' => $accountInfo['balance_xlm'] ?? 0,
                            'synced' => true,
                        ];
                    }
                } catch (Exception $e) {
                    $syncResults[] = [
                        'wallet_id' => $wallet->id,
                        'public_key' => $wallet->public_key,
                        'synced' => false,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return [
                'success' => true,
                'sync_results' => $syncResults,
                'synced_count' => count(array_filter($syncResults, fn($r) => $r['synced'])),
                'total_count' => count($syncResults),
            ];

        } catch (Exception $e) {
            Log::error('Failed to sync wallet balances', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to sync balances'
            ];
        }
    }

    /**
     * Get wallet security recommendations
     */
    public function getSecurityRecommendations(User $user): array
    {
        $recommendations = [];

        // Check if user has both custodial and non-custodial wallets
        $custodialCount = StellarWallet::where('user_id', $user->id)->count();
        $nonCustodialCount = NonCustodialWallet::where('user_id', $user->id)
            ->where('status', 'connected')->count();

        if ($custodialCount > 0 && $nonCustodialCount === 0) {
            $recommendations[] = [
                'type' => 'security',
                'level' => 'medium',
                'title' => 'Consider connecting an external wallet',
                'description' => 'For enhanced security, connect a hardware wallet or external Stellar wallet',
                'action' => 'Connect external wallet'
            ];
        }

        if ($nonCustodialCount > 0) {
            $recommendations[] = [
                'type' => 'security',
                'level' => 'info',
                'title' => 'External wallet connected',
                'description' => 'Your external wallet provides enhanced security as you control the private keys',
                'action' => null
            ];
        }

        return $recommendations;
    }
}
