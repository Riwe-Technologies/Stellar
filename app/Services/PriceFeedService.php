<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PriceFeedService
{
    protected array $config;
    protected string $defaultProvider;
    protected int $cacheDuration;

    public function __construct()
    {
        $this->config = config('defi-tokens.price_feeds');
        $this->defaultProvider = $this->config['default_provider'];
        $this->cacheDuration = $this->config['cache_duration'];
    }

    /**
     * Get current price for a token in specified currency.
     */
    public function getPrice(string $symbol, string $currency = 'USD'): ?float
    {
        $cacheKey = "price_{$symbol}_{$currency}";
        
        return Cache::remember($cacheKey, $this->cacheDuration, function () use ($symbol, $currency) {
            try {
                return $this->fetchPrice($symbol, $currency);
            } catch (\Exception $e) {
                Log::error('Failed to fetch price', [
                    'symbol' => $symbol,
                    'currency' => $currency,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        });
    }

    /**
     * Get prices for multiple tokens.
     */
    public function getPrices(array $symbols, string $currency = 'USD'): array
    {
        $prices = [];
        
        foreach ($symbols as $symbol) {
            $prices[$symbol] = $this->getPrice($symbol, $currency);
        }
        
        return $prices;
    }

    /**
     * Get price with 24h change data.
     */
    public function getPriceWithChange(string $symbol, string $currency = 'USD'): array
    {
        $cacheKey = "price_change_{$symbol}_{$currency}";
        
        return Cache::remember($cacheKey, $this->cacheDuration, function () use ($symbol, $currency) {
            try {
                return $this->fetchPriceWithChange($symbol, $currency);
            } catch (\Exception $e) {
                Log::error('Failed to fetch price with change', [
                    'symbol' => $symbol,
                    'currency' => $currency,
                    'error' => $e->getMessage(),
                ]);
                return [
                    'price' => null,
                    'change_24h' => null,
                    'change_percentage_24h' => null,
                ];
            }
        });
    }

    /**
     * Convert amount from one token to another.
     */
    public function convertAmount(float $amount, string $fromSymbol, string $toSymbol, string $currency = 'USD'): ?float
    {
        $fromPrice = $this->getPrice($fromSymbol, $currency);
        $toPrice = $this->getPrice($toSymbol, $currency);
        
        if (!$fromPrice || !$toPrice) {
            return null;
        }
        
        $usdValue = $amount * $fromPrice;
        return $usdValue / $toPrice;
    }

    /**
     * Get portfolio value for multiple assets.
     */
    public function getPortfolioValue(array $assets, string $currency = 'USD'): array
    {
        $totalValue = 0;
        $assetValues = [];
        
        foreach ($assets as $symbol => $amount) {
            $price = $this->getPrice($symbol, $currency);
            $value = $price ? $amount * $price : 0;
            
            $assetValues[$symbol] = [
                'amount' => $amount,
                'price' => $price,
                'value' => $value,
            ];
            
            $totalValue += $value;
        }
        
        return [
            'total_value' => $totalValue,
            'currency' => $currency,
            'assets' => $assetValues,
            'updated_at' => now(),
        ];
    }

    /**
     * Fetch price from the configured provider.
     */
    protected function fetchPrice(string $symbol, string $currency): ?float
    {
        switch ($this->defaultProvider) {
            case 'coingecko':
                return $this->fetchFromCoinGecko($symbol, $currency);
            case 'coinmarketcap':
                return $this->fetchFromCoinMarketCap($symbol, $currency);
            default:
                throw new \Exception("Unsupported price provider: {$this->defaultProvider}");
        }
    }

    /**
     * Fetch price with change data from the configured provider.
     */
    protected function fetchPriceWithChange(string $symbol, string $currency): array
    {
        switch ($this->defaultProvider) {
            case 'coingecko':
                return $this->fetchFromCoinGeckoWithChange($symbol, $currency);
            case 'coinmarketcap':
                return $this->fetchFromCoinMarketCapWithChange($symbol, $currency);
            default:
                throw new \Exception("Unsupported price provider: {$this->defaultProvider}");
        }
    }

    /**
     * Fetch price from CoinGecko API.
     */
    protected function fetchFromCoinGecko(string $symbol, string $currency): ?float
    {
        $config = $this->config['providers']['coingecko'];
        $coinId = $this->getCoinGeckoId($symbol);
        
        if (!$coinId) {
            return null;
        }
        
        $headers = [];
        if (!empty($config['api_key'])) {
            $headers['x-cg-demo-api-key'] = $config['api_key'];
        }

        $response = Http::timeout(10)->withHeaders($headers)->get("{$config['api_url']}/simple/price", [
            'ids' => $coinId,
            'vs_currencies' => strtolower($currency),
        ]);
        
        if (!$response->successful()) {
            throw new \Exception("CoinGecko API error: {$response->status()}");
        }
        
        $data = $response->json();
        return $data[$coinId][strtolower($currency)] ?? null;
    }

    /**
     * Fetch price with change from CoinGecko API.
     */
    protected function fetchFromCoinGeckoWithChange(string $symbol, string $currency): array
    {
        $config = $this->config['providers']['coingecko'];
        $coinId = $this->getCoinGeckoId($symbol);
        
        if (!$coinId) {
            return ['price' => null, 'change_24h' => null, 'change_percentage_24h' => null];
        }
        
        $headers = [];
        if (!empty($config['api_key'])) {
            $headers['x-cg-demo-api-key'] = $config['api_key'];
        }

        $response = Http::timeout(10)->withHeaders($headers)->get("{$config['api_url']}/simple/price", [
            'ids' => $coinId,
            'vs_currencies' => strtolower($currency),
            'include_24hr_change' => 'true',
        ]);
        
        if (!$response->successful()) {
            throw new \Exception("CoinGecko API error: {$response->status()}");
        }
        
        $data = $response->json();
        $coinData = $data[$coinId] ?? [];
        
        return [
            'price' => $coinData[strtolower($currency)] ?? null,
            'change_24h' => $coinData[strtolower($currency) . '_24h_change'] ?? null,
            'change_percentage_24h' => $coinData[strtolower($currency) . '_24h_change'] ?? null,
        ];
    }

    /**
     * Get CoinGecko coin ID for a symbol.
     */
    protected function getCoinGeckoId(string $symbol): ?string
    {
        $mapping = [
            'BTC' => 'bitcoin',
            'ETH' => 'ethereum',
            'XLM' => 'stellar',
            'MATIC' => 'matic-network',
            'BNB' => 'binancecoin',
            'TRX' => 'tron',
            'USDT' => 'tether',
            'USDC' => 'usd-coin',
            'BUSD' => 'binance-usd',
            'WETH' => 'weth',
            'WBNB' => 'wbnb',
        ];
        
        return $mapping[strtoupper($symbol)] ?? null;
    }

    /**
     * Fetch price from CoinMarketCap API.
     */
    protected function fetchFromCoinMarketCap(string $symbol, string $currency): ?float
    {
        $config = $this->config['providers']['coinmarketcap'];
        
        $response = Http::timeout(10)
            ->withHeaders([
                'X-CMC_PRO_API_KEY' => $config['api_key'],
            ])
            ->get("{$config['api_url']}/cryptocurrency/quotes/latest", [
                'symbol' => strtoupper($symbol),
                'convert' => strtoupper($currency),
            ]);
        
        if (!$response->successful()) {
            throw new \Exception("CoinMarketCap API error: {$response->status()}");
        }
        
        $data = $response->json();
        return $data['data'][strtoupper($symbol)]['quote'][strtoupper($currency)]['price'] ?? null;
    }

    /**
     * Fetch price with change from CoinMarketCap API.
     */
    protected function fetchFromCoinMarketCapWithChange(string $symbol, string $currency): array
    {
        $config = $this->config['providers']['coinmarketcap'];
        
        $response = Http::timeout(10)
            ->withHeaders([
                'X-CMC_PRO_API_KEY' => $config['api_key'],
            ])
            ->get("{$config['api_url']}/cryptocurrency/quotes/latest", [
                'symbol' => strtoupper($symbol),
                'convert' => strtoupper($currency),
            ]);
        
        if (!$response->successful()) {
            throw new \Exception("CoinMarketCap API error: {$response->status()}");
        }
        
        $data = $response->json();
        $quote = $data['data'][strtoupper($symbol)]['quote'][strtoupper($currency)] ?? [];
        
        return [
            'price' => $quote['price'] ?? null,
            'change_24h' => $quote['percent_change_24h'] ?? null,
            'change_percentage_24h' => $quote['percent_change_24h'] ?? null,
        ];
    }

    /**
     * Clear price cache.
     */
    public function clearCache(): void
    {
        Cache::flush();
    }

    /**
     * Get supported currencies.
     */
    public function getSupportedCurrencies(): array
    {
        return $this->config['supported_currencies'];
    }

    /**
     * Check if a currency is supported.
     */
    public function isCurrencySupported(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->getSupportedCurrencies());
    }
}
