# System Architecture. 
## Complete Stellar Integration Architecture

---

## 📋 Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Core Components](#core-components)
3. [Service Layer Architecture](#service-layer-architecture)
4. [Database Architecture](#database-architecture)
5. [Network Configuration](#network-configuration)
6. [Security Architecture](#security-architecture)
7. [Integration Patterns](#integration-patterns)

---

## 🏗️ Architecture Overview

### High-Level System Design
```
┌─────────────────────────────────────────────────────────────────┐
│                    Client Applications                          │
├─────────────────────────────────────────────────────────────────┤
│  Web App  │  Mobile  │  WhatsApp  │  USSD  │  API Clients      │
├─────────────────────────────────────────────────────────────────┤
│                      API Gateway                               │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐             │
│  │    REST     │  │  GraphQL    │  │  WebSocket  │             │
│  │     API     │  │     API     │  │   Events    │             │
│  └─────────────┘  └─────────────┘  └─────────────┘             │
├─────────────────────────────────────────────────────────────────┤
│                   Application Layer                            │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐             │
│  │ Controllers │  │ Middleware  │  │   Events    │             │
│  │             │  │             │  │             │             │
│  │ • Auth      │  │ • Security  │  │ • Webhooks  │             │
│  │ • Wallet    │  │ • Rate Limit│  │ • Queues    │             │
│  │ • Insurance │  │ • Validation│  │ • Broadcast │             │
│  └─────────────┘  └─────────────┘  └─────────────┘             │
├─────────────────────────────────────────────────────────────────┤
│                    Service Layer                               │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐             │
│  │   Stellar   │  │ DeFi Wallet │  │ Wallet Plus │             │
│  │  Services   │  │  Services   │  │  Services   │             │
│  │             │  │             │  │             │             │
│  │ • Network   │  │ • Multi-Net │  │ • Self-Cust │             │
│  │ • Payments  │  │ • On/Off    │  │ • Device    │             │
│  │ • Contracts │  │ • KYC/AML   │  │ • Biometric │             │
│  │ • Security  │  │ • Exchange  │  │ • Recovery  │             │
│  └─────────────┘  └─────────────┘  └─────────────┘             │
├─────────────────────────────────────────────────────────────────┤
│                     Data Layer                                 │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐             │
│  │  PostgreSQL │  │    Redis    │  │   Storage   │             │
│  │             │  │             │  │             │             │
│  │ • Users     │  │ • Cache     │  │ • Files     │             │
│  │ • Wallets   │  │ • Sessions  │  │ • Backups   │             │
│  │ • Policies  │  │ • Queues    │  │ • Logs      │             │
│  │ • Claims    │  │ • Rates     │  │ • Analytics │             │
│  └─────────────┘  └─────────────┘  └─────────────┘             │
├─────────────────────────────────────────────────────────────────┤
│                  Blockchain Layer                              │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐             │
│  │   Stellar   │  │   Soroban   │  │ Multi-Chain │             │
│  │  Network    │  │ Contracts   │  │ Integration │             │
│  │             │  │             │  │             │             │
│  │ • Horizon   │  │ • Insurance │  │ • Bitcoin   │             │
│  │ • Payments  │  │ • Claims    │  │ • Ethereum  │             │
│  │ • Assets    │  │ • Oracles   │  │ • Polygon   │             │
│  │ • Anchors   │  │ • Governance│  │ • BSC/Tron  │             │
│  └─────────────┘  └─────────────┘  └─────────────┘             │
└─────────────────────────────────────────────────────────────────┘
```

### Core Principles
- **Microservices Architecture**: Modular, scalable service design
- **Event-Driven**: Asynchronous processing and real-time updates
- **Security-First**: Multi-layer security and encryption
- **Blockchain-Native**: Deep integration with Stellar ecosystem
- **Multi-Network**: Support for multiple blockchain networks
- **Compliance-Ready**: Built-in KYC/AML and regulatory features

---

## 🔧 Core Components

### 1. Stellar Integration Layer

#### StellarService (Core)
```php
class StellarService
{
    // Network management
    protected function initializeNetwork()
    protected function initializeSdk()
    
    // Account operations
    public function createAccount(): array
    public function getAccountDetails(string $accountId): array
    public function fundAccount(string $accountId, float $amount): array
    
    // Transaction operations
    public function sendPayment(array $params): array
    public function createTrustline(array $params): array
    public function getTransactionHistory(string $accountId): array
    
    // Asset operations
    public function createAsset(array $params): array
    public function getAssetDetails(string $assetCode, string $issuer): array
}
```

#### StellarWalletService
```php
class StellarWalletService
{
    // Wallet lifecycle
    public function createWallet(User $user): StellarWallet
    public function getOrCreateWallet(User $user): StellarWallet
    public function activateWallet(StellarWallet $wallet): array
    
    // Balance management
    public function getBalance(StellarWallet $wallet): array
    public function syncBalance(StellarWallet $wallet): array
    
    // Transaction processing
    public function processPayment(array $params): array
    public function processReceive(array $params): array
}
```

#### StellarSmartContractService
```php
class StellarSmartContractService
{
    // Contract deployment
    public function deployContract(string $wasmPath, array $params): array
    public function upgradeContract(string $contractId, string $wasmPath): array
    
    // Contract interaction
    public function invokeContract(string $contractId, string $method, array $params): array
    public function queryContract(string $contractId, string $method, array $params): array
    
    // Insurance operations
    public function createPolicy(array $policyData): array
    public function submitClaim(array $claimData): array
    public function processClaim(string $claimId): array
}
```

### 2. DeFi Wallet System

#### DefiWalletService
```php
class DefiWalletService
{
    // Wallet management
    public function createOrGetWallet(User $user): DefiWallet
    public function enableWallet(DefiWallet $wallet): array
    public function disableWallet(DefiWallet $wallet): array
    
    // Multi-network support
    public function getWalletBalance(DefiWallet $wallet): array
    public function syncAllBalances(DefiWallet $wallet): array
    
    // Fiat operations
    public function initiateDeposit(DefiWallet $wallet, array $data): array
    public function initiateWithdrawal(DefiWallet $wallet, array $data): array
    public function processOnramp(FiatOnramp $onramp): array
}
```

#### CustodialAddressService
```php
class CustodialAddressService
{
    // Address generation
    public function generateAddressesForWallet(DefiWallet $wallet): array
    public function regenerateAddressesForWallet(DefiWallet $wallet): array
    
    // Network support
    public function getSupportedNetworks(): array
    public function validateAddressFormat(string $address, string $network): bool
    
    // Address management
    public function getAddressForNetwork(DefiWallet $wallet, string $network): ?string
    public function updateWalletAddresses(DefiWallet $wallet, array $addresses): void
}
```

### 3. Wallet Plus (Self-Custodial)

#### WalletPlusService
```php
class WalletPlusService
{
    // Initialization
    public function initializeWalletPlus(User $user, array $setupData): array
    public function setupDeviceBinding(WalletPlus $wallet, array $deviceData): array
    
    // Authentication
    public function authenticateWithPin(WalletPlus $wallet, string $pin): array
    public function authenticateWithBiometric(WalletPlus $wallet, array $biometricData): array
    public function verifyMFA(WalletPlus $wallet, string $code): array
    
    // Recovery
    public function initiateRecovery(string $email, array $recoveryData): array
    public function completeRecovery(string $recoveryToken, array $newDeviceData): array
    
    // Cloud backup
    public function createCloudBackup(WalletPlus $wallet, string $password): array
    public function restoreFromCloudBackup(string $backupData, string $password): array
}
```

---

## 🗄️ Database Architecture

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

## 🌐 Network Configuration

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

---

## 🔒 Security Architecture

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

## 🔄 Integration Patterns

### Event-Driven Architecture
```php
// Blockchain events
Event::listen(StellarTransactionConfirmed::class, function ($event) {
    // Update wallet balances
    // Process insurance claims
    // Send notifications
});

// Webhook processing
Event::listen(PaystackWebhookReceived::class, function ($event) {
    // Process fiat onramp
    // Update transaction status
    // Trigger crypto delivery
});
```

### Queue Processing
```php
// Asynchronous operations
Queue::push(new ProcessParametricClaim($claimId));
Queue::push(new SyncWalletBalance($walletId));
Queue::push(new SendNotification($userId, $message));
```

### API Integration
```php
// RESTful API design
Route::group(['prefix' => 'api/v1'], function () {
    Route::post('/stellar/wallet/create', [StellarController::class, 'createWallet']);
    Route::post('/defi-wallet/deposit', [DefiWalletController::class, 'initiateDeposit']);
    Route::post('/wallet-plus/authenticate', [WalletPlusController::class, 'authenticate']);
});
```

---

This architecture provides a robust, scalable foundation for the complete Stellar integration system, supporting multiple wallet types, blockchain networks, and advanced security features while maintaining compliance and user experience standards.
