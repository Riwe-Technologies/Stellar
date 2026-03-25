<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StellarAssetRegistryService
{
    protected $popularAssets = [
        'USDC' => [
            'code' => 'USDC',
            'issuer' => 'GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN',
            'name' => 'USD Coin',
            'description' => 'Fully-backed USD stablecoin',
            'logo' => 'https://cryptologos.cc/logos/usd-coin-usdc-logo.png',
            'website' => 'https://www.centre.io/',
            'category' => 'stablecoin',
            'verified' => true,
            'popular' => true,
            'decimals' => 7,
            'default_limit' => '1000000',
        ],
        'AQUA' => [
            'code' => 'AQUA',
            'issuer' => 'GBNZILSTVQZ4R7IKQDGHYGY2QXL5QOFJYQMXPKWRRM5PAV7Y4M67AQUA',
            'name' => 'Aquarius',
            'description' => 'Aquarius network token for AMM and voting',
            'logo' => 'https://aqua.network/assets/img/aqua-logo.svg',
            'website' => 'https://aqua.network/',
            'category' => 'defi',
            'verified' => true,
            'popular' => true,
            'decimals' => 7,
            'default_limit' => '10000000',
        ],
        'yXLM' => [
            'code' => 'yXLM',
            'issuer' => 'GARDNV3Q7YGT4AKSDF25LT32YSCCW4EV22Y2TV3I2PU2MMXJTEDL5T55',
            'name' => 'Ultra Stellar',
            'description' => 'Yield-bearing XLM token',
            'logo' => 'https://ultrastellar.com/assets/yxlm-logo.png',
            'website' => 'https://ultrastellar.com/',
            'category' => 'defi',
            'verified' => true,
            'popular' => true,
            'decimals' => 7,
            'default_limit' => '1000000',
        ],
        'EURC' => [
            'code' => 'EURC',
            'issuer' => 'GDHU6WRG4IEQXM5NZ4BMPKOXHW76MZM4Y2IEMFDVXBSDP6SJY4ITNPP2',
            'name' => 'Euro Coin',
            'description' => 'Euro-backed stablecoin',
            'logo' => 'https://cryptologos.cc/logos/euro-coin-eurc-logo.png',
            'website' => 'https://www.centre.io/',
            'category' => 'stablecoin',
            'verified' => true,
            'popular' => true,
            'decimals' => 7,
            'default_limit' => '1000000',
        ],
        'ARST' => [
            'code' => 'ARST',
            'issuer' => 'GB7TAYRUZGE6TVT7NHP5SMIZRNQA6PLM423EYISAOAP3MKYIQMVYP2JO',
            'name' => 'ARST Token',
            'description' => 'Antalya Real Estate Token',
            'logo' => 'https://antalyarealestate.com/assets/arst-logo.png',
            'website' => 'https://antalyarealestate.com/',
            'category' => 'real_estate',
            'verified' => true,
            'popular' => false,
            'decimals' => 7,
            'default_limit' => '1000000',
        ],
        'MOBI' => [
            'code' => 'MOBI',
            'issuer' => 'GA6HCMBLTZS5VYYBCATRBRZ3BZJMAFUDKYYF6AH6MVCMGWMRDNSWJPIH',
            'name' => 'Mobius',
            'description' => 'Mobius network token',
            'logo' => 'https://mobius.network/assets/mobi-logo.png',
            'website' => 'https://mobius.network/',
            'category' => 'utility',
            'verified' => true,
            'popular' => false,
            'decimals' => 7,
            'default_limit' => '10000000',
        ],
        'SLT' => [
            'code' => 'SLT',
            'issuer' => 'GCKA6K5PCQ6PNF5RQBF7PQDJWRHO6UOGFMRLK3DYHDOI244V47XKQ4GP',
            'name' => 'Smartlands Token',
            'description' => 'Real estate tokenization platform',
            'logo' => 'https://smartlands.io/assets/slt-logo.png',
            'website' => 'https://smartlands.io/',
            'category' => 'real_estate',
            'verified' => true,
            'popular' => false,
            'decimals' => 7,
            'default_limit' => '1000000',
        ],
        'WXT' => [
            'code' => 'WXT',
            'issuer' => 'GASBLVHS5FOABSDNW5SPPH3QRJYXY5JHA2AOA2QHH2FJLZBRXSG4SWXT',
            'name' => 'Wirex Token',
            'description' => 'Wirex payment platform token',
            'logo' => 'https://wirexapp.com/assets/wxt-logo.png',
            'website' => 'https://wirexapp.com/',
            'category' => 'payment',
            'verified' => true,
            'popular' => false,
            'decimals' => 7,
            'default_limit' => '10000000',
        ],
    ];

    /**
     * Get all popular assets
     */
    public function getPopularAssets(): array
    {
        return array_filter($this->popularAssets, fn($asset) => $asset['popular']);
    }

    /**
     * Get all verified assets
     */
    public function getVerifiedAssets(): array
    {
        return array_filter($this->popularAssets, fn($asset) => $asset['verified']);
    }

    /**
     * Get assets by category
     */
    public function getAssetsByCategory(string $category): array
    {
        return array_filter($this->popularAssets, fn($asset) => $asset['category'] === $category);
    }

    /**
     * Get asset information by code and issuer
     */
    public function getAssetInfo(string $code, string $issuer): ?array
    {
        foreach ($this->popularAssets as $asset) {
            if ($asset['code'] === $code && $asset['issuer'] === $issuer) {
                return $asset;
            }
        }
        return null;
    }

    /**
     * Search assets by name or code
     */
    public function searchAssets(string $query): array
    {
        $query = strtolower($query);
        
        return array_filter($this->popularAssets, function($asset) use ($query) {
            return str_contains(strtolower($asset['name']), $query) ||
                   str_contains(strtolower($asset['code']), $query) ||
                   str_contains(strtolower($asset['description']), $query);
        });
    }

    /**
     * Get recommended assets for new users
     */
    public function getRecommendedAssets(): array
    {
        return [
            $this->popularAssets['USDC'],
            $this->popularAssets['AQUA'],
            $this->popularAssets['yXLM'],
            $this->popularAssets['EURC'],
        ];
    }

    /**
     * Get asset categories
     */
    public function getCategories(): array
    {
        return [
            'stablecoin' => [
                'name' => 'Stablecoins',
                'description' => 'Price-stable cryptocurrencies',
                'icon' => '💰'
            ],
            'defi' => [
                'name' => 'DeFi',
                'description' => 'Decentralized Finance tokens',
                'icon' => '🏦'
            ],
            'real_estate' => [
                'name' => 'Real Estate',
                'description' => 'Real estate tokenization',
                'icon' => '🏠'
            ],
            'utility' => [
                'name' => 'Utility',
                'description' => 'Platform utility tokens',
                'icon' => '🔧'
            ],
            'payment' => [
                'name' => 'Payment',
                'description' => 'Payment and remittance tokens',
                'icon' => '💳'
            ],
        ];
    }

    /**
     * Validate asset before adding trustline
     */
    public function validateAsset(string $code, string $issuer): array
    {
        // Check if it's a known asset
        $knownAsset = $this->getAssetInfo($code, $issuer);
        
        if ($knownAsset) {
            return [
                'valid' => true,
                'verified' => $knownAsset['verified'],
                'warning' => null,
                'asset_info' => $knownAsset
            ];
        }

        // For unknown assets, provide warnings
        $warnings = [];
        
        if (strlen($code) > 12) {
            $warnings[] = 'Asset code is longer than 12 characters';
        }
        
        if (strlen($issuer) !== 56) {
            $warnings[] = 'Invalid issuer address format';
        }

        return [
            'valid' => empty($warnings),
            'verified' => false,
            'warnings' => $warnings,
            'asset_info' => null
        ];
    }

    /**
     * Get asset price from external API (cached)
     */
    public function getAssetPrice(string $code, string $issuer): ?array
    {
        $cacheKey = "stellar_price_{$code}_{$issuer}";
        
        return Cache::remember($cacheKey, 300, function() use ($code, $issuer) {
            try {
                // Try StellarExpert API
                $response = Http::timeout(10)->get("https://api.stellar.expert/explorer/public/asset/{$code}-{$issuer}/market");
                
                if ($response->successful()) {
                    $data = $response->json();
                    return [
                        'price_usd' => $data['price']['USD'] ?? null,
                        'price_xlm' => $data['price']['XLM'] ?? null,
                        'volume_24h' => $data['volume24h'] ?? null,
                        'change_24h' => $data['change24h'] ?? null,
                        'source' => 'stellar.expert',
                        'updated_at' => now()
                    ];
                }
            } catch (\Exception $e) {
                Log::warning('Failed to fetch asset price', [
                    'asset_code' => $code,
                    'asset_issuer' => $issuer,
                    'error' => $e->getMessage()
                ]);
            }
            
            return null;
        });
    }

    /**
     * Get asset statistics
     */
    public function getAssetStats(string $code, string $issuer): ?array
    {
        $cacheKey = "stellar_stats_{$code}_{$issuer}";
        
        return Cache::remember($cacheKey, 600, function() use ($code, $issuer) {
            try {
                $response = Http::timeout(10)->get("https://api.stellar.expert/explorer/public/asset/{$code}-{$issuer}");
                
                if ($response->successful()) {
                    $data = $response->json();
                    return [
                        'supply' => $data['supply'] ?? null,
                        'accounts' => $data['accounts'] ?? null,
                        'payments' => $data['payments'] ?? null,
                        'trades' => $data['trades'] ?? null,
                        'created' => $data['created'] ?? null,
                        'source' => 'stellar.expert',
                        'updated_at' => now()
                    ];
                }
            } catch (\Exception $e) {
                Log::warning('Failed to fetch asset stats', [
                    'asset_code' => $code,
                    'asset_issuer' => $issuer,
                    'error' => $e->getMessage()
                ]);
            }
            
            return null;
        });
    }

    /**
     * Get all assets with their current prices
     */
    public function getAssetsWithPrices(): array
    {
        $assets = $this->popularAssets;
        
        foreach ($assets as $key => &$asset) {
            $price = $this->getAssetPrice($asset['code'], $asset['issuer']);
            $asset['price'] = $price;
        }
        
        return $assets;
    }
}
