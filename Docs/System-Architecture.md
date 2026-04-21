# System Architecture
## Riwe Technologies — Parametric Climate Insurance on Stellar

---

## System Purpose

Riwe is a parametric climate insurance platform for smallholder farmers in Africa. A farmer who experiences a drought or flood receives a verified cash payout within 48 hours — no claim filing, no bank visit, no adjuster.

Every architectural decision exists in service of that outcome: the Soroban contract suite, the Acurast oracle pipeline, the MoneyGram SEP-24 integration, the application backend.

---

## Full Stack

```
┌──────────────────────────────────────────────────────────────────┐
│                        ACCESS CHANNELS                           │
│   Web App · Mobile App · WhatsApp · USSD (*384#) · Partner API  │
├──────────────────────────────────────────────────────────────────┤
│                   APPLICATION / BACKEND LAYER                    │
│   Laravel (PHP 8.2)                                              │
│   Policy APIs · Parametric claim processing · Partner webhooks   │
│   Queue processing · KYC/AML · SEP-10 auth · Contract invocation│
├──────────────────────────────────────────────────────────────────┤
│               SERVICE AND WALLET INFRASTRUCTURE                  │
│   StellarService · StellarWalletService                          │
│   StellarSmartContractService · StellarClaimService              │
│   DefiWalletService · WalletPlusService · MoneyGramRampsService  │
├──────────────────────────────────────────────────────────────────┤
│                       FIAT RAILS LAYER                           │
│   Paystack — NGN premium collection via MTN Mobile Money         │
│   MoneyGram — USDC/NGN cash ramps via SEP-24                     │
│   Webhook reconciliation and provider status tracking            │
├──────────────────────────────────────────────────────────────────┤
│                          DATA LAYER                              │
│   PostgreSQL — policy, claim, wallet, transaction state          │
│   Redis — queue processing, caching, rate limiting               │
├──────────────────────────────────────────────────────────────────┤
│                  SATELLITE / ORACLE PIPELINE                     │
│   Sentinel Hub (Copernicus) → Acurast TEE Network                │
│   NDVI / EVI · Rainfall telemetry · Farm polygon-level data      │
│   ed25519-signed payloads · Daily satellite revisit              │
├──────────────────────────────────────────────────────────────────┤
│             STELLAR / SOROBAN INSURANCE CONTRACT SUITE           │
│   insurance-policy   — policy registry and lifecycle             │
│   insurance-claim    — parametric evaluation and approval        │
│   insurance-payment  — USDC pool and payout execution            │
│   parametric-oracle  — verified satellite data storage           │
├──────────────────────────────────────────────────────────────────┤
│                   SETTLEMENT AND EXIT PATHS                      │
│   USDC on Stellar — primary settlement asset                     │
│   SEP-10 — Stellar wallet authentication                         │
│   SEP-24 — MoneyGram interactive cash withdrawal                 │
│   SEP-6  — B2B programmatic anchor flows                         │
│   MoneyGram agent network — NGN cash disbursement, last mile     │
└──────────────────────────────────────────────────────────────────┘
```

---

## Architecture Principles

**Stellar as the settlement layer, not just a wallet.**
USDC on Stellar is what makes last-mile insurance payouts economically viable. Every payout is on-chain, traceable by insurers and regulators, and reachable as NGN cash through MoneyGram's agent network without requiring a bank account. We chose Stellar because MoneyGram already operates as a SEP-24 anchor here — we didn't need to build a custom fiat conversion layer, and also, momneygram has a wide coverage in the Nigerian and African region, making it the perfect option to off-ramp claims payouts to farmers.

**Backend-mediated oracle flow.**
The `parametric-oracle` contract stores verified Acurast satellite data. The Laravel backend retrieves that data and supplies it to `insurance-claim` for evaluation. This keeps invocation logic in a testable application layer while preserving full on-chain verifiability of the underlying data.

**Four contracts, one responsibility each.**
`insurance-policy` owns policy state. `insurance-claim` owns claim decisions. `insurance-payment` owns pool accounting. `parametric-oracle` stores verified satellite readings. Splitting responsibilities this way improves auditability and allows targeted upgrades without touching unrelated contract logic.

**Custodial settlement, fiat-facing UX.**
Claim payouts settle in USDC to a Riwe-managed custodial Stellar wallet on the farmer's behalf. The farmer never holds a private key or interacts with USDC directly — their experience is entirely in NGN. MoneyGram converts the settled USDC and disburses local currency at the nearest agent. The USDC layer is an internal settlement rail.
---

## End-to-End Flow

