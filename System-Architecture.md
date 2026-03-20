# System Architecture
## Application, Wallet, Fiat, and Soroban Architecture — Riwe Technologies

**Related documentation:** [Soroban Smart Contracts Overview ←](./Soroban-Smart-Contracts-Overview.md) · [Contract Specifications](./Contract-Specifications.md) · [DeFi Wallet and MoneyGram Claims Payout](./DeFi-and-Moneygram-Claims-Payout.md)

---

## Table of Contents

1. [System Purpose](#system-purpose)
2. [Architecture Overview](#architecture-overview)
3. [Complete End-to-End Flow](#complete-end-to-end-flow)
4. [Core Components](#core-components)
5. [Stellar Ecosystem Integration](#stellar-ecosystem-integration)
6. [Database Architecture](#database-architecture)
7. [Network Configuration](#network-configuration)
8. [Security Architecture](#security-architecture)
9. [Integration Patterns](#integration-patterns)
10. [Build Readiness and Live Status](#build-readiness-and-live-status)

---

## System Purpose

Riwe is a parametric climate insurance protocol for smallholder farmers in Africa. The system architecture described here enables one specific outcome: **a farmer who experiences a drought or flood receives a verified cash payout within 48 hours, without filing a claim, visiting a bank, or speaking to an adjuster.**

Every architectural decision — the Soroban contract suite, the Acurast oracle pipeline, the MoneyGram SEP-24 integration (T2), the Laravel backend — exists in service of that outcome.

This document maps the full system stack: from the USSD channel a farmer uses to buy a policy, through the satellite data pipeline that detects a climate event, through the Soroban contracts that settle the payout, to the MoneyGram agent point where the farmer will collect cash once the SEP-24 integration is live in T2.

---

## Architecture Overview

### Full System Stack

```
┌─────────────────────────────────────────────────────────────────┐
│                    ACCESS CHANNELS                              │
│  Web App │ Mobile App │ WhatsApp │ USSD (*384#) │ Partner API  │
│  ↑ Farmer enrollment, policy purchase, claim status            │
├─────────────────────────────────────────────────────────────────┤
│                  APPLICATION / BACKEND LAYER                    │
│  Laravel (PHP) — the coordination boundary                      │
│  • Policy APIs          • SEP-10 auth validation               │
│  • Parametric claim     • Oracle data retrieval                │
│    processing           • Contract invocation                  │
│  • Partner webhooks     • Admin and operational tooling        │
│  • Queue processing     • KYC / AML enforcement               │
├─────────────────────────────────────────────────────────────────┤
│               SERVICE & WALLET INFRASTRUCTURE                   │
│  • StellarService              • StellarWalletService          │
│  • StellarSmartContractService • StellarClaimService           │
│  • DefiWalletService           • WalletPlusService             │
│  • CustodialAddressService     • MoneyGramRampsService         │
├─────────────────────────────────────────────────────────────────┤
│                     FIAT RAILS LAYER                            │
│  • Paystack — NGN premium collection (MTN Mobile Money)        │
│  • MoneyGram — USDC/NGN cash ramps via SEP-24                  │
│  • Webhook reconciliation and provider status tracking         │
├─────────────────────────────────────────────────────────────────┤
│                       DATA LAYER                                │
│  • PostgreSQL — policy, claim, wallet, transaction state       │
│  • Redis — queue processing, caching, rate limiting            │
│  • File storage — documents, reports, impact data             │
├─────────────────────────────────────────────────────────────────┤
│               SATELLITE / ORACLE PIPELINE                       │
│  Sentinel Hub (Copernicus) → Acurast TEE Network               │
│  • NDVI / EVI vegetation index   • Rainfall telemetry          │
│  • Farm polygon-level data       • ed25519-signed payloads     │
│  • Daily satellite revisit       • Confidence scoring          │
├─────────────────────────────────────────────────────────────────┤
│           STELLAR / SOROBAN INSURANCE CONTRACT SUITE            │
│  • insurance-policy    — policy registry and lifecycle         │
│  • insurance-claim     — parametric evaluation and approval    │
│  • insurance-payment   — USDC pool and payout execution        │
│  • parametric-oracle   — verified satellite data storage       │
├─────────────────────────────────────────────────────────────────┤
│                  SETTLEMENT AND EXIT PATHS                      │
│  • USDC on Stellar     — primary settlement asset              │
│  • SEP-10              — Stellar wallet authentication         │
│  • SEP-24              — MoneyGram interactive withdrawal      │
│  • SEP-6               — B2B programmatic anchor flows         │
│  • MoneyGram agents    — NGN cash disbursement (last mile)     │
└─────────────────────────────────────────────────────────────────┘
```

### Core Architectural Principles

**Stellar as the settlement layer, not just a wallet.**
USDC on Stellar is not a crypto add-on. It is the mechanism that makes instant, auditable, last-mile insurance payouts economically and operationally possible. Every payout is on-chain, traceable by insurers and regulators, and disbursable as NGN cash through MoneyGram's agent network — without a bank account.

**Backend-mediated oracle flow.**
The `parametric-oracle` contract stores verified Acurast satellite data. The Laravel backend retrieves that data and supplies it to `insurance-claim` for evaluation. This keeps the contract invocation logic in a testable, maintainable application layer while preserving full on-chain verifiability of the underlying data.

**Explicit contract boundaries.**
`insurance-policy` owns policy state. `insurance-claim` owns claim decisions. `insurance-payment` owns pool accounting. Each contract has a single responsibility, which improves auditability and allows targeted upgrades.

**Wallet-first settlement.**
All claim payouts settle to the farmer's Stellar wallet first. From there, the farmer can hold USDC, send it to another wallet, or — once the T2 SEP-24 integration is live — withdraw as NGN cash via MoneyGram. This model supports both banked and unbanked users.

**Compliance as a first-class concern.**
KYC/AML checks, provider reference tracking, webhook audit trails, and on-chain transaction records are built into every payment and payout flow — not added as an afterthought.

---

## Complete End-to-End Flow

This section traces a complete lifecycle — from a farmer buying a policy to collecting a payout in cash — showing exactly how each system component connects.

```
FARMER (Benue State, Nigeria)
│
│  1. Enrols via USSD (*384#) or mobile app
│     Selects maize insurance for GPS-tagged farm polygon
│     Premium: ₦16,100 paid via MTN Mobile Money (Paystack)
│
▼
LARAVEL BACKEND
│
│  2. Validates KYC, creates policy record in PostgreSQL
│     Calls StellarSmartContractService::createPolicy()
│     Deposits USDC equivalent into insurance-payment pool
│
▼
insurance-policy (Soroban)
│
│  3. Stores on-chain:
│     - Farm GPS polygon
│     - Crop type and season dates
│     - Parametric trigger: NDVI < 0.3 for 14+ days
│     - Coverage: $120 USDC
│
▼  ════════════ GROWING SEASON ════════════
│
SENTINEL HUB (Copernicus satellite)
│
│  4. Daily NDVI / rainfall data for farm polygon
│     Retrieved by Acurast TEE processor
│     Processed inside hardware Trusted Execution Environment
│     Signed with authorised ed25519 key
│
▼
parametric-oracle (Soroban)
│
│  5. Verifies Acurast processor is on allow-list
│     Verifies ed25519 signature
│     Checks data freshness and confidence score
│     Stores in Soroban Persistent storage
│
▼  ════════════ DROUGHT EVENT ════════════
│
LARAVEL BACKEND (scheduled artisan command)
│
│  6. php artisan insurance:process-parametric-claims
│     Reads oracle data from parametric-oracle contract
│     Detects NDVI 0.21 — trigger condition met
│     Calls StellarSmartContractService::processParametricPayout()
│
▼
insurance-claim (Soroban)
│
│  7. Looks up policy in insurance-policy
│     Validates policy is active and within term
│     Validates oracle data (freshness, confidence, signature)
│     Updates claim state (CHECKS-EFFECTS-INTERACTIONS)
│     Calls insurance-payment to execute payout
│
▼
insurance-payment (Soroban)
│
│  8. Releases $120 USDC from insurance pool
│     SAC transfer to farmer's Stellar wallet address
│     Transaction recorded on Stellar (permanent, auditable)
│     Notifies insurance-claim: payout complete
│
▼
FARMER STELLAR WALLET
│
│  9. Farmer receives SMS notification
│     Opens Riwe app → initiates cash withdrawal
│     (The following steps describe the designed T2 flow)
│
▼
SEP-10 AUTHENTICATION  (T2 deliverable)
│
│  10. Farmer's Stellar keypair authenticates with
│      MoneyGram's SEP-10 challenge/response flow
│      Backend verifies JWT token
│
▼
SEP-24 INTERACTIVE WITHDRAWAL (MoneyGram anchor)  (T2 deliverable)
│
│  11. MoneyGram SEP-24 anchor initiates withdrawal
│      USDC converted to NGN at live exchange rate
│      Farmer confirms amount and agent location
│
▼
MONEYGRAM AGENT POINT
│
│  12. Farmer collects NGN cash at local agent
│      Within 48 hours of trigger confirmation
│      No bank account required
│
▼
OUTCOME: Farmer paid. Insurer records updated.
         Full audit trail on Stellar.
         MoneyGram SEP-24 flow validated end-to-end in T2 D3.
```

---

## Core Components

### 1. Stellar and Soroban Integration

#### StellarService (Core)

```php
class StellarService
{
    protected function initializeNetwork()     // Horizon + Soroban RPC setup
    protected function initializeSdk()         // PHP Stellar SDK initialisation

    public function createAccount(): array
    public function fundTestnetAccount(string $accountId): bool
    public function getAccountInfo(string $accountId): array
    public function sendPayment(string $destination, string $amount, string $asset): array
    public function createTrustline(string $assetCode, string $issuer): array
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
    public function sendPayment(...): array
    public function createTrustline(...): array
}
```

#### StellarSmartContractService

```php
class StellarSmartContractService
{
    // Core contract invocation
    public function invokeContract(string $contractId, string $method, array $params): array

    // Insurance lifecycle operations
    public function createPolicy(array $policyData): array
    public function submitClaim(array $claimData): array
    public function processParametricPayout(string $claimId, array $parametricData): array
}
```

The `StellarClaimService` handles scheduled parametric claim evaluation, coordinating oracle data retrieval with claim submission and payout invocation.

### 2. Fiat Rails — Paystack and MoneyGram

#### Premium Collection (Paystack → USDC)

Farmers pay premiums in NGN via Paystack, which supports MTN Mobile Money and major Nigerian banks. The backend converts and deposits the USDC equivalent into the `insurance-payment` pool on Stellar.

```
Farmer → MTN Mobile Money → Paystack → NGN/USDC conversion → insurance-payment pool
```

#### Claim Disbursement — Designed Flow (USDC → MoneyGram → NGN cash)

The `MoneyGramRampsService` class defines the SEP-24 withdrawal interface. The live integration with MoneyGram's anchor and end-to-end validation is the **T2 Deliverable 3**.

```php
class MoneyGramRampsService
{
    public function getInfo(): array                           // SEP-24 anchor info
    public function initiateDeposit(User $user, ...): array   // SEP-24 deposit flow
    public function initiateWithdrawal(User $user, ...): array // SEP-24 withdrawal flow
    public function getTransactionStatus(string $txId): array
    public function handleWebhook(array $payload): bool
}
```

MoneyGram operates as a SEP-24 anchor on Stellar. Once live, the `MoneyGramRampsService` will wrap the full interactive withdrawal flow — SEP-10 authentication, USDC debit, NGN disbursement — and reconcile status via webhook.

### 3. Wallet Infrastructure

#### DefiWalletService — Custodial wallet operations

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

#### WalletPlusService — Self-custodial operations

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

WalletPlusService provides device-bound authentication and transaction signing for partner institutions and higher-assurance farmer operations. It complements the custodial wallet with self-custody capabilities where appropriate.

---

## Stellar Ecosystem Integration

### SEP Protocol Summary

| SEP | Purpose in Riwe | Status |
|---|---|---|
| SEP-10 | Stellar wallet authentication for farmers and partners | 🔲 T2 deliverable |
| SEP-24 | Interactive USDC/NGN withdrawal via MoneyGram anchor | 🔲 T2 deliverable |
| SEP-6 | Programmatic anchor flows for B2B partners (insurers, MFBs) | 🔲 T2 deliverable |
| SEP-1 | `stellar.toml` for protocol discovery on Mainnet | T3 Deliverable |
| SEP-30 | Account recovery for partner-managed customer wallets | T3 Deliverable |

### SEP-10 — Designed Authentication Flow

Once implemented in T2, every insurance operation touching a Stellar wallet will be gated behind SEP-10 wallet authentication. The farmer or partner signs a server-generated challenge with their Stellar keypair. The backend verifies the resulting JWT before authorising any contract invocation.

```
Client                Laravel Backend           Stellar Network
  │                         │                         │
  │── GET /auth challenge ──→│                         │
  │←── signed challenge ────│                         │
  │── POST signed JWT ─────→│                         │
  │                         │── verify SEP-10 JWT ───→│
  │                         │←── account confirmed ───│
  │←── auth token ──────────│                         │
```

### SEP-24 — Designed MoneyGram Withdrawal Flow

This is the last-mile disbursement mechanism that makes Riwe work for unbanked farmers. The UX/UI designs for this flow are **T1 Deliverable 3**. The live integration is **T2 Deliverable 3**.

```
Farmer Stellar Wallet ($120 USDC)
        │
        │  1. GET /info — anchor capabilities
        │  2. POST /transactions/withdraw/interactive
        │     { asset: USDC, amount: 120, account: farmer_keypair }
        │
        ▼
MoneyGram SEP-24 Anchor
        │
        │  3. Interactive KYC and amount confirmation
        │  4. USDC debited from farmer wallet
        │  5. NGN disbursement instruction sent to agent network
        │
        ▼
MoneyGram Agent (Benue State)
        │
        │  6. Farmer presents ID, collects NGN cash
        │     Confirmed via webhook back to Riwe backend
```

### USDC on Stellar

USDC is the settlement asset for all insurance operations. It is handled through Stellar Asset Contract (SAC) operations in the `insurance-payment` contract. USDC was selected because:

- Stable — no farmer or insurer exposure to crypto volatility
- Natively supported by MoneyGram's SEP-24 anchor
- First-class Stellar ecosystem asset with deep liquidity
- Auditable by reinsurers and regulators as a regulated stablecoin
- Directly usable in Soroban SAC token operations

---

## Database Architecture

### Core Schema

#### Users and Authentication

```sql
users (
    id, email, phone, password_hash,
    email_verified_at, phone_verified_at,
    kyc_status, preferred_currency,
    created_at, updated_at
)

stellar_wallets (
    id, user_id, public_key, encrypted_private_key,
    account_id, sequence_number,
    balance_xlm, balance_usd,
    is_active, is_funded,
    created_at, updated_at
)
```

#### Insurance Core

```sql
insurance_policies (
    id, user_id, farm_id, product_id,
    stellar_policy_id,          -- on-chain contract policy ID
    premium_amount, coverage_amount,
    status,
    parametric_triggers,        -- JSON: NDVI threshold, GPS polygon, season dates
    blockchain_data,            -- on-chain transaction references
    created_at, updated_at
)

claims (
    id, policy_id, user_id,
    stellar_claim_id,           -- on-chain claim ID
    claim_type, amount, status,
    trigger_data,               -- satellite data that triggered the claim
    oracle_data,                -- Acurast-verified oracle payload
    blockchain_data,            -- on-chain settlement references
    processed_at, created_at, updated_at
)

stellar_smart_contracts (
    id, contract_id, contract_type, wasm_hash,
    deployer_id, network, status,
    metadata, deployed_at,
    created_at, updated_at
)
```

The `stellar_smart_contracts` table maps to the four deployed Soroban contracts. The `parametric_triggers` column on `insurance_policies` stores the GPS polygon, threshold conditions, and season parameters that the oracle pipeline evaluates against.

#### DeFi Wallet

```sql
defi_wallets (
    id, user_id, stellar_wallet_id,
    status, is_enabled,
    balance_usd, balance_ngn, balance_xlm,
    stellar_address,
    kyc_level, daily_limit_fiat, monthly_limit_fiat,
    created_at, updated_at
)

defi_transactions (
    id, user_id, defi_wallet_id,
    stellar_transaction_id, transaction_hash,
    reference, type, status,
    amount, currency, amount_usd, exchange_rate, fees,
    metadata, created_at, updated_at
)

fiat_onramps (
    id, user_id, defi_wallet_id, defi_transaction_id,
    provider,                   -- 'paystack' or 'moneygram'
    type,                       -- 'deposit' or 'withdrawal'
    status,
    fiat_amount, fiat_currency,
    crypto_amount, crypto_currency,
    exchange_rate, bank_details,
    provider_reference,         -- Paystack or MoneyGram transaction ID
    created_at, updated_at
)
```

#### Self-Custodial Wallet

```sql
wallet_plus (
    id, user_id, public_key,
    device_id, device_fingerprint,
    pin_hash, biometric_enabled,
    mfa_enabled, mfa_secret,
    cloud_backup_enabled, encrypted_backup,
    status, last_accessed_at,
    created_at, updated_at
)
```

### Index Strategy

```sql
-- Primary access patterns
CREATE INDEX idx_policies_user_status ON insurance_policies(user_id, status);
CREATE INDEX idx_claims_policy_status ON claims(policy_id, status);
CREATE INDEX idx_defi_transactions_user_status ON defi_transactions(user_id, status);
CREATE INDEX idx_fiat_onramps_provider_ref ON fiat_onramps(provider_reference);
CREATE INDEX idx_stellar_transactions_hash ON stellar_transactions(transaction_hash);
CREATE INDEX idx_defi_wallets_user_id ON defi_wallets(user_id);
CREATE INDEX idx_defi_transactions_wallet_id ON defi_transactions(defi_wallet_id);
CREATE INDEX idx_defi_transactions_status ON defi_transactions(status);
```

---

## Network Configuration

### Stellar — Primary Settlement Network

```php
// config/stellar.php
'networks' => [
    'testnet' => [
        'horizon_url'        => 'https://horizon-testnet.stellar.org',
        'soroban_rpc_url'    => 'https://soroban-testnet.stellar.org',
        'network_passphrase' => 'Test SDF Network ; September 2015',
        'friendbot_url'      => 'https://friendbot.stellar.org',
    ],
    'mainnet' => [
        'horizon_url'        => 'https://horizon.stellar.org',
        'soroban_rpc_url'    => 'https://soroban-mainnet.stellar.org',
        'network_passphrase' => 'Public Global Stellar Network ; September 2015',
    ],
]
```

Stellar is the primary and non-negotiable settlement network for all insurance policy, claim, and payout operations. The Soroban contracts are Stellar-only.

### Live Testnet Contract Addresses

```
insurance-policy:  CCRXGROY4THHIB7QRGMJHBXXN7TPMVEYGBBEFVKGWQXOYH4RHJDB3SHR
insurance-claim:   CCFYJDOFQAQT5DVB2UNU4SWOXMVFLLVWNG47J6G5ZPQGPDMRWSXO75WQ
insurance-payment: TBD — T2 deliverable
parametric-oracle: TBD — T2 deliverable
```

The policy and claims contracts are active and verifiable at [stellar.expert/explorer/testnet](https://stellar.expert/explorer/testnet).

---

## Security Architecture

### Defence in Depth

```
┌──────────────────────────────────────────────────────────────┐
│                    APPLICATION LAYER                         │
│  SEP-10 wallet auth  │  Sanctum API tokens  │  Input valid.  │
├──────────────────────────────────────────────────────────────┤
│                    TRANSPORT LAYER                           │
│  TLS/SSL             │  Certificate pinning │  Rate limiting  │
├──────────────────────────────────────────────────────────────┤
│                      DATA LAYER                              │
│  Encrypted keys at rest │  Key management  │  Audit logging  │
├──────────────────────────────────────────────────────────────┤
│                   BLOCKCHAIN LAYER                           │
│  require_auth()  │  ed25519 sig verify  │  CHECKS-EFFECTS    │
│  Oracle allow-list │  Freshness checks  │  Contract audits   │
└──────────────────────────────────────────────────────────────┘
```

### Key Management

| Mode | Approach |
|---|---|
| Custodial (farmer wallets) | Encrypted private keys in PostgreSQL, AES-256 |
| Self-custodial (WalletPlus) | Device-bound key storage, biometric authentication |
| Hardware | HSM integration for critical pool operations |
| Recovery | Multi-factor recovery via `wallet_plus_recovery` table |

### On-chain Security

The Soroban contracts enforce:
- `require_auth()` on all state-changing operations
- ed25519 oracle signature verification
- CHECKS-EFFECTS-INTERACTIONS on payout flows
- Explicit error taxonomy preventing silent failures

See [Soroban Smart Contracts Overview — Security Features](./Soroban-Smart-Contracts-Overview.md#security-features) for full contract-level security documentation.

---

## Integration Patterns

### Webhook Processing

```php
// Inbound provider webhooks
Route::post('/webhooks/paystack', [PaymentWebhookController::class, 'handlePaystack']);
Route::post('/moneygram/webhook', [MoneyGramController::class, 'webhook']);
```

Paystack and MoneyGram webhooks update `fiat_onramps` and `defi_transactions` records and trigger downstream wallet reconciliation. The MoneyGram webhook is the confirmation signal that a farmer has collected their NGN cash — updating the claim record to `disbursed`.

### Queue and Scheduled Processing

```bash
# Scheduled parametric claim evaluation
php artisan insurance:process-parametric-claims

# Queued settlement jobs
PaySettlementJob::dispatch($settlement->id)
```

The `insurance:process-parametric-claims` command is the heartbeat of the protocol. It runs on a defined schedule, reads oracle data from the `parametric-oracle` contract, evaluates trigger conditions against active policies, and invokes the claim and payment contracts for any policies whose trigger conditions are met.

### API Route Groups

```php
// Stellar wallet operations (authenticated)
Route::middleware(['auth:sanctum'])->prefix('stellar')->group(function () { ... });

// DeFi wallet operations (authenticated)
Route::middleware(['auth:sanctum'])->prefix('defi-wallet')->group(function () { ... });

// MoneyGram SEP-24 flows (authenticated)
Route::prefix('api/moneygram')->middleware(['auth:sanctum'])->group(function () { ... });
```

All insurance-related API routes require Sanctum authentication. MoneyGram routes additionally require SEP-10 wallet authentication for any operation that touches the Stellar layer.

### Partner Underwriting Console

Partner institutions — Leadway Assurance, NIA-member insurers, microfinance banks — access a dedicated dashboard with:
- SEP-7 support for delegated portfolio actions
- Real-time risk pool visualisation
- Policy approval workflows
- Per-crop and per-region exposure summaries

This B2B console is an SCF T2 deliverable and is built on the same API infrastructure, authenticated via SEP-10 and restricted to partner-scoped roles.

---

## Build Readiness and Live Status

### What is deployed and operational today

| Component | Status | Evidence |
|---|---|---|
| `insurance-policy` Soroban contract | ✅ Live on Testnet | `CCRXGROY...` |
| `insurance-claim` Soroban contract | ✅ Live on Testnet | `CCFYJDOF...` |
| `insurance-payment` contract | 🔲 Source complete — T2 deployment | — |
| `parametric-oracle` contract | 🔲 Source complete — T2 deployment | — |
| Laravel Stellar service layer | ✅ Integrated | `StellarSmartContractService` |
| SEP-10 wallet authentication | 🔲 T2 deliverable | Backend extension |
| MoneyGram SEP-24 service | 🔲 T2 deliverable | `MoneyGramRampsService` (class exists) |
| Paystack NGN premium collection | ✅ Live | with existing payouts |
| Sentinel Hub data retrieval | ✅ Operational | Off-chain pipeline active |
| Off-chain parametric model | ✅ Live | 16,000+ active policies |

### SCF Tranche Deliverables

| Tranche | Deliverable | Stellar integration |
|---|---|---|
| T0 | Infrastructure setup | Sentinel Hub API, Acurast registration |
| T1 | Soroban contract suite (production-grade) | All 4 contracts, ≥90% test coverage |
| T1 | Protocol interaction CLI | Full lifecycle simulation in Soroban sandbox |
| T1 | MoneyGram SEP-24 UX/UI | 8-screen Figma: SEP-10 auth, policy, claim, withdrawal |
| T2 | Testnet deployment | All 4 contracts live; public dApp at riwe.io |
| T2 | Acurast oracle activation | Sentinel Hub → TEE → parametric-oracle live pipeline |
| T2 | Partner underwriting console | SEP-7 dashboard for insurers and MFBs |
| T2 | E2E integration and MoneyGram simulation | Full lifecycle test; SEP-24 video walkthrough |
| T3 | Mainnet deployment | SDF-audited contracts; SEP-1 stellar.toml |
| T3 | TypeScript SDK and NPM package | Public developer ecosystem tooling |
| T3 | Institutional partner onboarding | Leadway + bank integrations; SEP-30 account recovery |
| T3 | Infrastructure monitoring | Datadog at riwe.io/status; final impact report |

### The gap this submission closes

Riwe's off-chain parametric insurance model has been live since 2022 with 24,000+ users and $10M+ in protected assets. The Soroban contract suite exists and is partially deployed. The `MoneyGramRampsService` class and SEP-24 flow architecture exist in the codebase — the live anchor integration and end-to-end validation are T2 deliverables.

The missing piece is the Acurast-powered oracle pipeline connecting live satellite data to the on-chain `parametric-oracle` contract. Without this, the claim contract cannot evaluate triggers automatically — the on-chain settlement flow depends on a manual data submission. This SCF award funds the activation of that pipeline, completing the fully automated end-to-end protocol.

---

*Riwe Technologies Limited · riwe.io · partnerships@riwe.io*
