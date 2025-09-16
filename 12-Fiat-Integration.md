# Fiat Integration System
## On/Off-Ramp Implementation with Paystack & Banking

---

## 📋 Table of Contents

1. [Integration Overview](#integration-overview)
2. [Paystack Integration](#paystack-integration)
3. [Banking System Integration](#banking-system-integration)
4. [Exchange Rate Management](#exchange-rate-management)
5. [KYC & Compliance](#kyc--compliance)
6. [Transaction Processing](#transaction-processing)
7. [Risk Management](#risk-management)

---

## 🌟 Integration Overview

### Fiat Integration Architecture
```
┌─────────────────────────────────────────────────────────────────┐
│                    Fiat Integration System                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐             │
│  │   On-Ramp   │  │  Off-Ramp   │  │  Exchange   │             │
│  │   (Deposit) │  │(Withdrawal) │  │    Rate     │             │
│  │             │  │             │  │ Management  │             │
│  │ • Paystack  │  │ • Bank      │  │ • Live      │             │
│  │ • Cards     │  │ • Transfer  │  │ • Multiple  │             │
│  │ • Bank      │  │ • Mobile    │  │ • Cached    │             │
│  └─────────────┘  └─────────────┘  └─────────────┘             │
│         │                 │                 │                  │
│         └─────────────────┼─────────────────┘                  │
│                           │                                    │
│  ┌─────────────────────────────────────────────────────────────┤
│  │              Compliance & Risk Management                   │
│  │                                                             │
│  │ • KYC Verification       • AML Screening                   │
│  │ • Transaction Limits     • Risk Assessment                 │
│  │ • Regulatory Reporting   • Fraud Detection                 │
│  └─────────────────────────────────────────────────────────────┘
│                           │                                    │
│  ┌─────────────────────────────────────────────────────────────┤
│  │                Banking Integration                          │
│  │                                                             │
│  │ • Account Verification   • Transfer Processing             │
│  │ • Bank Code Validation   • Settlement Management           │
│  │ • Real-time Transfers    • Reconciliation                  │
│  └─────────────────────────────────────────────────────────────┘
└─────────────────────────────────────────────────────────────────┘
```

### Supported Fiat Currencies
- **Primary**: Nigerian Naira (NGN)
- **Secondary**: US Dollar (USD), British Pound (GBP), Euro (EUR)
- **Crypto Assets**: XLM, USDC, BTC, ETH, USDT

### Integration Partners
- **Payment Processor**: Paystack
- **Banking**: Nigerian Inter-Bank Settlement System (NIBSS)
- **Exchange Rates**: Multiple providers (CoinGecko, CoinMarketCap, Binance)
- **Compliance**: Chainalysis, Elliptic

---

## 💳 Paystack Integration

### PaystackService Implementation
```php
class PaystackService
{
    protected $secretKey;
    protected $publicKey;
    protected $baseUrl;

    public function __construct()
    {
        $this->secretKey = config('services.paystack.secret_key');
        $this->publicKey = config('services.paystack.public_key');
        $this->baseUrl = config('services.paystack.base_url', 'https://api.paystack.co');
    }

    /**
     * Initialize payment for fiat deposit
     */
    public function initializePayment(array $paymentData): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/transaction/initialize', [
                'email' => $paymentData['email'],
                'amount' => $paymentData['amount'], // Amount in kobo
                'currency' => $paymentData['currency'] ?? 'NGN',
                'reference' => $paymentData['reference'],
                'callback_url' => $paymentData['callback_url'],
                'metadata' => $paymentData['metadata'] ?? [],
                'channels' => $paymentData['channels'] ?? ['card', 'bank', 'ussd', 'qr', 'mobile_money'],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                return [
                    'success' => true,
                    'data' => $data['data'],
                    'authorization_url' => $data['data']['authorization_url'],
                    'access_code' => $data['data']['access_code'],
                    'reference' => $data['data']['reference'],
                ];
            }

            return [
                'success' => false,
                'message' => $response->json()['message'] ?? 'Payment initialization failed',
                'error_code' => $response->status(),
            ];

        } catch (Exception $e) {
            Log::error('Paystack payment initialization failed', [
                'error' => $e->getMessage(),
                'payment_data' => $paymentData
            ]);

            return [
                'success' => false,
                'message' => 'Payment initialization failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify payment transaction
     */
    public function verifyTransaction(string $reference): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
            ])->get($this->baseUrl . "/transaction/verify/{$reference}");

            if ($response->successful()) {
                $data = $response->json();
                
                return [
                    'success' => true,
                    'data' => $data['data'],
                    'status' => $data['data']['status'],
                    'amount' => $data['data']['amount'],
                    'currency' => $data['data']['currency'],
                    'paid_at' => $data['data']['paid_at'],
                ];
            }

            return [
                'success' => false,
                'message' => $response->json()['message'] ?? 'Transaction verification failed',
            ];

        } catch (Exception $e) {
            Log::error('Paystack transaction verification failed', [
                'reference' => $reference,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Transaction verification failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create transfer recipient for withdrawals
     */
    public function createTransferRecipient(array $recipientData): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/transferrecipient', [
                'type' => $recipientData['type'], // 'nuban' for Nigerian banks
                'name' => $recipientData['name'],
                'account_number' => $recipientData['account_number'],
                'bank_code' => $recipientData['bank_code'],
                'currency' => $recipientData['currency'] ?? 'NGN',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                return [
                    'success' => true,
                    'data' => $data['data'],
                    'recipient_code' => $data['data']['recipient_code'],
                ];
            }

            return [
                'success' => false,
                'message' => $response->json()['message'] ?? 'Recipient creation failed',
            ];

        } catch (Exception $e) {
            Log::error('Paystack recipient creation failed', [
                'recipient_data' => $recipientData,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Recipient creation failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Initiate transfer for withdrawals
     */
    public function initiateTransfer(array $transferData): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/transfer', [
                'source' => 'balance',
                'amount' => $transferData['amount'], // Amount in kobo
                'recipient' => $transferData['recipient_code'],
                'reason' => $transferData['reason'] ?? 'Crypto withdrawal',
                'currency' => $transferData['currency'] ?? 'NGN',
                'reference' => $transferData['reference'],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                return [
                    'success' => true,
                    'data' => $data['data'],
                    'transfer_code' => $data['data']['transfer_code'],
                    'status' => $data['data']['status'],
                ];
            }

            return [
                'success' => false,
                'message' => $response->json()['message'] ?? 'Transfer initiation failed',
            ];

        } catch (Exception $e) {
            Log::error('Paystack transfer initiation failed', [
                'transfer_data' => $transferData,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Transfer initiation failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get list of supported banks
     */
    public function getBanks(string $country = 'nigeria'): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
            ])->get($this->baseUrl . '/bank', [
                'country' => $country,
                'use_cursor' => false,
                'perPage' => 100,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                return [
                    'success' => true,
                    'banks' => $data['data'],
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to fetch banks',
            ];

        } catch (Exception $e) {
            Log::error('Paystack banks fetch failed', [
                'country' => $country,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to fetch banks',
                'error' => $e->getMessage(),
            ];
        }
    }
}
```

### Webhook Handling
```php
class PaystackWebhookController extends Controller
{
    /**
     * Handle Paystack webhooks
     */
    public function handleWebhook(Request $request)
    {
        try {
            // Verify webhook signature
            $signature = $request->header('x-paystack-signature');
            $payload = $request->getContent();
            
            if (!$this->verifyWebhookSignature($payload, $signature)) {
                return response()->json(['error' => 'Invalid signature'], 400);
            }

            $event = $request->json()->all();
            
            // Process based on event type
            switch ($event['event']) {
                case 'charge.success':
                    $this->handleChargeSuccess($event['data']);
                    break;
                    
                case 'transfer.success':
                    $this->handleTransferSuccess($event['data']);
                    break;
                    
                case 'transfer.failed':
                    $this->handleTransferFailed($event['data']);
                    break;
                    
                case 'transfer.reversed':
                    $this->handleTransferReversed($event['data']);
                    break;
                    
                default:
                    Log::info('Unhandled Paystack webhook event', ['event' => $event['event']]);
            }

            return response()->json(['status' => 'success']);

        } catch (Exception $e) {
            Log::error('Paystack webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    /**
     * Handle successful charge (deposit)
     */
    protected function handleChargeSuccess(array $data): void
    {
        $reference = $data['reference'];
        $amount = $data['amount'] / 100; // Convert from kobo to naira
        
        // Find corresponding fiat onramp record
        $onramp = FiatOnramp::where('provider_reference', $reference)->first();
        
        if (!$onramp) {
            Log::warning('Paystack charge success for unknown reference', ['reference' => $reference]);
            return;
        }

        DB::transaction(function () use ($onramp, $data, $amount) {
            // Update onramp status
            $onramp->update([
                'status' => 'payment_confirmed',
                'fiat_received_at' => now(),
                'provider_response' => $data,
            ]);

            // Update transaction status
            $onramp->defiTransaction->update([
                'status' => 'payment_confirmed',
                'provider_confirmation' => $data,
            ]);

            // Process crypto purchase
            $this->processCryptoPurchase($onramp);
        });
    }

    /**
     * Process crypto purchase after fiat payment
     */
    protected function processCryptoPurchase(FiatOnramp $onramp): void
    {
        try {
            // Get current exchange rate
            $exchangeRate = app(ExchangeRateService::class)->getRate('NGN', $onramp->crypto_currency);
            
            // Calculate crypto amount
            $cryptoAmount = $onramp->fiat_amount / $exchangeRate;
            
            // Send crypto to user's wallet
            $stellarService = app(StellarService::class);
            $transferResult = $stellarService->sendPayment([
                'destination' => $onramp->defiWallet->stellar_address,
                'amount' => $cryptoAmount,
                'asset_code' => $onramp->crypto_currency,
                'memo' => "Deposit: {$onramp->id}",
            ]);

            if ($transferResult['success']) {
                $onramp->update([
                    'status' => 'completed',
                    'crypto_sent_at' => now(),
                    'crypto_transaction_hash' => $transferResult['transaction_hash'],
                    'final_crypto_amount' => $cryptoAmount,
                    'final_exchange_rate' => $exchangeRate,
                ]);

                $onramp->defiTransaction->update([
                    'status' => 'completed',
                    'stellar_transaction_hash' => $transferResult['transaction_hash'],
                ]);

                // Send notification
                $onramp->user->notify(new DepositCompletedNotification($onramp));
            } else {
                throw new Exception('Crypto transfer failed: ' . $transferResult['message']);
            }

        } catch (Exception $e) {
            Log::error('Crypto purchase processing failed', [
                'onramp_id' => $onramp->id,
                'error' => $e->getMessage()
            ]);

            $onramp->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
```

---

## 🏦 Banking System Integration

### Bank Account Verification
```php
class BankVerificationService
{
    /**
     * Verify bank account details
     */
    public function verifyBankAccount(string $accountNumber, string $bankCode): array
    {
        try {
            $paystackService = app(PaystackService::class);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.paystack.secret_key'),
            ])->get('https://api.paystack.co/bank/resolve', [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                return [
                    'success' => true,
                    'data' => [
                        'account_number' => $data['data']['account_number'],
                        'account_name' => $data['data']['account_name'],
                        'bank_id' => $data['data']['bank_id'],
                    ],
                ];
            }

            return [
                'success' => false,
                'message' => $response->json()['message'] ?? 'Account verification failed',
            ];

        } catch (Exception $e) {
            Log::error('Bank account verification failed', [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Account verification failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get bank name by code
     */
    public function getBankNameByCode(string $bankCode): ?string
    {
        $banks = Cache::remember('paystack_banks', 3600, function () {
            $paystackService = app(PaystackService::class);
            $result = $paystackService->getBanks();
            return $result['success'] ? $result['banks'] : [];
        });

        $bank = collect($banks)->firstWhere('code', $bankCode);
        return $bank['name'] ?? null;
    }

    /**
     * Validate Nigerian bank account number
     */
    public function validateAccountNumber(string $accountNumber): bool
    {
        // Nigerian account numbers are typically 10 digits
        return preg_match('/^\d{10}$/', $accountNumber);
    }

    /**
     * Validate bank code
     */
    public function validateBankCode(string $bankCode): bool
    {
        // Nigerian bank codes are typically 3 digits
        return preg_match('/^\d{3}$/', $bankCode);
    }
}
```

### NIBSS Integration (Future Enhancement)
```php
class NIBSSService
{
    /**
     * Process instant transfer via NIBSS
     */
    public function processInstantTransfer(array $transferData): array
    {
        try {
            // This would integrate with NIBSS Instant Payment (NIP) system
            // For now, we'll use Paystack as the primary processor
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.nibss.api_key'),
                'Content-Type' => 'application/json',
            ])->post(config('services.nibss.base_url') . '/transfer', [
                'amount' => $transferData['amount'],
                'beneficiary_account' => $transferData['account_number'],
                'beneficiary_bank' => $transferData['bank_code'],
                'narration' => $transferData['narration'],
                'reference' => $transferData['reference'],
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'message' => 'NIBSS transfer failed',
                'error' => $response->json(),
            ];

        } catch (Exception $e) {
            Log::error('NIBSS transfer failed', [
                'transfer_data' => $transferData,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Transfer processing failed',
                'error' => $e->getMessage(),
            ];
        }
    }
}
```

---

## 💱 Exchange Rate Management

### ExchangeRateService Implementation
```php
class ExchangeRateService
{
    protected $providers = [
        'coingecko' => CoinGeckoProvider::class,
        'coinmarketcap' => CoinMarketCapProvider::class,
        'binance' => BinanceProvider::class,
    ];

    /**
     * Get exchange rate with fallback providers
     */
    public function getRate(string $fromCurrency, string $toCurrency): float
    {
        $cacheKey = "exchange_rate_{$fromCurrency}_{$toCurrency}";
        
        // Try to get from cache first
        $cachedRate = Cache::get($cacheKey);
        if ($cachedRate) {
            return $cachedRate;
        }

        // Try each provider until we get a rate
        foreach ($this->providers as $providerName => $providerClass) {
            try {
                $provider = app($providerClass);
                $rate = $provider->getExchangeRate($fromCurrency, $toCurrency);
                
                if ($rate > 0) {
                    // Cache for 1 minute
                    Cache::put($cacheKey, $rate, 60);
                    
                    // Store rate history
                    $this->storeRateHistory($fromCurrency, $toCurrency, $rate, $providerName);
                    
                    return $rate;
                }
                
            } catch (Exception $e) {
                Log::warning("Exchange rate provider {$providerName} failed", [
                    'from' => $fromCurrency,
                    'to' => $toCurrency,
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        // If all providers fail, try to get last known rate
        $lastKnownRate = $this->getLastKnownRate($fromCurrency, $toCurrency);
        if ($lastKnownRate) {
            Log::warning('Using last known exchange rate', [
                'from' => $fromCurrency,
                'to' => $toCurrency,
                'rate' => $lastKnownRate
            ]);
            return $lastKnownRate;
        }

        throw new Exception("Unable to get exchange rate for {$fromCurrency} to {$toCurrency}");
    }

    /**
     * Store exchange rate history
     */
    protected function storeRateHistory(string $from, string $to, float $rate, string $provider): void
    {
        ExchangeRateHistory::create([
            'from_currency' => $from,
            'to_currency' => $to,
            'rate' => $rate,
            'provider' => $provider,
            'created_at' => now(),
        ]);
    }

    /**
     * Get last known rate from history
     */
    protected function getLastKnownRate(string $from, string $to): ?float
    {
        $lastRate = ExchangeRateHistory::where('from_currency', $from)
            ->where('to_currency', $to)
            ->where('created_at', '>', now()->subHours(24)) // Within last 24 hours
            ->orderBy('created_at', 'desc')
            ->first();

        return $lastRate ? $lastRate->rate : null;
    }

    /**
     * Convert amount between currencies
     */
    public function convert(float $amount, string $from, string $to): float
    {
        if ($from === $to) {
            return $amount;
        }

        $rate = $this->getRate($from, $to);
        return $amount * $rate;
    }

    /**
     * Get multiple rates at once
     */
    public function getMultipleRates(array $pairs): array
    {
        $rates = [];
        
        foreach ($pairs as $pair) {
            [$from, $to] = explode('/', $pair);
            try {
                $rates[$pair] = $this->getRate($from, $to);
            } catch (Exception $e) {
                $rates[$pair] = null;
                Log::error("Failed to get rate for {$pair}", ['error' => $e->getMessage()]);
            }
        }
        
        return $rates;
    }
}

class CoinGeckoProvider
{
    public function getExchangeRate(string $from, string $to): float
    {
        $fromId = $this->getCoinGeckoId($from);
        $toId = $this->getCoinGeckoId($to);
        
        $response = Http::get('https://api.coingecko.com/api/v3/simple/price', [
            'ids' => $fromId,
            'vs_currencies' => $toId,
        ]);
        
        if ($response->successful()) {
            $data = $response->json();
            return $data[$fromId][$toId] ?? 0;
        }
        
        throw new Exception('CoinGecko API request failed');
    }
    
    protected function getCoinGeckoId(string $currency): string
    {
        $mapping = [
            'XLM' => 'stellar',
            'BTC' => 'bitcoin',
            'ETH' => 'ethereum',
            'USDC' => 'usd-coin',
            'USDT' => 'tether',
            'NGN' => 'ngn',
            'USD' => 'usd',
        ];
        
        return $mapping[$currency] ?? strtolower($currency);
    }
}
```

---

## 🔒 KYC & Compliance

### KYC Verification for Fiat Operations
```php
class FiatComplianceService
{
    /**
     * Check if user can perform fiat operations
     */
    public function canPerformFiatOperations(User $user, float $amount): array
    {
        $kyc = $user->kyc;
        
        // Basic KYC required for any fiat operations
        if (!$kyc || !$kyc->isVerified()) {
            return [
                'allowed' => false,
                'reason' => 'KYC verification required',
                'required_level' => 'basic',
            ];
        }

        // Check transaction limits based on KYC level
        $limits = $this->getTransactionLimits($kyc->level);
        
        // Check daily limit
        $dailyTotal = $this->getDailyFiatVolume($user);
        if ($dailyTotal + $amount > $limits['daily']) {
            return [
                'allowed' => false,
                'reason' => 'Daily transaction limit exceeded',
                'current_daily' => $dailyTotal,
                'daily_limit' => $limits['daily'],
            ];
        }

        // Check monthly limit
        $monthlyTotal = $this->getMonthlyFiatVolume($user);
        if ($monthlyTotal + $amount > $limits['monthly']) {
            return [
                'allowed' => false,
                'reason' => 'Monthly transaction limit exceeded',
                'current_monthly' => $monthlyTotal,
                'monthly_limit' => $limits['monthly'],
            ];
        }

        return [
            'allowed' => true,
            'kyc_level' => $kyc->level,
            'remaining_daily' => $limits['daily'] - $dailyTotal,
            'remaining_monthly' => $limits['monthly'] - $monthlyTotal,
        ];
    }

    /**
     * Get transaction limits based on KYC level
     */
    protected function getTransactionLimits(string $kycLevel): array
    {
        $limits = [
            'basic' => [
                'daily' => 50000,    // ₦50,000
                'monthly' => 500000, // ₦500,000
            ],
            'enhanced' => [
                'daily' => 1000000,   // ₦1,000,000
                'monthly' => 10000000, // ₦10,000,000
            ],
            'premium' => [
                'daily' => 5000000,   // ₦5,000,000
                'monthly' => 50000000, // ₦50,000,000
            ],
        ];

        return $limits[$kycLevel] ?? $limits['basic'];
    }

    /**
     * Perform AML screening
     */
    public function performAMLScreening(User $user, array $transactionData): array
    {
        $riskScore = 0;
        $riskFactors = [];

        // Check user risk profile
        if ($user->risk_profile === 'high') {
            $riskScore += 30;
            $riskFactors[] = 'High-risk user profile';
        }

        // Check transaction amount
        if ($transactionData['amount'] > 1000000) { // ₦1M
            $riskScore += 20;
            $riskFactors[] = 'Large transaction amount';
        }

        // Check transaction frequency
        $recentTransactions = FiatOnramp::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        if ($recentTransactions > 10) {
            $riskScore += 25;
            $riskFactors[] = 'High transaction frequency';
        }

        // Check for suspicious patterns
        if ($this->detectSuspiciousPatterns($user, $transactionData)) {
            $riskScore += 40;
            $riskFactors[] = 'Suspicious transaction patterns detected';
        }

        $riskLevel = $riskScore >= 70 ? 'high' : ($riskScore >= 40 ? 'medium' : 'low');

        return [
            'risk_score' => $riskScore,
            'risk_level' => $riskLevel,
            'risk_factors' => $riskFactors,
            'requires_manual_review' => $riskLevel === 'high',
            'auto_approve' => $riskLevel === 'low' && $riskScore < 20,
        ];
    }
}
```

---

This comprehensive fiat integration system provides secure, compliant, and user-friendly on/off-ramp functionality, enabling seamless conversion between fiat currencies and cryptocurrencies while maintaining regulatory compliance and risk management.