```
FARMER (Benue State, Nigeria)
│
│  1. Enrols via USSD (*384#) or mobile app
│     Selects maize coverage for GPS-tagged farm polygon
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
│       Farm GPS polygon · Crop type and season dates
│       Trigger: NDVI < 0.3 for 14+ consecutive days
│       Coverage: $120 USDC
│
▼  ════ GROWING SEASON ════
│
SENTINEL HUB (Copernicus satellite)
│
│  4. Daily NDVI / rainfall readings for the farm polygon
│     Retrieved by Acurast TEE processor
│     Processed inside hardware Trusted Execution Environment
│     Signed with authorised ed25519 key before submission
│
▼
parametric-oracle (Soroban)
│
│  5. Verifies Acurast processor is on allow-list
│     Verifies ed25519 signature
│     Checks data freshness and confidence score
│     Stores result in Soroban Persistent storage
│
▼  ════ DROUGHT DETECTED ════
│
LARAVEL BACKEND (scheduled artisan command)
│
│  6. php artisan insurance:process-parametric-claims
│     Reads oracle data from parametric-oracle contract
│     Detects NDVI at 0.21 — trigger threshold met
│     Calls StellarSmartContractService::processParametricPayout()
│
▼
insurance-claim (Soroban)
│
│  7. Looks up policy in insurance-policy
│     Validates policy is active and within season term
│     Validates oracle data: freshness, confidence, signature
│     Updates claim state (CHECKS-EFFECTS-INTERACTIONS)
│     Calls insurance-payment to execute payout
│
▼
insurance-payment (Soroban)
│
│  8. Releases $120 USDC from insurance pool
│     SAC transfer to Riwe-managed custodial wallet (farmer's account)
│     Transaction recorded on Stellar — permanent and auditable
│
▼
RIWE-MANAGED CUSTODIAL WALLET (Stellar, on behalf of farmer)
│
│  9. Farmer receives SMS notification showing NGN payout amount
│     Opens Riwe app and confirms cash collection
│
▼
SEP-10 AUTHENTICATION
│
│  10. Riwe backend signs MoneyGram's SEP-10 challenge
│      using the farmer's custodially-managed Stellar keypair
│      Backend verifies the resulting JWT
│
▼
SEP-24 INTERACTIVE WITHDRAWAL — MoneyGram anchor
│
│  11. MoneyGram SEP-24 anchor initiates withdrawal
│      USDC debited from custodial wallet, converted to NGN
│      Farmer selects nearest agent location in Riwe app
│
▼
MONEYGRAM AGENT POINT
│
│  12. Farmer collects NGN cash at local agent
│      Within 48 hours of trigger confirmation
│      No bank account required
```

---

## Core Components

### Stellar and Soroban Services

**`StellarService`** — Low-level Stellar gateway. Account creation, Friendbot funding (testnet), balance reads, payments, trustlines, and network config. Base dependency for all higher-level services.

**`StellarWalletService`** — Farmer wallet lifecycle. Creates Stellar keypairs, encrypts secret keys via Laravel `Crypt`, funds testnet accounts, reads balances, and dispatches user-level payments.

**`StellarSmartContractService`** — All Soroban contract interaction. Contract IDs sourced from `config/stellar.php`, `invokeContract()` and `queryContract()` surfaces, and three insurance lifecycle methods:

```php
public function createPolicy(array $policyData): array
public function submitClaim(array $claimData): array
public function processParametricPayout(string $claimId, array $parametricData): array
```

**`StellarClaimService`** — Claim orchestration. Runs the `insurance:process-parametric-claims` command, retrieves oracle data, evaluates trigger conditions, and coordinates payout dispatch to the farmer's wallet.

### Fiat Rails

**Premium collection — Paystack → USDC:**
```
Farmer → MTN Mobile Money → Paystack → NGN/USDC conversion → insurance-payment pool
```

**Claim disbursement — MoneyGram SEP-24:**
`MoneyGramRampsService` wraps the full interactive withdrawal flow — SEP-10 authentication, USDC debit, NGN disbursement — and reconciles status via webhook.

```php
public function initiateWithdrawal(User $user, ...): array
public function getTransactionStatus(string $txId): array
public function handleWebhook(array $payload): bool
```

### Wallet Infrastructure

**`DefiWalletService`** — Custodial wallet operations: create or retrieve wallet, sync with Stellar settlement wallet, initiate fiat deposits and withdrawals, and claims settlements. 

**`WalletPlusService`** — Self-custodial operations for partners and higher-assurance farmer (agribusiness) accounts: device-bound authentication, biometric signing, transaction preparation, and wallet recovery.

---

## Soroban Contract Suite

### Live Testnet Contract IDs

| Contract | Function | Testnet Address |
|---|---|---|
| `insurance-policy` | Policy registry and lifecycle | `CCRXGROY4THHIB7QRGMJHBXXN7TPMVEYGBBEFVKGWQXOYH4RHJDB3SHR` |
| `insurance-claim` | Parametric evaluation and approval | `CCFYJDOFQAQT5DVB2UNU4SWOXMVFLLVWNG47J6G5ZPQGPDMRWSXO75WQ and to nbe updated by this SCF submission` |
| `insurance-payment` | USDC pool and payout execution | `TBD by this SCF submission` |
| `parametric-oracle` | Verified satellite data storage | `TBD by this SCF submission` |

