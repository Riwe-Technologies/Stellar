# System Architecture
## Application, Wallet, Fiat, and Soroban Architecture

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Core Components](#core-components)
3. [Database Architecture](#database-architecture)
4. [Network Configuration](#network-configuration)
5. [Security Architecture](#security-architecture)
6. [Integration Patterns](#integration-patterns)

**Related docs:** [04-Soroban-Overview.md](./04-Soroban-Overview.md) · [05-Contract-Specifications.md](./05-Contract-Specifications.md) · [08-DeFi-Wallet-System.md](./08-DeFi-Wallet-System.md)

---

<a id="architecture-overview"></a>
## Architecture Overview

This document defines the system-level architecture used across the application documentation: Laravel backend orchestration, wallet infrastructure, fiat rails, application data services, and the live Soroban insurance contract suite on Stellar.

### High-Level System Design
```
┌─────────────────────────────────────────────────────────────────┐
│                   Client / Access Channels                     │
├─────────────────────────────────────────────────────────────────┤
│ Web App │ Mobile │ WhatsApp │ USSD │ External API Clients      │
├─────────────────────────────────────────────────────────────────┤
│ Application / Backend Layer                                    │
│ • Auth / policy APIs       • Controllers / middleware          │
│ • Oracle retrieval         • Claim / payout coordination       │
│ • Webhooks / queues        • Admin / operational tooling       │
├─────────────────────────────────────────────────────────────────┤
│ Service & Wallet Infrastructure Layer                          │
│ • StellarService              • StellarWalletService           │
│ • StellarSmartContractService • StellarClaimService            │
│ • DefiWalletService           • WalletPlusService              │
│ • CustodialAddressService     • MoneyGramRampsService          │
├─────────────────────────────────────────────────────────────────┤
│ Fiat Rails Layer                                               │
│ • Paystack NGN flows       • MoneyGram USDC cash ramps         │
│ • Provider references      • Webhook/status reconciliation     │
├─────────────────────────────────────────────────────────────────┤
│ Data Layer                                                     │
│ • PostgreSQL application state  • Redis queues / cache         │
│ • Wallet / policy / claim data  • Files / logs / analytics     │
├─────────────────────────────────────────────────────────────────┤
│ Stellar / Soroban Insurance Layer                              │
│ • insurance-policy          • insurance-claim                  │
│ • insurance-payment         • parametric-oracle                │
├─────────────────────────────────────────────────────────────────┤
│ Settlement and Exit Paths                                      │
│ • Stellar wallet settlement  • Hold / transfer / cash-out      │
└─────────────────────────────────────────────────────────────────┘
```

### Core Principles
- **Layered architecture first:** client channels, backend orchestration, wallet infrastructure, fiat rails, data services, and on-chain contracts are documented as separate concerns.
- **Backend-mediated oracle flow:** oracle data is stored in `parametric-oracle`, retrieved by the backend, and then supplied to `insurance-claim`.
- **Explicit contract boundaries:** `insurance-policy` manages policy state, `insurance-claim` evaluates claims, and `insurance-payment` executes payout settlement.
- **Wallet-first settlement:** claim proceeds settle to the user's Stellar wallet before optional transfer or fiat off-ramp.
- **Multi-network wallet support:** wallet services support multiple networks, while the insurance contract layer remains anchored on Stellar / Soroban.
- **Compliance and traceability:** KYC/AML, provider references, webhook tracking, and auditable transaction state are treated as first-class system concerns.

### Architecture Standards Alignment
- The live on-chain architecture is the 4-contract Soroban suite: `insurance-policy`, `insurance-claim`, `insurance-payment`, and `parametric-oracle`.
- The backend is the coordination boundary for oracle retrieval, claim processing, payout invocation, and wallet reconciliation.
- Paystack and MoneyGram are application-layer fiat integrations, not deployed smart contracts.
- This system architecture doc is aligned with `docs/04-Soroban-Overview.md`, `docs/05-Contract-Specifications.md`, and `docs/08-DeFi-Wallet-System.md`.

---

<a id="core-components"></a>
## Core Components

The service layer is organized around Stellar / Soroban integration, wallet infrastructure, fiat rails, and self-custodial security. The sections below list representative services used by the current application.

### 1. Stellar and Soroban Integration

#### StellarService (Core)
```php
class StellarService
{
    protected function initializeNetwork()
    protected function initializeSdk()

    public function createAccount(): array
    public function fundTestnetAccount(string $accountId): bool
    public function getAccountInfo(string $accountId): array
    public function sendPayment(...)
    public function createTrustline(...)
    public function getNetworkConfig(): array
}
```

#### StellarWalletService
```php
class StellarWalletService
{
    public function createWallet(User $user, bool $fundTestnet = true): StellarWallet
    public function getOrCreateWallet(User $user): StellarWallet
    public function activateWallet(StellarWallet $wallet, string $fundingAmount = null): bool
    public function sendPayment(...)
    public function createTrustline(...)
}
```

#### StellarSmartContractService
```php
class StellarSmartContractService
{
    public function invokeContract(string $contractId, string $method, array $params): array
    public function createPolicy(array $policyData): array
    public function submitClaim(array $claimData): array
    public function processParametricPayout(string $claimId, array $parametricData): array
}
```

The backend uses these services to interact with the live Soroban contract suite and to coordinate wallet settlement around claim and payout flows. Automatic claim settlement is further coordinated in `StellarClaimService`.

### 2. Wallet Infrastructure and Fiat Rails

#### DefiWalletService
```php
class DefiWalletService
{
    public function createOrGetWallet(User $user): DefiWallet
    public function syncWithStellarWallet(DefiWallet $wallet): array
    public function enableWallet(User $user): array
    public function getWalletBalance(DefiWallet $wallet): array
    public function initiateFiatDeposit(DefiWallet $wallet, array $depositData): array
    public function initiateFiatWithdrawal(DefiWallet $wallet, array $withdrawalData): array
    public function sendCrypto(DefiWallet $wallet, array $sendData): array
}
```

#### CustodialAddressService
```php
class CustodialAddressService
{
    public function generateAddressesForWallet(DefiWallet $wallet): array
    public function validateAddressFormat(string $address, string $network): bool
    public function getSupportedNetworks(): array
    public function regenerateAddressesForWallet(DefiWallet $wallet): array
}
```

#### MoneyGramRampsService
```php
class MoneyGramRampsService
{
    public function getInfo(): array
    public function initiateDeposit(User $user, float $amount, string $currency = 'USD'): array
    public function initiateWithdrawal(User $user, float $amount, string $currency = 'USD'): array
    public function getTransactionStatus(string $moneygramTransactionId): array
    public function handleWebhook(array $payload): bool
}
```

This layer provides custodial wallet services, multi-network address management, NGN fiat flows through Paystack, and USDC cash-in / cash-out flows through MoneyGram.

### 3. Wallet Plus (Self-Custodial)

#### WalletPlusService
```php
class WalletPlusService
{
    public function initializeWalletPlus(User $user, array $setupData): array
    public function authenticateDevice(User $user, array $authData): array
    public function prepareTransaction(User $user, array $transactionData): array
    public function recoverWallet(User $user, array $recoveryData): array
    public function signTransaction($user, $transactionData, $authData)
}
```

Wallet Plus complements the custodial wallet flows with device-bound authentication, recovery handling, and transaction signing for higher-assurance user-controlled operations.

---

<a id="database-architecture"></a>
## Database Architecture

### Core Tables

#### Users & Authentication
```sql
-- users table (existing)
users (
    id, email, phone, password_hash, email_verified_at,
    phone_verified_at, kyc_status, preferred_currency,
    created_at, updated_at
)

-- stellar_wallets table
stellar_wallets (
    id, user_id, public_key, encrypted_private_key,
    account_id, sequence_number, balance_xlm, balance_usd,
    is_active, is_funded, created_at, updated_at
)
```

#### DeFi Wallet System
```sql
-- defi_wallets table
defi_wallets (
    id, user_id, stellar_wallet_id, status, is_enabled,
    balance_usd, balance_ngn, balance_xlm, custom_asset_balances,
    stellar_address, bitcoin_address, ethereum_address,
    polygon_address, bsc_address, tron_address,
    kyc_level, daily_limit_fiat, monthly_limit_fiat,
    created_at, updated_at
)

-- defi_transactions table
defi_transactions (
    id, user_id, defi_wallet_id, stellar_transaction_id,
    transaction_hash, reference, type, status, amount,
    currency, amount_usd, exchange_rate, fees,
    metadata, created_at, updated_at
)

-- fiat_onramps table
fiat_onramps (
    id, user_id, defi_wallet_id, defi_transaction_id,
    provider, type, status, fiat_amount, fiat_currency,
    crypto_amount, crypto_currency, exchange_rate,
    bank_details, provider_reference, created_at, updated_at
)
```

#### Wallet Plus System
```sql
-- wallet_plus table
wallet_plus (
    id, user_id, public_key, device_id, device_fingerprint,
    pin_hash, biometric_enabled, mfa_enabled, mfa_secret,
    cloud_backup_enabled, encrypted_backup, backup_metadata,
    status, last_accessed_at, created_at, updated_at
)

-- wallet_plus_recovery table
wallet_plus_recovery (
    id, wallet_plus_id, recovery_email, recovery_phone,
    recovery_method, recovery_data, recovery_questions,
    created_at, updated_at
)
```

#### Smart Contracts & Insurance
```sql
-- stellar_smart_contracts table
stellar_smart_contracts (
    id, contract_id, contract_type, wasm_hash, deployer_id,
    network, status, metadata, deployed_at, created_at, updated_at
)

-- insurance_policies table (enhanced)
insurance_policies (
    id, user_id, farm_id, product_id, stellar_policy_id,
    premium_amount, coverage_amount, status,
    parametric_triggers, blockchain_data,
    created_at, updated_at
)

-- claims table (enhanced)
claims (
    id, policy_id, user_id, stellar_claim_id, claim_type,
    amount, status, trigger_data, oracle_data,
    blockchain_data, processed_at, created_at, updated_at
)
```

The `stellar_smart_contracts` records should map to the live Soroban suite and related deployment metadata rather than legacy generic contract categories.

### Indexing Strategy
```sql
-- Performance indexes
CREATE INDEX idx_defi_wallets_user_id ON defi_wallets(user_id);
CREATE INDEX idx_defi_transactions_wallet_id ON defi_transactions(defi_wallet_id);
CREATE INDEX idx_defi_transactions_status ON defi_transactions(status);
CREATE INDEX idx_fiat_onramps_provider_ref ON fiat_onramps(provider_reference);
CREATE INDEX idx_stellar_transactions_hash ON stellar_transactions(transaction_hash);

-- Composite indexes
CREATE INDEX idx_defi_transactions_user_status ON defi_transactions(user_id, status);
CREATE INDEX idx_policies_user_status ON insurance_policies(user_id, status);
```

---

<a id="network-configuration"></a>
## Network Configuration

### Stellar Network Setup
```php
// config/stellar.php
'networks' => [
    'testnet' => [
        'horizon_url' => 'https://horizon-testnet.stellar.org',
        'soroban_rpc_url' => 'https://soroban-testnet.stellar.org',
        'network_passphrase' => 'Test SDF Network ; September 2015',
        'friendbot_url' => 'https://friendbot.stellar.org',
    ],
    'mainnet' => [
        'horizon_url' => 'https://horizon.stellar.org',
        'soroban_rpc_url' => 'https://soroban-mainnet.stellar.org',
        'network_passphrase' => 'Public Global Stellar Network ; September 2015',
    ],
]
```

### Multi-Chain Configuration
```php
// config/defi-tokens.php
'networks' => [
    'stellar' => [
        'rpc_url' => 'https://horizon.stellar.org',
        'native_token' => 'XLM',
        'decimals' => 7,
        'tokens' => ['XLM', 'USDC']
    ],
    'ethereum' => [
        'rpc_url' => 'https://mainnet.infura.io/v3/{PROJECT_ID}',
        'native_token' => 'ETH',
        'decimals' => 18,
        'tokens' => ['ETH', 'USDT', 'USDC']
    ],
    // Additional networks...
]
```

Stellar remains the primary settlement network for policy, claim, and payout flows, while the additional configured networks are exposed through the wallet infrastructure layer for broader asset access.

---

<a id="security-architecture"></a>
## Security Architecture

### Multi-Layer Security
```
┌─────────────────────────────────────────────────────────────────┐
│                    Application Security                         │
├─────────────────────────────────────────────────────────────────┤
│  Authentication  │  Authorization  │  Input Validation         │
├─────────────────────────────────────────────────────────────────┤
│                    Transport Security                           │
├─────────────────────────────────────────────────────────────────┤
│  TLS/SSL  │  Certificate Pinning  │  API Rate Limiting         │
├─────────────────────────────────────────────────────────────────┤
│                     Data Security                               │
├─────────────────────────────────────────────────────────────────┤
│  Encryption at Rest  │  Key Management  │  Secure Storage       │
├─────────────────────────────────────────────────────────────────┤
│                   Blockchain Security                           │
├─────────────────────────────────────────────────────────────────┤
│  Multi-Sig  │  Hardware Security  │  Smart Contract Audits     │
└─────────────────────────────────────────────────────────────────┘
```

### Key Management
- **Custodial**: Encrypted private keys in database
- **Self-Custodial**: Device-bound key storage
- **Hardware**: HSM integration for critical operations
- **Recovery**: Multi-factor recovery mechanisms

---

<a id="integration-patterns"></a>
## Integration Patterns

### Event and Webhook Processing
```php
Route::post('/webhooks/paystack', [PaymentWebhookController::class, 'handlePaystack']);
Route::post('/moneygram/webhook', [MoneyGramController::class, 'webhook']);
```

These public provider integrations update transaction state and trigger downstream wallet, fiat, and settlement reconciliation inside the application layer.

### Queue and Command Processing
```php
php artisan insurance:process-parametric-claims

PaySettlementJob::dispatch($settlement->id);
```

Backend processing combines command-driven parametric evaluation, queued payout work, and application-layer contract invocation. Claim payout orchestration is handled in services such as `StellarClaimService`, while on-chain settlement uses `insurance-claim` and `insurance-payment`.

### API Integration
```php
Route::middleware(['auth:sanctum'])->prefix('stellar')->group(function () { ... });
Route::middleware(['auth:sanctum'])->prefix('defi-wallet')->group(function () { ... });
Route::prefix('api/moneygram')->middleware(['auth:sanctum'])->group(function () { ... });
```

The application is currently REST-first: Stellar operations, DeFi wallet actions, and MoneyGram APIs are exposed through explicit route groups, while wallet-plus flows are primarily handled through authenticated web routes.

---

This architecture provides a robust and consistent foundation across the backend, wallet, fiat, data, and Soroban layers. By standardizing on the live 4-contract Soroban suite, backend-mediated oracle flow, and wallet-first settlement model, the application documentation now reflects the same operational boundaries and system standards end to end.
