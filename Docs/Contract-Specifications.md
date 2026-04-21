# Smart Contract Specifications
## Riwe Technologies — Parametric Climate Insurance on Stellar

**Related documentation:** [Soroban Smart Contracts Overview ←](./Soroban-Smart-Contracts-Overview.md) · [System Architecture ←](./System-Architecture.md) · [DeFi-Wallet-System.md](./DeFi-Wallet-System.md)

* **[View Rust Codes](./Contracts/)** The Rust-based smart contracts defining the on-chain protocol logic.

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture and Contract Relationships](#architecture-and-contract-relationships)
3. [Cross-Contract Call Sequence](#cross-contract-call-sequence)
4. [Contract Specifications](#contract-specifications)
5. [Live Contract Addresses](#live-contract-addresses)
6. [Deployment Specifications](#deployment-specifications)
7. [Storage Key Reference](#storage-key-reference)
8. [Security Risks and Mitigations](#security-risks-and-mitigations)
9. [Mainnet Readiness Checklist](#mainnet-readiness-checklist)
10. [Governance and Compliance](#governance-and-compliance)
11. [Operational Notes](#operational-notes)

---

## Overview

Riwe's on-chain implementation is a modular four-contract Soroban suite deployed on Stellar Testnet. Mainnet deployment is the T3 SCF deliverable.

| Contract | Role | Testnet Status |
|---|---|---|
| `insurance-policy` | Policy registry and lifecycle management | Live |
| `insurance-claim` | Claim evaluation and payout authorisation | Live |
| `insurance-payment` | Premium collection and USDC payout execution | T2 deliverable |
| `parametric-oracle` | Acurast-verified satellite data ingestion | T2 deliverable |

Together these contracts implement the complete on-chain layer: a farmer buys a policy, satellite data confirms a climate event, and USDC is automatically released to the farmer's Stellar wallet. No adjuster, no paperwork, no bank account required.

This document is the authoritative specification for the four-contract suite. All application references and deployment configurations should treat this as the canonical source.

---

## Architecture and Contract Relationships

### Contract Responsibility Summary

| Contract | Owns | Calls |
|---|---|---|
| `insurance-policy` | Policy records, lifecycle state, trigger configuration | — |
| `insurance-claim` | Claim submissions, decisions, payout authorisations | `insurance-policy`, `insurance-payment` |
| `insurance-payment` | USDC pool balance, payment records, payout execution | — |
| `parametric-oracle` | Verified satellite data submissions, oracle allow-list | — |

The contracts are split by business domain rather than built as a monolith for a practical reason: Leadway Assurance needs to be able to audit settlement logic and fund custody independently of claim decision logic. Keeping `insurance-payment` separate from `insurance-claim` means an insurer can review fund movements without needing to understand parametric trigger evaluation, and vice versa.

### Architecture Flow

```
Farmer / Policyholder
        │
        ▼
Laravel Backend / Operator
        │
        ├── create_policy() ──────────────────────→ insurance-policy
        │                                                   │ stores policy record
        │                                                   │ on-chain
        │
        ├── process_premium() ──────────────────→ insurance-payment
        │                                                   │ credits pool balance
        │
        ├── submit_claim() ─────────────────────→ insurance-claim
        │                                           │
        │                                           └── get_policy() → insurance-policy
        │
Acurast TEE Processor
        │  ed25519-signed payload
        ▼
parametric-oracle
        │  stores verified data in Persistent storage
        │
        ▼
Laravel Backend reads oracle data
        │
        ▼
insurance-claim
        │  evaluates trigger conditions
        │
        └── process_claim_payout() ─────────────→ insurance-payment
                                                        │
                                                        └── SAC transfer
                                                            → farmer Stellar wallet
```

The oracle flow is worth explaining explicitly: `insurance-claim` does not call `parametric-oracle` directly. The Laravel backend reads oracle data from `parametric-oracle`, verifies it looks correct at the application layer, and supplies it to `insurance-claim` as a verified payload. The oracle's own verification — allow-list check, ed25519 signature, freshness — happened on-chain when Acurast submitted the data. This pattern keeps the claim contract testable in isolation while preserving full on-chain verifiability of the underlying satellite data.

### Shared Crate

`contracts/shared` is a Rust support library providing shared domain types, validation helpers, event definitions, and error codes used across all four contracts. It is not a deployable contract and should not be counted as such.

---

## Cross-Contract Call Sequence

```
Farmer
  │
  │ 1. Purchase policy (USSD / app)
  ▼
Laravel Backend
  │
  │ 2. create_policy(farm_polygon, crop, triggers, coverage)
  ▼
insurance-policy → policy_id confirmed on-chain

  │ 3. process_premium(policy_id, payer, amount, USDC)
  ▼
insurance-payment → pool balance updated

  │ 4. submit_claim(policy_id, claimant, amount)
  ▼
insurance-claim
  │ get_policy(policy_id) → insurance-policy
  └── claim_id / submitted status returned to backend

--- Runs continuously throughout the season ---

Acurast TEE Processor
  │ submit_data(location, ndvi, rainfall, timestamp, confidence, signature)
  ▼
parametric-oracle
  [verifies allow-list, ed25519 sig, freshness, confidence threshold]
  [stores in Persistent storage]

--- Trigger condition met ---

Laravel Backend
  │ get_latest_data(farm_location) → parametric-oracle
  │ process_parametric_claim(claim_id, parametric_data)
  ▼
insurance-claim
  │ get_policy(policy_id) → insurance-policy
  │ [validates oracle data, updates claim state]
  │ process_claim_payout(claim_id, farmer_wallet, amount, USDC)
  ▼
insurance-payment
  [SAC transfer: USDC from pool to farmer Stellar wallet]
  └── payout confirmed → claim status → Paid

  │ Farmer notified via SMS
  ▼
SEP-24 MoneyGram withdrawal → NGN cash at agent point (T2 deliverable)
```

---

## Contract Specifications

### 1. insurance-policy

**Role:** Policy registry and lifecycle manager. The foundational contract — all other contracts reference policy state from here.

**Initialise:**
```
initialize(
    admin,
    oracles,
    minimum_confidence_score,
    auto_payout_threshold,
    fee_percentage,
    fee_recipient
)
```

#### Functions

| Function | Description |
|---|---|
| `initialize` | Deploy-time setup: admin key, oracle set, confidence thresholds, fee config |
| `create_policy` | Register a new policy with farm polygon, crop type, season dates, and parametric trigger parameters |
| `activate_policy` | Move a policy from pending to active state |
| `suspend_policy` | Suspend an active policy (admin or compliance action) |
| `cancel_policy` | Cancel a policy and record cancellation reason |
| `expire_policies` | Batch-expire policies past their term end date |
| `get_policy` | Return full policy record by policy ID |
| `get_policy_status` | Return current lifecycle state for a policy |
| `is_policy_active` | Boolean check used by insurance-claim before accepting submissions |
| `get_policies_by_holder` | Return all policy IDs for a given wallet address |
| `get_active_policies` | Return all currently active policy IDs |
| `get_expired_policies` | Return all expired policy IDs |
| `get_policy_count` | Return total policy count |
| `update_config` | Admin-only config update |
| `get_config` | Return current contract configuration |

#### Events

| Event | Trigger |
|---|---|
| `PolicyCreated` | New policy registered on-chain |
| `PolicyActivated` | Policy moved to active state |
| `PolicyExpired` | Policy term end reached |

#### State Keys

| Key | Purpose |
|---|---|
| `Config` | Admin, oracle set, fee configuration |
| `Policy(policy_id)` | Full policy record including farm polygon, triggers, coverage |
| `PolicyCount` | Global policy counter |
| `PoliciesByHolder(address)` | Policy IDs indexed by farmer wallet address |
| `ActivePolicies` | Registry of currently active policy IDs |
| `ExpiredPolicies` | Registry of expired policy IDs |

---

### 2. insurance-claim

**Role:** Claim intake, parametric evaluation, and payout authorisation. The coordination hub of the contract suite.

**Initialise:**
```
initialize(
    admin,
    oracles,
    minimum_confidence_score,
    auto_payout_threshold,
    fee_percentage,
    fee_recipient,
    policy_contract,
    payment_contract
)
```

#### Functions

| Function | Description |
|---|---|
| `initialize` | Deploy-time setup including addresses of policy and payment contracts |
| `submit_claim` | Accept a new claim submission linked to an active policy |
| `process_parametric_claim` | Evaluate parametric trigger conditions against supplied oracle data; approve and trigger payout if threshold met |
| `approve_claim` | Admin-controlled manual approval path |
| `reject_claim` | Reject a claim with a recorded reason |
| `mark_claim_paid` | Confirm payout completion from payment contract |
| `get_claim` | Return full claim record by claim ID |
| `get_claim_status` | Return current claim state |
| `get_claims_by_claimant` | Return all claim IDs for a given farmer wallet address |
| `get_claims_by_policy` | Return all claim IDs for a given policy |
| `get_pending_claims` | Return all claims awaiting evaluation |
| `get_approved_claims` | Return all approved claims |
| `get_rejected_claims` | Return all rejected claims |
| `get_paid_claims` | Return all paid claims |
| `get_claim_count` | Return total claim count |
| `update_config` | Admin-only config update |
| `get_config` | Return current contract configuration |

#### Events

| Event | Trigger |
|---|---|
| `ClaimSubmitted` | New claim accepted on-chain |
| `ClaimApproved` | Claim approved — payout authorised |
| `ClaimRejected` | Claim rejected — reason recorded |
| `ParametricTriggerActivated` | Parametric threshold met — auto-payout initiated |

#### State Keys

| Key | Purpose |
|---|---|
| `Config` | Claim contract admin, oracle set, threshold configuration |
| `Claim(claim_id)` | Full claim record |
| `ClaimCount` | Global claim counter |
| `ClaimsByClaimant(address)` | Claim IDs indexed by farmer wallet address |
| `ClaimsByPolicy(policy_id)` | Claim IDs indexed by policy |
| `PendingClaims` | Claims awaiting evaluation |
| `ApprovedClaims` | Approved claim registry |
| `RejectedClaims` | Rejected claim registry |
| `PaidClaims` | Paid claim registry |
| `PolicyContract` | Address of the `insurance-policy` contract |
| `PaymentContract` | Address of the `insurance-payment` contract |

#### CHECKS-EFFECTS-INTERACTIONS

`process_parametric_claim` follows this pattern strictly:
1. **Checks** — validate policy status, oracle data freshness, confidence score, and claim eligibility
2. **Effects** — update claim state to `Approved` before any external call
3. **Interactions** — call `insurance-payment` to execute the USDC transfer

This ordering is necessary because `insurance-claim` makes a cross-contract call. Updating state before the external call means a re-entrancy attempt cannot trigger a second payout for the same claim.

---

### 3. insurance-payment

**Role:** Premium collection, insurance pool accounting, and USDC payout execution.

**Initialise:**
```
initialize(
    admin,
    oracles,
    minimum_confidence_score,
    auto_payout_threshold,
    fee_percentage,
    fee_recipient,
    policy_contract,
    claim_contract,
    supported_tokens
)
```

#### Functions

| Function | Description |
|---|---|
| `initialize` | Deploy-time setup including policy and claim contract addresses, and initial supported token list |
| `process_premium` | Accept a premium payment and credit the insurance pool balance |
| `process_claim_payout` | Execute a USDC transfer from the insurance pool to a farmer's Stellar wallet address |
| `get_payment` | Return a payment or payout record by payment ID |
| `get_payments_by_payer` | Return all payment IDs for a given payer address |
| `get_payments_by_policy` | Return all payment IDs for a given policy |
| `get_pool_balance` | Return current insurance pool balance per supported asset |
| `get_supported_tokens` | Return list of accepted payment assets |
| `update_config` | Admin-only config update |
| `get_config` | Return current contract configuration |

#### Events

| Event | Trigger |
|---|---|
| `PaymentProcessed` (type: Premium) | Premium deposited into insurance pool |
| `PaymentProcessed` (type: Payout) | USDC payout executed to farmer wallet |

#### State Keys

| Key | Purpose |
|---|---|
| `Config` | Admin, oracle set, fee configuration |
| `Payment(payment_id)` | Individual payment or payout record |
| `PaymentCount` | Global payment counter |
| `PaymentsByPayer(address)` | Payment IDs by payer |
| `PaymentsByRecipient(address)` | Payout IDs by recipient |
| `PaymentsByPolicy(policy_id)` | Payment IDs by policy |
| `PaymentsByClaim(claim_id)` | Payout IDs by claim |
| `PendingPayments` | Payments awaiting processing |
| `CompletedPayments` | Successfully completed payments |
| `FailedPayments` | Failed payment records |
| `PolicyContract` | Address of `insurance-policy` contract |
| `ClaimContract` | Address of `insurance-claim` contract |
| `InsurancePool` | Aggregated pool balance per asset |
| `SupportedTokens` | Allowed payment assets (USDC primary) |

We use Stellar Asset Contract (SAC) operations for USDC transfers rather than a custom token implementation because USDC on Stellar already has established issuer trust, regulatory standing, and direct compatibility with MoneyGram's SEP-24 anchor. A custom settlement token would introduce issuer risk we do not need.

---

### 4. parametric-oracle

**Role:** Acurast TEE oracle data ingestion, verification, and on-chain storage. The trust anchor for parametric claim evaluation.

**Initialise:**
```
initialize(
    admin,
    authorized_oracles,
    data_retention_period,
    minimum_confidence_score
)
```

#### Functions

| Function | Description |
|---|---|
| `initialize` | Deploy-time setup: admin key, initial Acurast oracle allow-list, retention period, minimum confidence score |
| `submit_data` | Accept a signed satellite data submission from an authorised Acurast processor |
| `get_latest_data` | Return the most recent verified submission for a given farm location |
| `get_historical_data` | Return historical submissions for a location within the retention window |
| `get_oracle_submissions` | Return all submission IDs for a given oracle address |
| `get_submission_count` | Return total oracle submission count |
| `add_oracle` | Admin action — add an Acurast processor key to the allow-list |
| `remove_oracle` | Admin action — remove an oracle key from the allow-list |
| `get_config` | Return current oracle contract configuration |
| `update_config` | Admin-only config update |

#### State Keys

| Key | Purpose |
|---|---|
| `Config` | Admin, oracle allow-list, confidence threshold, retention period |
| `Submission(submission_id)` | Full oracle data submission record |
| `SubmissionsByOracle(address)` | Submission IDs indexed by Acurast processor address |
| `SubmissionsByLocation(location)` | Submission IDs indexed by farm GPS location |
| `LatestSubmission(location)` | Most recent verified submission per farm location |
| `SubmissionCount` | Global oracle submission counter |
| `DataRetentionPeriod` | Maximum age of a valid oracle submission |

#### Verification Logic on Every Submission

```
1. Verify submitter address is in the authorised Acurast allow-list
2. Verify ed25519 signature on the data payload
3. Check submission timestamp is within DataRetentionPeriod
4. Check confidence score meets minimum threshold
5. Store verified payload in Soroban Persistent storage
6. Update LatestSubmission(location) index
```

If any check fails, the submission is rejected with a specific error code. No partial writes, no silent failures.

Note on `OracleDataSubmitted` events: explicit `env.events().publish(...)` calls in the current oracle contract source are to be confirmed in the T1 contract delivery.

---

## Live Contract Addresses

### Testnet

| Contract | Contract ID |
|---|---|
| `insurance-policy` | `CCRXGROY4THHIB7QRGMJHBXXN7TPMVEYGBBEFVKGWQXOYH4RHJDB3SHR` |
| `insurance-claim` | `CCFYJDOFQAQT5DVB2UNU4SWOXMVFLLVWNG47J6G5ZPQGPDMRWSXO75WQ` |
| `insurance-payment` | TBD — T2 deliverable |
| `parametric-oracle` | TBD — T2 deliverable |

Both live contracts verifiable at [stellar.expert/explorer/testnet](https://stellar.expert/explorer/testnet).

### Mainnet

No Mainnet deployment exists. Mainnet deployment is the T3 SCF deliverable following SDF contract audit and full Testnet end-to-end validation in T2. Any previously documented Mainnet addresses were placeholders and are not valid.

---

## Deployment Specifications

### Build and Deployment

| Parameter | Value |
|---|---|
| Language | Rust |
| Platform | Soroban on Stellar |
| WASM target | `wasm32v1-none` |
| CLI | `stellar` |
| Network | `testnet` (production: `mainnet` at T3) |
| Soroban RPC | `https://soroban-testnet.stellar.org` |
| Network passphrase | `Test SDF Network ; September 2015` |

### Deployment Order

```
1. insurance-policy    → no dependencies, deployed first
2. insurance-payment   → initialised with policy_contract address
3. insurance-claim     → initialised with policy_contract and payment_contract addresses
4. parametric-oracle   → initialised with admin and initial oracle allow-list
```

### Current Testnet Initialisation Values

```
admin:                    [testnet admin account]
oracles:                  [] (Acurast keys provisioned in T2)
authorized_oracles:       [] (Acurast keys provisioned in T2)
minimum_confidence_score: 70
auto_payout_threshold:    80
fee_percentage:           100
fee_recipient:            [testnet fee recipient account]
data_retention_period:    86400
supported_tokens:         [] (USDC configured in T2)
```

The empty `authorized_oracles` and `supported_tokens` are intentional and reflect current Testnet state. Populating the oracle allow-list with registered Acurast TEE processor keys and configuring the USDC token address are T2 deliverables.

### Application Configuration

Live contract IDs are loaded from `.env` via `config('stellar.insurance.*')`:

```env
STELLAR_POLICY_CONTRACT_ID=CCRXGROY4THHIB7QRGMJHBXXN7TPMVEYGBBEFVKGWQXOYH4RHJDB3SHR
STELLAR_CLAIM_CONTRACT_ID=CCFYJDOFQAQT5DVB2UNU4SWOXMVFLLVWNG47J6G5ZPQGPDMRWSXO75WQ
STELLAR_PAYMENT_CONTRACT_ID=TBD
STELLAR_ORACLE_CONTRACT_ID=TBD
```

---

## Storage Key Reference

### insurance-policy

| Key | Purpose |
|---|---|
| `Config` | Admin, oracle set, fee configuration |
| `Policy(String)` | Full policy record keyed by policy ID |
| `PolicyCount` | Global policy counter |
| `PoliciesByHolder(Address)` | Policy IDs indexed by farmer wallet address |
| `ActivePolicies` | Registry of currently active policy IDs |
| `ExpiredPolicies` | Registry of expired policy IDs |

### insurance-claim

| Key | Purpose |
|---|---|
| `Config` | Admin, oracle set, threshold configuration |
| `Claim(String)` | Full claim record keyed by claim ID |
| `ClaimCount` | Global claim counter |
| `ClaimsByClaimant(Address)` | Claim IDs indexed by claimant wallet address |
| `ClaimsByPolicy(String)` | Claim IDs indexed by policy |
| `PendingClaims` | Claims awaiting evaluation |
| `ApprovedClaims` | Approved claim registry |
| `RejectedClaims` | Rejected claim registry |
| `PaidClaims` | Paid claim registry |
| `PolicyContract` | Address of referenced `insurance-policy` contract |
| `PaymentContract` | Address of referenced `insurance-payment` contract |

### insurance-payment

| Key | Purpose |
|---|---|
| `Config` | Admin and fee configuration |
| `Payment(String)` | Payment or payout record keyed by payment ID |
| `PaymentCount` | Global payment counter |
| `PaymentsByPayer(Address)` | Payments indexed by payer |
| `PaymentsByRecipient(Address)` | Payouts indexed by recipient |
| `PaymentsByPolicy(String)` | Payments indexed by policy |
| `PaymentsByClaim(String)` | Payouts indexed by claim |
| `PendingPayments` | Payments awaiting execution |
| `CompletedPayments` | Successfully executed payments |
| `FailedPayments` | Failed payment records |
| `PolicyContract` | Address of `insurance-policy` contract |
| `ClaimContract` | Address of `insurance-claim` contract |
| `InsurancePool` | Aggregated pool balance per supported asset |
| `SupportedTokens` | List of accepted payment assets |

### parametric-oracle

| Key | Purpose |
|---|---|
| `Config` | Admin key, oracle allow-list, confidence and retention config |
| `Submission(String)` | Full oracle data submission keyed by submission ID |
| `SubmissionsByOracle(Address)` | Submissions indexed by Acurast processor address |
| `SubmissionsByLocation(Location)` | Submissions indexed by farm GPS location |
| `LatestSubmission(Location)` | Most recent verified submission per farm location |
| `SubmissionCount` | Global oracle submission counter |
| `DataRetentionPeriod` | Maximum age of a valid oracle submission |

---

## Security Risks and Mitigations

| Risk | Current Mitigation | Mainnet Requirement |
|---|---|---|
| Unauthorised admin actions | `require_auth()` on all admin-gated functions | Hardened admin key custody, rotation policy, multi-sig for critical operations |
| Fabricated oracle data | Acurast allow-list; ed25519 signature check; confidence threshold; freshness check | Non-empty production oracle set; Acurast key rotation procedure; oracle anomaly monitoring |
| Re-entrancy on payout | CHECKS-EFFECTS-INTERACTIONS in claim contract | End-to-end payout failure and retry scenario coverage; incident response runbook |
| Token misconfiguration | Supported-token whitelist in `insurance-payment` | Whitelist only audited production assets (USDC); validate decimals and issuer |
| Soroban state expiration | TTL renewal helpers included in all contracts | Production TTL monitoring, automated renewal, and state archival procedures |
| Configuration drift | `.env` and `config('stellar.insurance.*')` as runtime source of truth | Signed deployment runbook; post-deploy verification checklist |
| Incomplete event observability | Policy, claim, and payment contracts emit domain events | Confirm `OracleDataSubmitted` events in T1 delivery |
| Network or deployment errors | Deployment script with contract ID persistence to `.env.deployed` | Post-deploy smoke tests; rollback runbook |

---

## Mainnet Readiness Checklist

Before T3 Mainnet deployment:

- [ ] External security audit of the full four-contract suite
- [ ] Provision production Acurast TEE oracle keys and populate `authorized_oracles`
- [ ] Configure supported production tokens (USDC issuer, decimals, treasury accounts) in `insurance-payment`
- [ ] Finalise admin account custody: key management, signer policy, secret rotation
- [ ] Full end-to-end test coverage: policy creation, premium collection, parametric claim, payout, rejection, oracle-driven flows
- [ ] Validate MoneyGram SEP-24 withdrawal flow end-to-end against live anchor
- [ ] Define and validate Soroban RPC and Horizon failover procedures
- [ ] Deploy `stellar.toml` (SEP-1) for protocol discovery
- [ ] Implement SEP-30 account recovery for partner-managed wallets
- [ ] Datadog monitoring: TTL/state health, failed invocations, payout failures, oracle anomalies
- [ ] TypeScript SDK and NPM package published at `riwe.io/developers`
- [ ] Remove legacy `simple_insurance.wasm` references from application code
- [ ] Backend config rollout, environment templates, and operational handoff complete

---

## Governance and Compliance

### On-chain governance

There is no separate on-chain governance contract at this stage. Contract configuration and upgrades are controlled by the admin key established at initialisation. This is appropriate for Testnet and T2. Multi-sig governance is a post-Mainnet consideration depending on protocol adoption and partner requirements.

### Compliance model

KYC/AML, operator review, regulatory reporting, and NAICOM distribution agent status under Leadway Assurance are all handled in the Laravel application layer. The on-chain layer is responsible for deterministic execution, state control, and providing an auditable record of every policy, claim, and payout.

This is a deliberate architectural boundary. The on-chain record is the source of truth for payout verification. The off-chain layer handles the regulated identity and compliance surface. Combining them would complicate contract auditability without adding meaningful security.

---

## Internal Operational Notes

- The Soroban TTL expiration issue on Testnet (disappearing state) was caused by TTL expiration combined with stale Laravel config cache lookups. TTL renewal helpers have been added to all four contracts.
- Runtime integrations must use `config('stellar.insurance.*')` values sourced from `.env`. Hardcoded contract IDs in application code are a deployment risk — they will point to the wrong contracts if the deployment is refreshed.
- Testnet infrastructure should be treated as non-production even when deployments are stable. All load, security, and recovery testing happens on Testnet before any Mainnet deployment.
- The empty `authorized_oracles` list in the current Testnet initialisation is intentional. The oracle contract is deployed and verified. Acurast TEE processor key registration is a T2 deliverable.

---

*Riwe Technologies Limited · riwe.io · partnerships@riwe.io*