Verifiable at [stellar.expert/explorer/testnet](https://stellar.expert/explorer/testnet).

### On-Chain Security

- `require_auth()` on all state-changing operations
- `ed25519` signature verification on all oracle submissions
- CHECKS-EFFECTS-INTERACTIONS pattern on payout flows
- Oracle allow-list — only registered Acurast processors can submit data
- Explicit error taxonomy — no silent failures

---

## Database Architecture

```sql
users (id, email, phone, kyc_status, preferred_currency, ...)

stellar_wallets (id, user_id, public_key, encrypted_private_key,
                 balance_xlm, balance_usd, is_active, is_funded, ...)

insurance_policies (id, user_id, farm_id, stellar_policy_id,
                    premium_amount, coverage_amount, status,
                    parametric_triggers,  -- JSON: NDVI threshold, GPS polygon, season dates
                    blockchain_data, ...)

claims (id, policy_id, stellar_claim_id, claim_type, amount, status,
        trigger_data,   -- satellite reading that triggered the claim
        oracle_data,    -- Acurast-verified oracle payload
        blockchain_data, processed_at, ...)

fiat_onramps (id, user_id, provider,          -- 'paystack' or 'moneygram'
              type,                            -- 'deposit' or 'withdrawal'
              fiat_amount, fiat_currency,
              crypto_amount, crypto_currency,
              exchange_rate, provider_reference, ...)

wallet_plus (id, user_id, public_key, device_id, device_fingerprint,
             pin_hash, biometric_enabled, mfa_enabled,
             cloud_backup_enabled, encrypted_backup, ...)
```

### Key Indexes

```sql
CREATE INDEX idx_policies_user_status     ON insurance_policies(user_id, status);
CREATE INDEX idx_claims_policy_status     ON claims(policy_id, status);
CREATE INDEX idx_fiat_onramps_provider    ON fiat_onramps(provider_reference);
CREATE INDEX idx_defi_transactions_wallet ON defi_transactions(defi_wallet_id);
CREATE INDEX idx_stellar_tx_hash          ON stellar_transactions(transaction_hash);
```

---

## Network Configuration

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

---

## Security Architecture

| Layer | Controls |
|---|---|
| Application | SEP-10 wallet auth · Sanctum API tokens · Input validation |
| Transport | TLS/SSL · Certificate pinning · Rate limiting |
| Data | AES-256 encrypted keys at rest · Audit logging |
| Blockchain | `require_auth()` · ed25519 sig verify · CHECKS-EFFECTS · Oracle allow-list |

| Wallet mode | Key management |
|---|---|
| Custodial (farmer wallets) | AES-256 encrypted private keys in PostgreSQL |
| Self-custodial (WalletPlus) | Device-bound storage, biometric authentication |
| Critical pool operations | HSM integration |
| Account recovery | SEP-30 multi-factor recovery (T3) |

---

## SEP Protocol Summary

| SEP | Role in Riwe | Status |
|---|---|---|
| SEP-10 | Stellar wallet authentication for farmers and partners | T2 |
| SEP-24 | Interactive USDC/NGN withdrawal via MoneyGram anchor | T2 |
| SEP-6 | Programmatic anchor flows for B2B partners | T2 |
| SEP-7 | Delegated portfolio actions in partner underwriting console | T2 |
| SEP-1 | `stellar.toml` for Mainnet protocol discovery | T3 |
| SEP-30 | Account recovery for partner-managed wallets | T3 |

---

## Integration Patterns

### Webhooks

```php
Route::post('/webhooks/paystack', [PaymentWebhookController::class, 'handlePaystack']);
Route::post('/moneygram/webhook', [MoneyGramController::class, 'webhook']);
```

The MoneyGram webhook fires with `completed` status when a farmer collects their NGN cash — the backend marks the claim as `disbursed` and closes the loop.

### Scheduled Processing

```bash
# Heartbeat of the protocol — reads oracle data, evaluates triggers, invokes contracts
php artisan insurance:process-parametric-claims
```

### API Route Groups

```php
Route::middleware(['auth:sanctum'])->prefix('stellar')->group(...);
Route::middleware(['auth:sanctum'])->prefix('defi-wallet')->group(...);
Route::prefix('api/moneygram')->middleware(['auth:sanctum'])->group(...);
```

---

## Build Status

| Component | Status |
|---|---|
| `insurance-policy` Soroban contract | Live on Testnet |
| `insurance-claim` Soroban contract | Live on Testnet |
| `insurance-payment` contract | T2 deployment |
| `parametric-oracle` contract | T2 deployment |
| Laravel Stellar service layer | Integrated and operational |
| Paystack NGN premium collection | Live |
| Sentinel Hub satellite data retrieval | Operational — off-chain pipeline |
| Off-chain parametric model | Live — 16,000+ active policies |
| SEP-10 wallet authentication | T2 |
| MoneyGram SEP-24 integration | T2 — `MoneyGramRampsService` class exists |

---

*Riwe Technologies Limited · riwe.io · partnerships@riwe.io*
