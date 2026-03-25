# Soroban Smart Contracts Overview
## Riwe Technologies — Parametric Climate Insurance on Stellar

**Related documentation:** [System Architecture →](./System-Architecture.md) · [Contract Specifications](./Contract-Specifications.md)
* **[View Rust Codes](./Contracts/)** The Rust-based smart contracts defining the on-chain protocol logic.

---

## Table of Contents

1. [Why Stellar](#why-stellar)
2. [What the Contracts Do — In Plain Terms](#what-the-contracts-do)
3. [Contract Architecture](#contract-architecture)
4. [End-to-End Flow: Farmer to Payout](#end-to-end-flow)
5. [Contract Inventory](#contract-inventory)
6. [Stellar Ecosystem Integration](#stellar-ecosystem-integration)
7. [Oracle Architecture — Acurast and Sentinel Hub](#oracle-architecture)
8. [Contract Deployment](#contract-deployment)
9. [Application Integration](#application-integration)
10. [Security](#security)
11. [Testing](#testing)
12. [Build Status](#build-status)
13. [Known Issues](#known-issues)
14. [Relevant Files](#relevant-files)

---

## Why Stellar

Riwe provides parametric climate insurance to smallholder farmers across Africa. When a drought or flood is detected by satellite, an enrolled farmer receives a payout automatically — no claims adjuster, no paperwork, no bank account required.

We evaluated multiple blockchain platforms before committing to Stellar. The decision came down to three things that are specific to our use case, not general arguments about speed or cost.

**MoneyGram already operates as a SEP-24 anchor on Stellar.** This was the most important single factor. The hardest problem in last-mile insurance is not the smart contract — it is converting a digital payout into cash that a farmer in Benue State can physically collect. MoneyGram's agent network solves that problem, and it is already integrated with Stellar via the SEP-24 protocol. We did not need to build a custom fiat bridge. That infrastructure existed and it covered Nigeria, Kenya, and Ghana — exactly our target markets.

**Soroban's deterministic execution fits parametric insurance.** Parametric insurance has one rule: if the trigger condition is met, the payout fires. The same satellite data and the same threshold must always produce the same outcome. There is no room for discretion or dispute. Soroban's execution model guarantees this. The contract does not have an opinion — it applies the rule.

**USDC on Stellar gives insurers and reinsurers an auditable settlement trail.** Leadway Assurance and any reinsurer we work with can independently verify every policy, every oracle submission, and every payout on Stellar without asking us for records. The pool balance is on-chain. The payout transactions are on-chain. Farmers never touch USDC — they pay in NGN and collect NGN cash — but the institutional counterparties can see the full settlement chain.

---

## What the Contracts Do — In Plain Terms

Before the technical detail, here is what each contract does in operational language:

| Contract | What it does for a farmer |
|---|---|
| `insurance-policy` | Records that Adaeze's farm in Benue State is insured for the 2026 maize season, with a drought trigger set at NDVI below 0.3 |
| `parametric-oracle` | Receives Sentinel Hub satellite data confirming that Adaeze's farm polygon recorded NDVI 0.21 on August 14th |
| `insurance-claim` | Evaluates the trigger condition, confirms the oracle data is fresh and properly signed, and authorises a payout |
| `insurance-payment` | Releases USDC from the insurance pool to Adaeze's Stellar wallet, which she then withdraws as NGN cash through a MoneyGram agent |

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
└── shared/                 # Shared types, validation helpers, error definitions
```

We split this into four contracts rather than one monolith for a specific reason: Leadway Assurance and any future reinsurer needs to be able to audit settlement logic and fund custody independently. If the payment pool and the claim decision logic live in the same contract, there is no clean separation between who controls the money and who decides when it moves. Keeping them separate means an insurer can review `insurance-payment` in isolation without needing to understand the claim evaluation logic, and vice versa.

### How the contracts connect

```
                    ┌─────────────────────────┐
  Satellite data    │   parametric-oracle     │  ← Acurast TEE pushes
  from Sentinel Hub │   - oracle allow-list   │    verified NDVI and
  via Acurast ───→  │   - ed25519 sig verify  │    rainfall data
                    │   - freshness checks    │
                    │   - Persistent storage  │
                    └────────────┬────────────┘
                                 │ oracle data read by backend
                                 ▼
  Laravel backend  ┌─────────────────────────┐    ┌───────────────────────┐
  (claim trigger)  │   insurance-claim       │───→│  insurance-payment    │
              ───→ │   - policy lookup       │    │  - USDC pool balance  │
                   │   - parametric eval     │    │  - payout execution   │
                   │   - checks-effects-int  │    │  - SAC disbursement   │
                   └────────────┬────────────┘    └──────────┬────────────┘
                                │ policy read                │ USDC to wallet
                                ▼                            ▼
                   ┌─────────────────────────┐    ┌──────────────────────┐
  Farmer enrolls   │   insurance-policy      │    │  Stellar Wallet      │
  via USSD or app  │   - policy registry     │    │  → SEP-24 withdrawal │
              ───→ │   - lifecycle state     │    │  → MoneyGram agent   │
                   │   - trigger parameters  │    │  → NGN cash payout   │
                   └─────────────────────────┘    └──────────────────────┘
```

One thing worth noting on the oracle flow: `insurance-claim` does not directly call `parametric-oracle`. The Laravel backend reads oracle data from the oracle contract, verifies it looks correct, and supplies it to the claim contract as a payload. This keeps the claim contract's logic clean and testable in isolation, while the oracle's own verification (allow-list check, ed25519 signature, freshness) still happened on-chain before the data was stored.

### Dependency model

- `insurance-policy` has no dependencies on other contracts. It is deployed first.
- `insurance-claim` depends on `insurance-policy` for policy lookups and calls `insurance-payment` to execute payouts.
- `insurance-payment` tracks the USDC pool and handles transfers. It does not call other contracts.
- `parametric-oracle` is standalone. The backend reads from it; no contract calls it directly.

---

## End-to-End Flow: Farmer to Payout

### Policy purchase

A farmer in Benue State enrolls via USSD (`*384#`) or the mobile app. They select maize coverage for their GPS-tagged farm polygon covering the 2026 growing season. Premium is collected via MTN Mobile Money through Paystack.

The Laravel backend calls `StellarSmartContractService::createPolicy()`, which invokes `insurance-policy` on Stellar. The policy is stored on-chain with the farm GPS coordinates, crop type, coverage amount in USDC, parametric trigger conditions (NDVI below 0.3 for 14 consecutive days), and season dates. A USDC equivalent of the premium is deposited into the `insurance-payment` pool.

### In-season monitoring

Throughout the growing season, Sentinel Hub satellite data is retrieved by the Acurast off-chain oracle network. Acurast processors run inside hardware Trusted Execution Environments — the data cannot be tampered with between retrieval and on-chain submission.

The processor signs the payload with its registered ed25519 key and submits to `parametric-oracle`. The contract checks the submitter is on the allow-list, verifies the signature, checks data freshness, and checks the confidence score before storing in Soroban Persistent storage.

### Trigger detection

The Laravel backend runs `php artisan insurance:process-parametric-claims` on a schedule. It reads oracle data from `parametric-oracle` and evaluates it against active policy trigger conditions.

When a trigger is met, the backend calls `insurance-claim` with the policy ID, the verified oracle payload, and the claim amount. The claim contract looks up the policy, validates the oracle data again, updates claim state, then calls `insurance-payment` to execute the USDC transfer to the farmer's Stellar wallet.

### Last-mile disbursement

The farmer is notified by SMS. The designed disbursement flow — a T2 deliverable — works as follows:

1. Farmer authenticates their Stellar wallet via SEP-10 challenge/response
2. MoneyGram's SEP-24 anchor initiates the interactive withdrawal flow
3. USDC is converted to NGN at the prevailing rate
4. NGN is disbursed at the nearest MoneyGram agent point within 48 hours

The UX/UI designs for this complete flow are delivered in T1. The live SEP-24 integration with the MoneyGram anchor is built and validated in T2.

---

## Contract Inventory

### insurance-policy

**Location:** `contracts/insurance-policy/src/contract.rs`

Stores and manages policy records. Every policy created through the Riwe platform has a corresponding on-chain entry here. The contract handles policy creation, lifecycle transitions (active, expired, suspended, cancelled), and provides the lookup interface that `insurance-claim` uses before accepting a claim submission.

The separation of policy registry from claim logic means an insurer auditing our portfolio can query policy state independently of claim processing state.

### insurance-claim

**Location:** `contracts/insurance-claim/src/contract.rs`

The coordination point of the contract suite. Accepts claim submissions from the authorised backend caller, evaluates parametric trigger conditions against the supplied oracle payload, enforces the CHECKS-EFFECTS-INTERACTIONS pattern on payout calls, and coordinates with `insurance-payment` to execute the USDC transfer.

The CHECKS-EFFECTS-INTERACTIONS pattern matters here specifically because `insurance-claim` makes a cross-contract call to `insurance-payment`. Claim state is updated to approved before the external call is made. This prevents a class of re-entrancy vulnerabilities where a malicious contract could re-enter the claim flow mid-execution.

### insurance-payment

**Location:** `contracts/insurance-payment/src/contract.rs`

Holds the insurance pool and executes USDC transfers. Premium payments flow in via `process_premium()`. Claim payouts flow out via `process_claim_payout()` using Stellar Asset Contract (SAC) operations. The pool balance is on-chain and auditable by anyone.

The reason we use SAC operations rather than a custom token implementation is straightforward: USDC on Stellar already has established issuer trust, regulatory standing, and compatibility with MoneyGram's SEP-24 anchor. Building our own settlement token would introduce issuer risk and complexity we do not need.

### parametric-oracle

**Location:** `contracts/parametric-oracle/src/contract.rs`

The trust anchor for parametric claim evaluation. Accepts signed satellite data submissions from registered Acurast TEE processors, enforces ed25519 signature verification and confidence score thresholds, and stores verified data in Soroban Persistent storage for downstream reads.

The allow-list is the critical security boundary here. Only registered Acurast processor keys can submit data. If an unauthorised key attempts a submission, the contract rejects it with `OracleNotAuthorized` before touching any state.

### Shared crate

`contracts/shared` is a support library used across all four contracts. It contains shared domain types, validation helpers, error codes, and event definitions. It is not deployable.

---

## Stellar Ecosystem Integration

### SEP-10 — Stellar Web Authentication

All wallet operations in the application use SEP-10 for authentication. Farmers and partner institutions authenticate with their Stellar keypair via a challenge/response flow. The Laravel backend verifies SEP-10 JWT tokens before authorising contract invocations or fiat withdrawals. SEP-10 backend integration is a T2 deliverable.

### SEP-24 — Interactive Anchor Deposit and Withdrawal

The mechanism for farmer payouts. Once USDC settles to a farmer's Stellar wallet, SEP-24 handles the interactive MoneyGram withdrawal flow — KYC verification, exchange rate confirmation, agent selection, and NGN disbursement. The UX/UI designs are the T1 Deliverable 3. Backend integration with the SEP-24 anchor is T2 Deliverable 1.

### SEP-6 — Programmatic Anchor Flows

Planned for B2B partner integrations where interactive browser-based flows are not appropriate. Insurers and MFBs will be able to programmatically deposit into and withdraw from the insurance pool. Part of the T2 backend deliverable.

### USDC on Stellar

USDC is the primary settlement asset throughout the contract suite. We chose it because it is stable, it is natively supported by MoneyGram's SEP-24 anchor, and it is auditable by reinsurers as a standard stablecoin. The `insurance-payment` contract uses Stellar Asset Contract operations for all USDC transfers.

### MoneyGram

MoneyGram operates as a SEP-24 anchor on Stellar and has agent coverage across Nigeria, Kenya, and Ghana — exactly the markets we operate in. The live SEP-24 integration with the MoneyGram anchor is the T2 Deliverable 3, validated end-to-end with a video walkthrough.

---

## Oracle Architecture — Acurast and Sentinel Hub

### Why Acurast

The current oracle model is backend-mediated: our Laravel application retrieves NDVI data from Sentinel Hub and submits it to `parametric-oracle` as an authorised operator. It works, but any party verifying a claim trigger has to trust Riwe as the sole data source. For an insurance product, that trust model is a problem. Leadway Assurance cannot independently verify that the satellite data we submitted is the satellite data that was actually retrieved.

Acurast runs the same Sentinel Hub retrieval inside a hardware TEE. The attestation proves the data was fetched and signed in a tamper-resistant environment. The attestation is verifiable on-chain by any counterparty without trusting us. The data source does not change. The trust model does.

This is also why we chose Acurast over Chainlink. Chainlink's integration with Stellar is focused on cross-chain interoperability and price data feeds — not geospatial parametric triggers. Chainlink does not provide verified NDVI readings for GPS farm polygons. Acurast's job-based architecture maps directly to our periodic satellite telemetry ingestion pattern.

### Data source — Sentinel Hub

Sentinel Hub provides API access to Copernicus satellite data including NDVI, EVI, and rainfall telemetry. Data is retrieved at farm polygon level using GPS coordinates stored in the `insurance-policy` contract. Sentinel Hub provides daily revisit coverage across sub-Saharan Africa.

### On-chain verification

When a data submission arrives at `parametric-oracle`:

```
Acurast TEE processor
        │
        │  ed25519-signed payload
        │  { farm_id, ndvi, rainfall, timestamp, confidence }
        ▼
parametric-oracle contract
        │
        ├── verify submitter is in authorised allow-list
        ├── verify ed25519 signature on the payload
        ├── check timestamp is within DataRetentionPeriod
        ├── check confidence score meets minimum threshold
        │
        └── store in Soroban Persistent storage
```

### Fallback

If an Acurast node goes down during an active trigger window, we maintain a secondary verified Sentinel Hub pipeline that can be submitted by an authorised admin key. All fallback submissions are logged on-chain. The grace period in policy terms is 48 hours from oracle confirmation, not from the climate event, which decouples payout timing from oracle latency.

---

## Contract Deployment

```bash
# Build
cargo build --target wasm32-unknown-unknown --release

# Deploy
soroban contract deploy --wasm [artifact] --source [key] --network testnet

# Initialise
soroban contract invoke --id [contract_id] -- initialize [config]
```

Deployment order: `insurance-policy` first (no dependencies), then `insurance-payment`, then `insurance-claim`, then `parametric-oracle`. The deployment script at `contracts/scripts/deploy.sh` handles this in the correct order and writes resulting contract IDs to `contracts/.env.deployed`.

### Build configuration

```toml
[profile.release]
opt-level = "z"
overflow-checks = true
strip = "symbols"
panic = "abort"
codegen-units = 1
lto = true
```

`overflow-checks = true` is important for insurance arithmetic. An unchecked overflow in a payout calculation producing a wrong USDC amount would be a serious production incident.

### Live Testnet IDs

- Policy: `CCRXGROY4THHIB7QRGMJHBXXN7TPMVEYGBBEFVKGWQXOYH4RHJDB3SHR`
- Claims: `CCFYJDOFQAQT5DVB2UNU4SWOXMVFLLVWNG47J6G5ZPQGPDMRWSXO75WQ`

Verifiable at [stellar.expert/explorer/testnet](https://stellar.expert/explorer/testnet).

---

## Application Integration

The contracts are orchestrated through the Laravel service layer:

```php
// All Soroban contract interaction
app/Services/StellarSmartContractService.php
    - invokeContract(contractId, method, params)
    - createPolicy(policyData)
    - submitClaim(claimData)
    - processParametricPayout(claimId, parametricData)

// Network and contract configuration
config/stellar.php
```

The scheduled processing command that drives parametric claims:

```bash
php artisan insurance:process-parametric-claims
```

This command reads oracle data, evaluates trigger conditions across all active policies, and invokes the claim contracts for any policies where the threshold is met.

---

## Security

### Authorization

All state-changing contract operations require `require_auth()`. Claim submission requires claimant authorisation. Payment operations require payer or contract authorisation. Oracle submissions require a registered Acurast processor key. Admin config updates require explicit admin authorisation.

### Oracle data integrity

The `parametric-oracle` contract enforces four checks on every submission in order: allow-list membership, ed25519 signature verification, data freshness, and minimum confidence score. If any check fails the submission is rejected before any state is written.

### CHECKS-EFFECTS-INTERACTIONS

Applied in `insurance-claim` on the payout flow. State is updated to approved before the cross-contract call to `insurance-payment`. This ordering matters because `insurance-claim` calls an external contract, and without it a re-entrancy-style attack could potentially trigger multiple payouts for a single claim.

### Error taxonomy

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

No silent failures. Every rejected operation returns a specific error code that the backend logs and the application layer can act on.

---

## Testing

Validation checklist at each milestone:

- [ ] `cargo test` at minimum 90% unit test coverage across all four contracts
- [ ] Successful deployment to Stellar Testnet
- [ ] Initialisation with valid cross-contract addresses
- [ ] End-to-end payout flow: policy creation, oracle submission, claim evaluation, USDC transfer
- [ ] Oracle submission with ed25519 signature verification
- [ ] Premium collection and pool accounting
- [ ] SEP-10 wallet authentication integration (T2)
- [ ] SEP-24 withdrawal flow with MoneyGram test anchor (T2)

---

## Build Status

| Component | Status |
|---|---|
| `insurance-policy` | Live on Testnet |
| `insurance-claim` | Live on Testnet |
| `insurance-payment` | Source complete, T2 deployment |
| `parametric-oracle` | Source complete, T2 deployment |
| Laravel `StellarSmartContractService` | Integrated |
| SEP-10 wallet authentication | T2 |
| MoneyGram SEP-24 integration | T2 |
| Mainnet deployment | T3 |

---

## Known Issues

### Legacy `simple_insurance` reference

The application layer references a legacy artifact path `contracts/target/wasm32v1-none/release/simple_insurance.wasm` in `StellarSmartContractService.php` and `config/stellar.php`. This is a carry-over from an early single-contract prototype and does not reflect the current four-contract architecture. Removing it is a T2 cleanup task.

---

## Relevant Files

### Contract workspace
- `contracts/Cargo.toml`
- `contracts/Cargo.lock`

### Contracts
- `contracts/insurance-policy/src/contract.rs`
- `contracts/insurance-claim/src/contract.rs`
- `contracts/insurance-payment/src/contract.rs`
- `contracts/parametric-oracle/src/contract.rs`
- `contracts/shared/src/*`

### Deployment
- `contracts/scripts/deploy.sh`
- `contracts/.env.deployed`

### Application integration
- `app/Services/StellarSmartContractService.php`
- `config/stellar.php`

---

*Riwe Technologies Limited · riwe.io · partnerships@riwe.io*
