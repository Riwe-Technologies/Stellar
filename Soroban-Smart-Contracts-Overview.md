# Soroban Smart Contracts Overview
## Riwe Technologies — Parametric Climate Insurance on Stellar

**Related documentation:** [System Architecture →](./System-Architecture.md)

---

## Table of Contents

1. [Why Stellar](#why-stellar)
2. [What the Contracts Do — In Plain Terms](#what-the-contracts-do)
3. [Soroban Introduction](#soroban-introduction)
4. [Contract Architecture](#contract-architecture)
5. [End-to-End Flow: Farmer to Payout](#end-to-end-flow)
6. [Contract Inventory](#contract-inventory)
7. [Stellar Ecosystem Integration](#stellar-ecosystem-integration)
8. [Oracle Architecture — Acurast + Sentinel Hub](#oracle-architecture)
9. [Contract Deployment](#contract-deployment)
10. [Application Integration](#application-integration)
11. [Security Features](#security-features)
12. [Performance & Optimization](#performance--optimization)
13. [Testing & Validation](#testing--validation)
14. [Build Readiness](#build-readiness)
15. [Known Inconsistencies](#known-inconsistencies)
16. [Relevant Files](#relevant-files)

---

## Why Stellar

Riwe provides parametric climate insurance to smallholder farmers across Africa. When a drought or flood is detected by satellite, an enrolled farmer should receive a payout automatically — no claims adjuster, no paperwork, no bank account required.

Traditional insurance infrastructure cannot deliver this. Claims take 30–90 days. Disbursement to unbanked rural communities is expensive and unreliable. Fraud and manual error erode trust.

Stellar solves three specific problems in this model:

**1. Instant, auditable settlement.**
When a parametric trigger is met — for example, NDVI vegetation index falling below a defined threshold for a GPS-tagged farm polygon — the Soroban claim contract evaluates the condition and authorises a USDC payout in a single on-chain transaction. Settlement happens in seconds, not weeks. Every payout is permanently recorded on Stellar, giving insurers, regulators, and reinsurers a tamper-proof audit trail.

**2. Last-mile disbursement to unbanked farmers.**
USDC settled on Stellar is disbursed to farmers in local currency (NGN) through MoneyGram's agent network via the SEP-24 protocol. A farmer with no bank account can collect their claim payout in cash at a nearby MoneyGram agent point within 48 hours of trigger confirmation. This is only possible because Stellar's SEP-24 interactive withdrawal protocol provides a standardised, auditable fiat off-ramp directly from the on-chain settlement.

**3. Transparent insurance pool accounting.**
The `insurance-payment` contract maintains the insurance pool balance on-chain. Premiums flow in, payouts flow out, and the reserve is visible to every counterparty — farmers, insurers, and partner banks — without requiring trust in any single intermediary. This is the foundation for Riwe's B2B insurance infrastructure layer.

Stellar is not a generic choice. It is the only network that combines Soroban programmable settlement, USDC as a first-class asset, SEP-24 fiat off-ramps, and MoneyGram's last-mile agent network in a single coherent stack purpose-built for cross-border financial inclusion.

---

## What the Contracts Do — In Plain Terms

Before going into technical detail, here is what each contract does in operational language:

| Contract | What it does for a farmer |
|---|---|
| `insurance-policy` | Records that Adaeze's farm in Benue State is insured for the 2025 maize season, with a drought trigger set at NDVI < 0.3 |
| `parametric-oracle` | Receives Sentinel Hub satellite data confirming that Adaeze's farm polygon recorded NDVI 0.21 on August 14th |
| `insurance-claim` | Evaluates the trigger condition, confirms the oracle data is fresh and signed, and authorises a ₦48,000 payout |
| `insurance-payment` | Releases USDC from the insurance pool to Adaeze's Stellar wallet, which she then withdraws as cash through a MoneyGram agent |

---

## Soroban Introduction

### What Soroban is

Soroban is Stellar's smart contract platform. Contracts are written in Rust, compiled to WebAssembly (WASM), and deployed to the Stellar network where they execute deterministically and at low cost.

### Why Soroban fits this use case

Soroban is the right choice for parametric insurance because:

- **Deterministic execution** ensures that the same satellite data and trigger conditions always produce the same claim outcome — no discretion, no dispute
- **Low-cost settlement** on Stellar makes micro-insurance economically viable at ₦16,100 premium levels
- **Composable cross-contract calls** allow the claim, payment, and oracle contracts to coordinate in a single atomic flow
- **Token-aware payment flows** with USDC as a native Stellar asset make payout execution straightforward and auditable
- **Soroban Persistent storage** gives the oracle contract a tamper-resistant on-chain record of every satellite data submission

### Core development model

1. Contracts written in Rust with `#[contract]` and `#[contractimpl]` annotations
2. Compiled to WASM with Cargo using size-optimised release settings
3. Deployed to Soroban via `soroban contract deploy`
4. Initialised with network-specific configuration and cross-contract addresses
5. Invoked by the Laravel backend via `StellarSmartContractService`

---

## Contract Architecture

### Workspace structure

```
contracts/
├── Cargo.toml              # Workspace definition and shared release profile
├── insurance-policy/       # Policy lifecycle management
├── insurance-claim/        # Claim evaluation and payout authorisation
├── insurance-payment/      # Premium collection and payout execution
├── parametric-oracle/      # Satellite data ingestion and verification
└── shared/                 # Shared types, validation, error definitions
```

### High-level architecture

The contract suite is modular by domain. Each contract owns its own state and exposes a clean interface to the others:

```
                    ┌─────────────────────────┐
  Satellite Data    │   parametric-oracle     │  ← Acurast TEE pushes
  (Sentinel Hub)    │   - oracle allow-list   │    verified NDVI /
  via Acurast ───→  │   - ed25519 sig verify  │    rainfall data
                    │   - freshness checks    │
                    │   - Persistent storage  │
                    └────────────┬────────────┘
                                 │ oracle data read
                                 ▼
  Laravel backend  ┌─────────────────────────┐    ┌───────────────────────┐
  (claim trigger)  │   insurance-claim       │───→│  insurance-payment    │
              ───→ │   - policy lookup       │    │  - USDC pool balance  │
                   │   - parametric eval     │    │  - payout execution   │
                   │   - checks-effects-int. │    │  - SAC disbursement   │
                   └────────────┬────────────┘    └──────────┬────────────┘
                                │ policy read                │ USDC to wallet
                                ▼                            ▼
                   ┌─────────────────────────┐    ┌──────────────────────┐
  Farmer enrolls   │   insurance-policy      │    │  Stellar Wallet      │
  via USSD / App   │   - policy registry     │    │  → SEP-24 withdrawal │
              ───→ │   - lifecycle mgmt      │    │  → MoneyGram agent   │
                   │   - trigger params      │    │  → NGN cash payout   │
                   └─────────────────────────┘    └──────────────────────┘
```

### Dependency model

- `insurance-policy` — core registry, no dependencies on other contracts
- `insurance-claim` — depends on `insurance-policy` for policy lookup, calls `insurance-payment` for payout execution
- `insurance-payment` — tracks supported assets and insurance pool balance, notifies `insurance-claim` on payout completion
- `parametric-oracle` — standalone data contract; read by the Laravel backend, which supplies verified data to `insurance-claim`

---

## End-to-End Flow: Farmer to Payout

This section describes a complete parametric insurance lifecycle — from policy purchase to cash in hand — to demonstrate how every layer of the Stellar integration connects.

### Step 1 — Policy purchase and on-chain registration

A farmer in Benue State accesses Riwe via USSD (`*384#`) or the mobile app. They select a maize insurance product for their GPS-tagged farm polygon covering the 2025 growing season. Premium is collected via MTN Mobile Money through Paystack (NGN).

The Laravel backend calls `StellarSmartContractService::createPolicy()`, which invokes the `insurance-policy` Soroban contract. The policy is stored on-chain with:
- farm GPS coordinates
- crop type
- coverage amount in USDC
- parametric trigger conditions (e.g. NDVI < 0.3 for 14 consecutive days)
- policy term dates

A USDC equivalent of the premium is deposited into the `insurance-payment` pool via `processPayment()`.

### Step 2 — In-season satellite monitoring

Throughout the growing season, Sentinel Hub Copernicus satellite data (NDVI, EVI, rainfall telemetry) is retrieved by the Acurast off-chain oracle network. Acurast processors run in a Trusted Execution Environment (TEE), ensuring data cannot be tampered with between retrieval and on-chain submission.

The Acurast processor signs the data payload with its authorised ed25519 key and submits it to the `parametric-oracle` Soroban contract. The oracle contract:
1. Verifies the submitter is on the authorised allow-list
2. Verifies the ed25519 signature
3. Checks the data freshness timestamp
4. Checks the confidence score meets the minimum threshold
5. Stores the verified payload in Soroban Persistent storage

### Step 3 — Trigger detection and claim evaluation

The Laravel backend's `insurance:process-parametric-claims` artisan command runs on a schedule. It reads oracle data from the `parametric-oracle` contract and evaluates it against active policy trigger conditions.

When a trigger condition is met, the backend invokes `StellarSmartContractService::processParametricPayout()`, which calls `insurance-claim` with:
- the policy ID
- the verified oracle data payload
- the claim amount

The `insurance-claim` contract:
1. Looks up the policy in `insurance-policy`
2. Confirms the policy is active and within term
3. Validates the oracle data (freshness, confidence, signature)
4. Updates claim state before external calls (CHECKS-EFFECTS-INTERACTIONS pattern)
5. Calls `insurance-payment` to execute the USDC payout

### Step 4 — USDC settlement and last-mile disbursement

`insurance-payment` transfers USDC from the insurance pool to the farmer's Stellar wallet address. The transaction is recorded on Stellar with full traceability.

The farmer is notified via SMS. They initiate a cash withdrawal through the Riwe app or by visiting a MoneyGram agent. The withdrawal flow uses **SEP-24** (interactive withdrawal) with **SEP-10** wallet authentication:

1. Farmer authenticates with their Stellar wallet via SEP-10 challenge/response
2. MoneyGram's SEP-24 anchor initiates the interactive withdrawal flow
3. USDC is converted to NGN at the prevailing exchange rate
4. NGN is disbursed at the MoneyGram agent point within 48 hours

The full lifecycle — from satellite trigger to cash in hand — is completed in under 48 hours. Every step is traceable on Stellar.

---

## Contract Inventory

### Current source-defined smart contracts: 4

#### 1. InsurancePolicyContract

- **Package:** `contracts/insurance-policy`
- **Main file:** `contracts/insurance-policy/src/contract.rs`
- **Responsibilities:**
  - Initialise policy system configuration
  - Create policies with parametric trigger parameters and GPS farm data
  - Manage policy lifecycle (active, expired, suspended)
  - Store and query policy data in Soroban Persistent storage
  - Expose contract configuration for cross-contract reads

#### 2. InsuranceClaimContract

- **Package:** `contracts/insurance-claim`
- **Main file:** `contracts/insurance-claim/src/contract.rs`
- **Responsibilities:**
  - Accept claim submissions from the authorised backend caller
  - Validate claimant and policy status via `insurance-policy`
  - Evaluate parametric trigger conditions against supplied oracle data
  - Enforce CHECKS-EFFECTS-INTERACTIONS ordering on payout calls
  - Approve or reject claims and record outcomes
  - Trigger payouts through `insurance-payment`

#### 3. InsurancePaymentContract

- **Package:** `contracts/insurance-payment`
- **Main file:** `contracts/insurance-payment/src/contract.rs`
- **Responsibilities:**
  - Accept premium payments and maintain the insurance pool balance
  - Track supported tokens (USDC as primary settlement asset)
  - Execute USDC payout to farmer Stellar wallets on claim approval
  - Use Stellar Asset Contract (SAC) operations for USDC transfers
  - Notify `insurance-claim` on payout completion

#### 4. ParametricOracleContract

- **Package:** `contracts/parametric-oracle`
- **Main file:** `contracts/parametric-oracle/src/contract.rs`
- **Responsibilities:**
  - Maintain the authorised oracle source allow-list (Acurast processor keys)
  - Accept signed environmental data submissions (NDVI, EVI, rainfall)
  - Verify ed25519 signatures on submitted data
  - Enforce data freshness and confidence score thresholds
  - Store verified data in Soroban Persistent storage for downstream reads
  - Expose retained data for claim evaluation

### Shared crate

`contracts/shared` is a support library used across all four contracts. It contains shared domain models, validation helpers, error types, event definitions, and common business rules. It is not a deployable contract.

---

## Stellar Ecosystem Integration

This section explicitly maps Riwe's use of Stellar ecosystem protocols and tools.

### SEP-10 — Stellar Web Authentication

Used for wallet authentication throughout the application. Farmers and partner institutions authenticate with their Stellar keypair via a challenge/response flow. The Laravel backend verifies SEP-10 JWT tokens before authorising contract invocations or fiat withdrawals.

### SEP-24 — Interactive Anchor Deposit and Withdrawal

The primary mechanism for farmer payouts. After USDC settles to a farmer's Stellar wallet:

1. The Riwe app initiates a SEP-24 withdrawal with the MoneyGram anchor
2. MoneyGram's interactive flow handles KYC verification and exchange rate confirmation
3. USDC is debited from the farmer's Stellar wallet
4. NGN is disbursed at a MoneyGram agent point

SEP-24 is also used for premium collection — Nigerian farmers can deposit NGN via Paystack, which is converted to USDC and credited to their Stellar wallet for premium payment.

### SEP-6 — Programmatic Anchor Deposit and Withdrawal

Used for B2B partner integrations (insurers, MFBs) where interactive flows are not required. Partner institutions can programmatically deposit into and withdraw from the insurance pool without a browser-based interaction.

### USDC on Stellar

USDC is the primary settlement asset throughout the contract suite. The `insurance-payment` contract uses Stellar Asset Contract (SAC) operations for USDC transfers. USDC was chosen because:
- It is stable (no farmer exposure to crypto volatility)
- It is natively supported by MoneyGram's SEP-24 anchor
- It is the primary asset for Stellar ecosystem DeFi integrations
- It is auditable by reinsurers and regulators as a standard stablecoin

### MoneyGram Access

Riwe integrates MoneyGram's Stellar-native Access product for last-mile NGN disbursement. MoneyGram operates as a SEP-24 anchor on Stellar, enabling USDC-to-NGN conversion and cash disbursement through its agent network across Nigeria, Kenya, and Ghana — the same markets where Riwe operates.

### Horizon and Soroban RPC

The Laravel backend connects to Stellar via:
- **Horizon** (`https://horizon.stellar.org`) for account queries, transaction submission, and event streaming
- **Soroban RPC** (`https://soroban-mainnet.stellar.org`) for smart contract deployment, invocation, and state reads

---

## Oracle Architecture — Acurast + Sentinel Hub

The oracle layer is one of the most technically differentiated components of the Riwe protocol. This section explains the full data pipeline from satellite to smart contract.

### Why a decentralised oracle matters for insurance

The credibility of parametric insurance depends entirely on the integrity of the trigger data. If a single party controls the oracle, they can manipulate payouts — approving claims that should be rejected or denying legitimate claims. This is the trust problem that makes traditional parametric insurance expensive and contentious.

Riwe's oracle architecture removes this single point of control.

### Data source — Sentinel Hub (Copernicus)

Sentinel Hub provides API access to Copernicus satellite data, including:
- **NDVI** (Normalised Difference Vegetation Index) — measures crop health and drought stress
- **EVI** (Enhanced Vegetation Index) — provides complementary vegetation health signal
- **Weather telemetry** — rainfall accumulation, temperature anomaly

Data is retrieved at farm polygon level using the GPS coordinates stored in the `insurance-policy` contract. Sentinel Hub provides daily revisit coverage across all of sub-Saharan Africa.

### Off-chain processing — Acurast TEE network

Acurast is a decentralised compute network where processors run inside hardware Trusted Execution Environments (TEEs). A TEE is a hardware-enforced secure enclave: code running inside it cannot be observed or modified by the processor operator, the host operating system, or any external party.

The Riwe oracle workflow on Acurast:

1. An Acurast processor job is configured to retrieve Sentinel Hub NDVI and rainfall data for each active policy's farm polygon on a defined schedule
2. The processor retrieves data inside the TEE — the raw satellite API response is processed in a tamper-resistant environment
3. The processor signs the output payload with its authorised ed25519 keypair (registered in the `parametric-oracle` allow-list)
4. The signed data is submitted to the `parametric-oracle` Soroban contract via an `InvokeHostFunction` transaction

This architecture means no single party — not Riwe, not the processor operator, not Acurast — can fabricate or alter a satellite data submission without breaking the cryptographic signature.

### On-chain verification — parametric-oracle contract

When a data submission arrives at the `parametric-oracle` contract:

```
Acurast TEE processor
        │
        │  ed25519-signed payload
        │  { farm_id, ndvi, rainfall, timestamp, confidence }
        ▼
parametric-oracle contract
        │
        ├── verify submitter is in authorised allow-list
        ├── verify ed25519 signature
        ├── check timestamp freshness (reject stale data)
        ├── check confidence score ≥ minimum threshold
        │
        └── store in Soroban Persistent storage
                │
                └── available for insurance-claim evaluation
```

### Fallback and resilience

In the event of Acurast node downtime during an active trigger window, the system has two safeguard mechanisms:

1. **Verified Sentinel Hub fallback feed** — Riwe maintains a secondary verified data pipeline directly from Sentinel Hub that can be submitted by an authorised admin key. All fallback submissions are logged and auditable. The threshold for activating the fallback is defined in the oracle contract configuration.

2. **Grace period in policy terms** — payout SLAs are defined as 48 hours from oracle confirmation, not from the climate event itself. This decouples the payout timeline from oracle latency.

---

## Contract Deployment

### Standard deployment model

```
1. cargo build --target wasm32-unknown-unknown --release
        │
        ▼
2. soroban contract deploy --wasm [artifact] --source [key] --network [target]
        │
        ▼
3. soroban contract invoke --id [contract_id] -- initialize [config]
        │
        ▼
4. Contract IDs exported to contracts/.env.deployed
```

### Build configuration

The workspace uses an optimised release profile in `contracts/Cargo.toml`:

```toml
[profile.release]
opt-level = "z"          # Minimise WASM size
overflow-checks = true   # Deterministic arithmetic
strip = "symbols"        # Reduce artifact size
panic = "abort"          # Lean WASM output
codegen-units = 1        # Tighter optimisation
lto = true               # Link-time optimisation
```

### Deployment order

The deployment script (`contracts/scripts/deploy.sh`) deploys and initialises contracts in dependency order:

1. `insurance-policy` — no dependencies, deployed first
2. `insurance-payment` — initialised with USDC token address
3. `insurance-claim` — initialised with policy and payment contract addresses
4. `parametric-oracle` — initialised with admin key and initial oracle allow-list

Contract IDs are written to `contracts/.env.deployed` and consumed by the Laravel configuration layer.

### Live deployment status

Both the `insurance-policy` and `insurance-claim` contracts are deployed and functional on **Stellar Testnet**:

- Policy contract: `CCRXGROY4THHIB7QRGMJHBXXN7TPMVEYGBBEFVKGWQXOYH4RHJDB3SHR`
- Claims contract: `CCFYJDOFQAQT5DVB2UNU4SWOXMVFLLVWNG47J6G5ZPQGPDMRWSXO75WQ`

The primary remaining gap addressed by this SCF submission is activating the Acurast-powered oracle pipeline and integrating it with the deployed claim contract, enabling fully automated satellite-triggered payouts.

---

## Application Integration

### Laravel/PHP integration layer

The smart contracts are orchestrated through three PHP service classes:

```php
// Contract invocation and lifecycle
app/Services/StellarSmartContractService.php
    - invokeContract(contractId, method, params)
    - createPolicy(policyData)
    - submitClaim(claimData)
    - processParametricPayout(claimId, parametricData)

// Stellar SDK operations and account management
app/Services/StellarSdkContractService.php

// Network and contract configuration
config/stellar.php
```

### Runtime orchestration

The Laravel layer is responsible for:

1. Retrieving and validating oracle data from the `parametric-oracle` contract
2. Formatting arguments for Soroban contract invocations
3. Managing deployed contract IDs from configuration
4. Updating application database records after on-chain state changes
5. Triggering parametric claim processing via scheduled artisan commands:

```bash
php artisan insurance:process-parametric-claims
```

---

## Security Features

### Authorization

All state-changing contract operations require `require_auth()` validation:
- Claim submission requires claimant authorization
- Payment operations require payer or contract authorization
- Oracle submissions require an authorised Acurast processor key
- Admin configuration updates require explicit admin authorization

### Input validation

Contracts validate all inputs before state transitions:
- Positive amount checks on all payment operations
- Policy status checks before claim acceptance
- Oracle data freshness and confidence threshold checks
- GPS coordinate and farm ID validation
- Supported token checks on payment operations

### CHECKS-EFFECTS-INTERACTIONS

The `insurance-claim` contract applies the standard CHECKS-EFFECTS-INTERACTIONS pattern on payout flows: claim state is updated before the cross-contract call to `insurance-payment`, preventing re-entrancy-style vulnerabilities.

### Oracle data integrity

The `parametric-oracle` contract enforces:
- Authorised allow-list verification for every submission
- `ed25519_verify` signature checks on every data payload
- Confidence score enforcement (minimum threshold configurable)
- Data freshness enforcement (maximum age configurable)
- Retention period management for submitted data

### Explicit error taxonomy

```rust
Unauthorized
InvalidStatus
InvalidAmount
ClaimNotFound
OracleNotAuthorized
PolicyExpired
InsufficientPoolBalance
StaleOracleData
ConfidenceThresholdNotMet
```

---

## Performance & Optimization

### WASM build optimisations

The release profile settings minimise contract size and maximise determinism — important for Soroban's fee model, where larger WASM artifacts cost more to deploy.

### Contract modularity benefits

Splitting into four contracts rather than a single monolith:
- Reduces per-contract complexity and WASM size
- Allows focused upgrades without redeploying the full suite
- Improves auditability — each contract has a single clear responsibility
- Localises storage, reducing unnecessary state coupling

### Storage responsibility model

| Contract | Owns |
|---|---|
| `insurance-policy` | Policy records, trigger configurations |
| `insurance-claim` | Claim submissions, decisions, outcomes |
| `insurance-payment` | Pool balance, payout ledger, supported assets |
| `parametric-oracle` | Verified data submissions, oracle allow-list |

---

## Testing & Validation

### Contract validation checklist

The following should be verified at each development milestone:

- [ ] Contract compilation to WASM (`cargo test` at ≥90% unit test coverage)
- [ ] Successful deployment to Stellar Testnet
- [ ] Initialization with valid addresses and cross-contract configuration
- [ ] End-to-end payout flow: policy → oracle data → claim → USDC transfer
- [ ] Oracle submission and ed25519 signature verification
- [ ] Premium collection and insurance pool accounting
- [ ] SEP-10 wallet authentication integration
- [ ] SEP-24 withdrawal flow with MoneyGram test anchor

### Testnet contracts

Both deployed Testnet contracts can be verified on [Stellar Expert](https://stellar.expert/explorer/testnet):
- Policy: `CCRXGROY4THHIB7QRGMJHBXXN7TPMVEYGBBEFVKGWQXOYH4RHJDB3SHR`
- Claims: `CCFYJDOFQAQT5DVB2UNU4SWOXMVFLLVWNG47J6G5ZPQGPDMRWSXO75WQ`

---

## Build Readiness

### What is already built

| Component | Status |
|---|---|
| `insurance-policy` Soroban contract | ✅ Deployed on Testnet |
| `insurance-claim` Soroban contract | ✅ Deployed on Testnet |
| `insurance-payment` contract | ✅ Source complete |
| `parametric-oracle` contract | ✅ Source complete |
| Laravel `StellarSmartContractService` | ✅ Integrated |
| SEP-10 wallet authentication | ✅ Integrated |
| MoneyGram SEP-24 service layer | ✅ Integrated |
| Sentinel Hub data retrieval | ✅ Operational off-chain |

### What this SCF submission funds

| Deliverable | Description |
|---|---|
| T1 — Contract suite completion | Production-grade Rust contracts with ≥90% unit test coverage |
| T1 — Protocol interaction CLI | End-to-end lifecycle simulation in local Soroban sandbox |
| T1 — MoneyGram UX/UI architecture | High-fidelity Figma for SEP-10 and SEP-24 farmer flows |
| T2 — Testnet deployment | All four contracts deployed; public dApp at riwe.io |
| T2 — Acurast oracle activation | Live Sentinel Hub → TEE → parametric-oracle pipeline |
| T2 — Partner underwriting console | SEP-7 dashboard for insurers and MFB partners |
| T3 — Mainnet deployment | SDF-audited contracts; TypeScript SDK; NPM package |
| T3 — Institutional onboarding | Leadway Assurance and NIA-member bank integrations |
| T3 — Infrastructure monitoring | Datadog stack at riwe.io/status |

### Team

- **CTO** (ex-Kuda): Rust/Soroban contract development, Stellar SDK integration, backend architecture
- **Senior Backend Engineer**: Soroban contract implementation, oracle pipeline
- **Mid Blockchain Engineer**: Contract testing, Testnet deployment, SDK development
- **QA/DevOps**: Sandbox simulation, CI/CD, Mainnet monitoring
- **Product Designer**: SEP-24 UX flows, farmer-facing interface

---

## Known Inconsistencies

### Legacy `simple_insurance` reference

The application layer references a legacy artifact path:

```
contracts/target/wasm32v1-none/release/simple_insurance.wasm
```

This appears in `app/Services/StellarSmartContractService.php` and `config/stellar.php`. It is a carry-over from an earlier single-contract prototype.

The active architecture is the 4-contract modular suite described in this document. Removing the legacy reference is a T2 cleanup task. Contract count for SCF purposes: **4 current source-defined contracts**.

---

## Relevant Files

### Contract workspace
- `contracts/Cargo.toml`
- `contracts/Cargo.lock`

### Deployable contract packages
- `contracts/insurance-policy/src/contract.rs`
- `contracts/insurance-claim/src/contract.rs`
- `contracts/insurance-payment/src/contract.rs`
- `contracts/parametric-oracle/src/contract.rs`

### Shared support code
- `contracts/shared/src/*`

### Deployment and operations
- `contracts/scripts/deploy.sh`
- `contracts/.env.deployed`

### Application integration
- `app/Services/StellarSmartContractService.php`
- `app/Services/StellarSdkContractService.php`
- `config/stellar.php`

### Related documentation
- [System Architecture](./System-Architecture.md)
- [Contract Specifications](./Contract-Specifications.md)
- [DeFi Wallet and MoneyGram Claims Payout](./DeFi-and-Moneygram-Claims-Payout.md)
- [Technical Architecture (GitHub)](https://github.com/Riwe-Technologies/Stellar/blob/main/Technical-Architecture.md)

---

*Riwe Technologies Limited · RC 1899524 · riwe.io*
