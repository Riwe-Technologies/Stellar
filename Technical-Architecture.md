# Technical Architecture
## Parametric Climate Insurance on Stellar

Related: [System-Architecture.md](./System-Architecture.md) · [Contract-Specifications.md](./Contract-Specifications.md) · [Soroban-Smart-Contracts-Overview.md](./Soroban-Smart-Contracts-Overview.md) · [DeFi-and-Moneygram-Claims-Payout.md](./DeFi-and-Moneygram-Claims-Payout.md) · [Moneygram-Integration.md](./Moneygram-Integration.md)

---

## Contents

- [What Stellar uniquely enables](#what-stellar-uniquely-enables)
- [Platform baseline](#platform-baseline)
- [Architecture principles](#architecture-principles)
- [System topology](#system-topology)
- [Stellar integration](#stellar-integration)
- [Soroban contract architecture](#soroban-contract-architecture)
- [Contract naming reference](#contract-naming-reference)
- [Oracle architecture](#oracle-architecture)
- [Oracle failure modes and recovery](#oracle-failure-modes-and-recovery)
- [Parametric claims processing](#parametric-claims-processing)
- [Wallet and settlement model](#wallet-and-settlement-model)
- [MoneyGram integration](#moneygram-integration)
- [Underwriting and regulatory model](#underwriting-and-regulatory-model)
- [Current implementation maturity](#current-implementation-maturity)
- [Operational data model](#operational-data-model)
- [Security and observability](#security-and-observability)
- [Planned roadmap](#planned-roadmap)

---

## What Stellar uniquely enables

Soroban's deterministic execution model is the right fit for parametric insurance. The same oracle data and trigger conditions must always produce the same claim outcome. No discretion, no dispute, no adjuster. Soroban guarantees that.

**Transparent, auditable risk records.** Every policy issuance, oracle submission, and claim payout is anchored on the Stellar ledger. Licensed insurer partners can independently verify the full history of any policy they have underwritten without calling us. This is the audit trail that traditional parametric insurance cannot offer.

**Micro-policy economics.** The average Riwe policy covers a smallholder farm with a crop value of $200 to $800. A $3 premium policy is only economically viable when the settlement fee is a fraction of a cent. Stellar's fee model, currently under $0.0001 per operation, is the reason micro-insurance at this scale works. It does not work on Ethereum or Polygon at current fee levels.

**SAC-based atomic payouts.** Soroban's Stellar Asset Contract standard lets the `insurance-payment` contract hold and transfer USDC in a single atomic on-chain operation with no bridge, no wrapped token, and no custodian approval step. When a parametric trigger fires, the SAC transfer executes in the same transaction as the claim decision. This is what reduces settlement from the current industry sandard of 2 to 4 weeks to juust under 48 hours.

**SEP-24 last-mile settlement.** MoneyGram's SEP-24 anchor is a Stellar-native protocol. USDC settled on Stellar flows directly into the MoneyGram withdrawal flow without any cross-chain conversion. A farmer receives NGN cash at a local agent point from a payout that originated as a Soroban contract invocation. Based on research, we dont believe any other major chain has this path at production scale in Nigeria today.

**SEP-10 trustless authentication.** Farmers and partner institutions authenticate to the protocol using their Stellar keypair via SEP-10 Web Auth, a challenge-response signature scheme that requires no password, no OTP, and no custodial account. This is the authentication primitive that allows USSD and WhatsApp flows to be cryptographically verified without a smartphone.


---

## Platform baseline

| Layer | Baseline |
|---|---|
| Application framework | Laravel 12 |
| Language runtime | PHP 8.5, Rust, Python |
| Primary data store | PostgreSQL |
| API style | REST, Sanctum-protected API groups plus authenticated web routes |
| Blockchain settlement network | Stellar |
| Smart contract framework | Soroban (Rust) under `/contracts` |
| Contract suite | `insurance-policy` · `insurance-claim` · `insurance-payment` · `parametric-oracle` |
| Fiat rails | Paystack (bank and mobile money) · MoneyGram (USDC cash ramps via SEP-24) |
| Climate data provider | Sentinel Hub via Copernicus Data Space Ecosystem (NDVI, EVI, weather telemetry) |
| Decentralised oracle layer | Acurast TEE-based compute (T2) |
| Oracle integrity layer | Merkle-batched data submission with on-chain root verification |

---

## Architecture principles

**Backend-mediated orchestration.** The backend coordinates policy state, external data, wallet state, provider callbacks, and contract invocation. It is important to know that the frontend is not the integration point for Soroban or provider webhooks.

**Custodial settlement, fiat-facing UX.** Claim proceeds settle in USDC to a managed custodial Stellar wallet on the farmer's behalf. The farmer never interacts with USDC directly. They only gget alerted via sms about a payout and a refrence ID pointing them to the nearest agent. Their experience is entirely in local currency which in this case is aira (NGN). MoneyGram converts and disburses at the agent point. The USDC layer is internal to the settlement infrastructure.

**Insurer-underwritten risk, not decentralised capital.** It is important to note that we do not pool capital on-chain at this point as decentralised capital is futuristic based on regulatory approvals. For now, licensed insurance companies underwrite the risk on their own balance sheets. Riwe provides the parametric trigger infrastructure, oracle layer, and settlement rail. The smart contracts enforce the payout rules that the insurer has agreed to at underwriting. They do not replace the insurer. Once a trigger event happens, we auto payout a fraction of the total claims to the farmer wallet whcih can be withdrawn isntatly while we wait for our underwriters to disburse re3maindr claims to user wallet which can then be withdrawn via our agent network. Our approach allows underwriting partners audit settlement logic and fund custody independently, which is a standard requirement for licensed insurers operating under NAICOM supervision.


**Fiat rails are application integrations, not smart contracts.** Paystack and MoneyGram are backend service integrations managed entirely from the application layer. They are not on-chain components.

**TEE-verified oracle inputs, Merkle-auditable batches.** Oracle data arrives on-chain only via Acurast's TEE processor network. Individual farm readings are batched into Merkle trees before submission. The on-chain Oracle contract stores the Merkle root. Any counterparty can verify that a specific farm's NDVI reading was included in a verified batch without replaying the full dataset.

---

## System topology

```
+------------------------------------------------------------------+
|             Client Channels                                      |
|   Web App · Mobile App · USSD · WhatsApp                        |
+------------------------------------------------------------------+
                          |
                          v
+------------------------------------------------------------------+
|             Laravel Application Layer                            |
|                                                                  |
|  StellarService          StellarWalletService                    |
|  StellarSmartContractService  StellarClaimService                |
|  MoneyGramRampsService   PaystackService                         |
|  ParametricClaimService  NotificationService                     |
+------------------------------------------------------------------+
        |                     |                    |
        v                     v                    v
+---------------+   +------------------+   +--------------+
| PostgreSQL    |   | Soroban Contract |   | MoneyGram    |
|               |   | Suite            |   | (SEP-24)     |
|               |   |                  |   +--------------+
|               |   | insurance-policy |
|               |   | insurance-claim  |         |
|               |   | insurance-payment|         v
|               |   | parametric-oracle|   +--------------+
+---------------+   +------------------+   | Paystack     |
                           |               | (NGN rails)  |
                           v               +--------------+
                   +------------------+
                   | Stellar Network  |
                   | (Horizon + RPC)  |
                   +------------------+
                           ^
                           |
+------------------------------------------------------------------+
|             Oracle Layer                                         |
|                                                                  |
|  Acurast TEE Nodes (minimum quorum: 3)                           |
|       |                                                          |
|       v                                                          |
|  Sentinel Hub (NDVI, EVI) + ERA5 / OpenMeteo (weather)          |
|       |                                                          |
|       v                                                          |
|  Merkle Tree Batch --> submit_batch(root, attestation_proof)     |
+------------------------------------------------------------------+
```

### Service responsibilities

| Service | Role |
|---|---|
| `StellarService` | Account creation · Friendbot funding (testnet) · balance reads · payments · trustlines · network config |
| `StellarWalletService` | Wallet lifecycle · AES-256 encrypted secret handling · balance reads · payment dispatch |
| `StellarSmartContractService` | Contract ID config · `invokeContract()` / `queryContract()` · policy/claim/payment invocation surfaces |
| `StellarClaimService` | Trigger evaluation · claim submission · payout orchestration · wallet settlement |
| `MoneyGramRampsService` | SEP-24 deposit/withdrawal initiation · webhook-driven status updates · sandbox and production handling |
| `ParametricClaimService` | Daily automated trigger checking · claim creation · confidence scoring · payout routing |

---

## Stellar integration

### Network profiles

`config/stellar.php` supports `testnet`, `mainnet`, and `futurenet`. Key config values include `stellar.default_network`, per-network Horizon and Soroban RPC endpoints, `stellar.insurance_contract_id` for all four contracts, and mainnet hardening settings under `security.mainnet_security`.

### `StellarWalletService`

```php
public function createWallet(User $user, bool $fundTestnet = true): StellarWallet
```

Generates a keypair, encrypts the secret key using Laravel `Crypt` (AES-256-CBC), optionally funds via Friendbot on testnet, and persists to the database. Secret keys are never exposed in API responses and are decrypted only at the point of transaction signing.

### `StellarSmartContractService`

Core invocation surfaces:

```php
public function invokeContract(string $contractId, string $method, array $params): array
public function queryContract(string $contractId, string $method, array $params): array
public function createPolicy(array $policyData): array
public function submitClaim(array $claimData): array
public function processParametricPayout(string $claimId, array $parametricData): array
```

Contract IDs are sourced from environment-backed values in `config/stellar.php` under `stellar.insurance`.

---

## Soroban contract architecture

### Live testnet contract IDs

| Contract | Role | Testnet ID |
|---|---|---|
| `insurance-policy` | Policy creation, registry, lifecycle state | `CCRXGROY4THHIB7QRGMJHBXXN7TPMVEYGBBEFVKGWQXOYH4RHJDB3SHR` |
| `insurance-claim` | Claim submission, validation, decision logic | `CCFYJDOFQAQT5DVB2UNU4SWOXMVFLLVWNG47J6G5ZPQGPDMRWSXO75WQ` |
| `insurance-payment` | Premium escrow, payout orchestration, SAC settlement | Delivered in T1 |
| `parametric-oracle` | Authorised oracle input, Merkle root storage, TEE attestation verification | Delivered in T1 |

### Contract interaction sequence

```
+---------------------------+
| Acurast TEE Oracle Nodes  |
| (min. 3 node quorum)      |
+---------------------------+
             |
             | submit_batch(merkle_root, polygon_readings[],
             |              timestamps[], attestation_proof)
             v
+---------------------------+
| parametric-oracle         |
| - verify TEE attestation  |
| - enforce quorum          |
| - store Merkle root       |
| - open 24hr challenge     |
|   window                  |
+---------------------------+
             |
             | get_latest_reading(polygon_id, merkle_proof)
             v
+---------------------------+
| Laravel Backend           |
| (ParametricClaimService)  |
+---------------------------+
             |
             | process_parametric_claim(policy_id, oracle_payload,
             |                          merkle_proof)
             v
+---------------------------+
| insurance-claim           |
| - verify Merkle proof     |
| - evaluate triggers       |
| - validate policy state   |
+---------------------------+
             |
             | process_claim_payout(policy_id, amount)
             v
+---------------------------+
| insurance-payment         |
| - SAC transfer            |
| - USDC to custodial       |
|   Stellar wallet          |
+---------------------------+
             |
             | result / status
             v
+---------------------------+
| Laravel Backend           |
| - reconcile records       |
| - initiate MoneyGram      |
|   withdrawal              |
| - notify farmer           |
+---------------------------+
```

### On-chain security

```rust
// All state-changing calls require caller authorisation
require_auth!(&env, &caller);

// Oracle submissions verified against registered Acurast processor allow-list
let allowed = env.storage().get(&DataKey::AllowedProcessors).unwrap();
assert!(allowed.contains(&oracle_id), Error::UnauthorizedOracle);

// TEE attestation proof verified on every oracle submission
env.crypto().ed25519_verify(&oracle_key, &payload_bytes, &signature);

// Merkle proof verified before any reading is used in a trigger decision
verify_merkle_proof(&root, &leaf, &proof);

// CEI pattern enforced throughout
// CHECK policy state -> EFFECT claim update -> INTERACT with payment contract
```

---

## Contract naming reference

| Application name | Contract(s) | Role |
|---|---|---|
| Policy Factory | `insurance-policy` | Risk position minting and policy lifecycle |
| Climate Oracle | `parametric-oracle` | Verifiable environmental data ingestion with Merkle batching |
| Claim Engine | `insurance-claim` + `insurance-payment` | Trigger validation and automated SAC disbursement |

The Claim Engine presents as a single settlement primitive to end users. `insurance-claim` handles trigger validation. `insurance-payment` holds the USDC pool and executes the SAC transfer. Keeping them separate lets underwriting partners audit settlement logic and fund custody independently, which is a standard requirement for licensed insurers operating under NAICOM supervision.

---

## Oracle architecture

### Our choice for Acurast

We reviewed the Stellar oracle ecosystem. Reflector Network, Band, and DIA are price feed oracles. They do not serve climate and satellite data. Acurast was the only production-available decentralised compute option on Stellar that can execute arbitrary off-chain jobs inside a hardware Trusted Execution Environment. The data source does not change. We still pull Satelite data from Sentinel Hub. The trust model does: instead of trusting us to report the data honestly, any counterparty can verify the TEE attestation proof on-chain.

### TEE attestation

The Satellite data retrieval, NDVI aggregation, and payload signing all run inside a hardware secure enclave. Neither our team nor Acurast operators can observe or tamper with execution after the job is deployed. The TEE generates an attestation proof, a cryptographic certificate from the hardware manufacturer confirming the code ran in a genuine enclave. The `parametric-oracle` contract checks this proof before accepting any submission. A payload without a valid TEE attestation is rejected at the contract level.

### Merkle-batched submissions for Efficiency

```
Observation cycle
       |
       v
+-------------------------------+
| Acurast TEE Job               |
| Pull Sentinel Hub readings    |
| for all active farm polygons  |
+-------------------------------+
       |
       v
+-------------------------------+
| Build Merkle tree             |
|                               |
| Root                          |
|  / \                          |
| H1  H2                        |
| /\  /\                        |
|L1 L2 L3 L4                    |
|                               |
| Leaf = hash(polygon_id,       |
|             ndvi, timestamp)  |
+-------------------------------+
       |
       | submit_batch(root, attestation_proof)
       v
+-------------------------------+
| parametric-oracle contract    |
| stores root on-chain          |
+-------------------------------+
       |
       | Later: claim evaluation
       v
+-------------------------------+
| Backend passes:               |
| reading + Merkle proof        |
|                               |
| Contract verifies:            |
| proof against stored root     |
| -> reading is authenticated   |
+-------------------------------+
```

Individual farm readings are not submitted one by one as this is ineffective. Instaed, each Acurast oracle cycle aggregates all active farm polygon readings into a Merkle tree. The Oracle contract stores only the 32-byte root. Full leaf data is stored off-chain in our data layer. When the backend uses a reading in a claim decision, it passes the reading plus a Merkle proof to the contract. The contract verifies the proof against the stored root confirming the reading was part of the verified oracle batch without storing every farm's data on-chain.

This approach provides three properties. Gas efficiency: storing a 32-byte root instead of thousands of readings. Auditability: any counterparty can independently verify any specific farm reading by requesting its Merkle proof. Tamper evidence: a single altered reading invalidates the Merkle root, making post-hoc data manipulation detectable.

### Minimum quorum

The Oracle contract enforces a minimum quorum of 3 independent Acurast TEE nodes before accepting any batch submission. Fewer than 3 attestation-valid submissions within an observation window are discarded. Riwe registers and maintains at least 2 nodes as protocol-side redundancy.

---

## Oracle failure modes and recovery

### Failure mode 1: Cloud cover gaps

Sentinel Hub optical imagery is subject to cloud obstruction and satellite availability. When cloud coverage exceeds 20% over a farm polygon for a given observation window, the NDVI reading is flagged as incomplete. The system falls back to ERA5 reanalysis weather data, a model-derived dataset not subject to optical obstruction, for the weather-based trigger components (rainfall, temperature). NDVI-based triggers are suspended for the affected window. The policy term extends by the length of the missed window. Partners and farmers are notified via the dApp status dashboard.

### Failure mode 2: Oracle node failure or quorum not reached

If quorum cannot be reached within the observation window buffer (default: 7 days, also configurable per policy), affected policies are auto moved to a suspended state. No trigger fires. No premium is forfeited. The policy term extends by the missed window duration. This is enforced at the contract level. There is no code path in our `insurance-claim` that accepts an oracle payload that was not quorum-validated.

### Failure mode 3: Tampered payload

Each Acurast node's TEE attestation is verifiable on-chain. The Oracle contract checks attestation proofs before accepting any submission. A payload without a valid proof is rejected. For the residual risk of coordinated multi-node compromise, the Oracle contract maintains a 24-hour challenge window after every submission. During this window the trigger is in `pending` state and no settlement executes. Any on-chain observer, including underwriting partners or third-party auditors, can flag the submission for human review. After 24 hours without a challenge, settlement proceeds automatically.

### Trigger methodology

A parametric trigger fires when a verified oracle reading crosses a predefined threshold written into the policy at issuance. Thresholds are crop-specific and farm-location-specific, derived from the EcoCrop database and historical Sentinel Hub data for the polygon. The trigger logic runs in `insurance-claim` and is not editable by Riwe after policy creation.

| Trigger | Parameter | Data source |
|---|---|---|
| Rainfall deficit | Annual mm below crop minimum | ERA5 + OpenMeteo |
| Rainfall excess | Daily mm above flood threshold | ERA5 + OpenMeteo |
| Extreme heat | Days above crop temperature ceiling | OpenMeteo |
| NDVI decline | NDVI below crop health threshold | Sentinel Hub via Acurast |
| Drought index | Composite index above threshold | ERA5 + FEWS NET |

---

## Parametric claims processing

### Daily automated monitoring

```
05:00 AM -- insurance:process-parametric-claims (scheduled Artisan command)
                          |
                          v
             Get all active parametric policies
                          |
                          v
              For each policy in active state:
                          |
          +---------------+---------------+
          |                               |
          v                               v
  Fetch oracle reading           Fetch weather data
  for farm polygon               (OpenMeteo + ERA5)
  (Sentinel Hub NDVI)
          |                               |
          +---------------+---------------+
                          |
                          v
          Check readings against policy trigger thresholds
                          |
            +-------------+-------------+
            |                           |
            v                           v
     [No trigger]               [Trigger exceeded]
            |                           |
     Continue                           v
     monitoring          Create claim record
                         (incident_type, amount,
                          oracle_data, trigger_data,
                          merkle_proof)
                                        |
                                        v
                         Calculate confidence score:
                           Data source reliability   40%
                           Trigger severity          30%
                           Multi-source agreement    20%
                           Historical pattern match  10%
                                        |
                         +--------------+--------------+
                         |                             |
                         v                             v
                 [Score >= 80%]               [Score < 80%]
                         |                             |
                         v                             v
             Automatic payout via           Route to insurer
             smart contract                 review queue
                         |
                         v
             USDC to custodial wallet
                         |
                         v
             MoneyGram SEP-24 withdrawal
                         |
                         v
             NGN cash at local agent
                         |
                         v
             Notify farmer:
             WhatsApp + SMS + in-app
```

### Payout calculation

```php
// Severity-based payout percentage
foreach ($exceededTriggers as $trigger) {
    $severity = $trigger['severity'];
    if ($severity >= 80)      $percentageToPay += 40;   // Extreme
    elseif ($severity >= 60)  $percentageToPay += 30;   // High
    elseif ($severity >= 40)  $percentageToPay += 20;   // Medium
    else                      $percentageToPay += 10;   // Low
}
$percentageToPay = min(100, $percentageToPay);
$payoutAmount    = ($coverageAmount * $percentageToPay) / 100;
```

---

## Wallet and settlement model

```
+---------------------------+
| Farmer                    |
| (NGN experience only)     |
+---------------------------+
             |
             | Policy purchase / claim collection
             v
+---------------------------+
| Riwe dApp / USSD /        |
| WhatsApp                  |
+---------------------------+
             |
             v
+---------------------------+
| Riwe-managed custodial    |
| Stellar wallet            |
| (AES-256 encrypted keys)  |
+---------------------------+
             |
             | USDC settlement (on-chain, atomic)
             v
+---------------------------+
| insurance-payment         |
| (SAC transfer)            |
+---------------------------+
             |
             | MoneyGram SEP-24 withdrawal
             v
+---------------------------+
| MoneyGram agent network   |
| NGN cash disbursement     |
+---------------------------+
```

| Wallet mode | Key management |
|---|---|
| Custodial (farmer wallets) | AES-256 encrypted private keys in PostgreSQL via Laravel `Crypt` |
| Self-custodial (WalletPlus) | Device-bound storage, biometric authentication |
| Critical pool operations | HSM integration (T3) |
| Account recovery | SEP-30 multi-factor recovery (T3) |

---

## MoneyGram integration

MoneyGram is a backend service integration. It is not an on-chain component at this stage. It sits after wallet settlement.

### Payout sequence

```
1. USDC settles to Riwe-managed custodial wallet on Stellar (on-chain, atomic)
       |
2. Farmer taps "Collect Payout" in app or initiates via USSD
   Farmer sees NGN amount only
       |
3. Backend calls MoneyGramRampsService::initiateWithdrawal()
   using custodially-managed Stellar keypair for SEP-10 authentication
       |
4. MoneyGram converts USDC to NGN
   Routes to nearest agent in farmer's location
       |
5. Webhook fires with `completed` status
       |
6. Backend marks claim as `disbursed`
   Farmer notified via WhatsApp and SMS
```

### Webhook status mapping

| MoneyGram status | Local status |
|---|---|
| `pending_user_transfer_start` | `pending` |
| `pending_anchor` / `pending_stellar` / `pending_external` | `processing` |
| `pending_trust` / `pending_user` | `pending` |
| `completed` | `completed` |
| `error` / `incomplete` | `failed` |

### Current configuration

| Setting | Value |
|---|---|
| Default environment | Sandbox simulation by this submission |
| USDC issuer (testnet) | `GBBD47IF6LWK7P7MDEVSCWR7DPUWV3NY3DTQEVFL4NAT4AQH3ZLLFLA5` |
| USDC issuer (mainnet) | `GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN` |
| Deposit limits | 5 USDC min / 950 USDC max |
| Withdrawal limits | 5 USDC min / 2,500 USDC max |

---

## Underwriting and regulatory model

Riwe does not underwrite or hold insurance risk on its balance sheet. We are a parametric insurance infrastructure provider. Risk is underwritten by licensed insurance companies regulated by NAICOM (National Insurance Commission of Nigeria).

Our underwriting partners review and sign off on the parametric trigger thresholds for each product before it goes live. Trigger parameters are locked into the Soroban policy contract at issuance. Riwe cannot modify them after the fact. This gives underwriting partners and regulators a tamper-evident record of exactly what was agreed at the point of policy creation.

Premium pricing is validated by underwriting partner actuarial teams before each product launch. Trigger thresholds are calibrated against 20+ years of Sentinel Hub historical NDVI data and ERA5 weather reanalysis for each farm polygon, cross-referenced against EcoCrop database crop-specific environmental tolerance ranges.

Distribution partners, including commercial banks and microfinance institutions regulated by the Central Bank of Nigeria, distribute policies to their farmer customer bases and collect premiums through existing mobile money and bank account infrastructure.

---

## Current implementation maturity

| Surface | Status |
|---|---|
| Contract IDs, Policy and Claims | Live on Stellar testnet |
| `invokeContract()` / `queryContract()` | Live |
| Policy creation via `createPolicy()` | Live, calls `insurance-policy` contract |
| Premium processing via `processPremiumPayment()` | T1 deliverable, currently returns placeholder |
| Claim submission via `submitClaim()` | T1 deliverable, currently returns placeholder |
| Parametric payout via `processParametricPayout()` | T1 deliverable, currently returns placeholder |
| `insurance-payment` contract | T1 deliverable |
| `parametric-oracle` contract with Merkle root storage | T1 deliverable |
| Acurast TEE oracle pipeline | T2 deliverable, replaces backend-mediated submission |
| MoneyGram SEP-24 | Sandbox-first. Routes, service class, and webhook handler exist. Live anchor in T2 |
| SEP-10 authentication | T2 deliverable |
| Mainnet deployment | T3 deliverable, post-SDF audit |

---

## Operational data model

| Table | Purpose |
|---|---|
| `stellar_wallets` | Custodial wallet records and AES-256 encrypted key metadata |
| `insurance_policies` | Off-chain policy state mirrored against on-chain contract identifiers |
| `claims` | Claim records, trigger data, oracle context, Merkle proofs, processing metadata |
| `stellar_smart_contracts` | Contract references, deployment and linking metadata |
| `weather_alerts` | Farm-level early warning alerts with severity, recommendations, and resolution state |
| `crop_parametric_triggers` | Crop-specific environmental thresholds used in trigger evaluation |

---

## Security and observability

**Application-layer controls:**

- Custodial private keys encrypted with AES-256 via Laravel `Crypt`. 
- Per-environment Stellar network selection. Testnet and mainnet cannot be mixed accidentally
- Webhook secret validation on all MoneyGram callbacks
- Sanctum token authentication on all insurance API routes
- SEP-10 JWT verification on all Stellar-touching operations (T2)
- Oracle allow-list enforced at the contract level. Only registered Acurast processor IDs can submit
- TEE attestation proof required on every oracle submission
- 24-hour challenge window on every oracle batch before settlement executes

**Observability:**

- Laravel logging channels for Stellar operations and contract invocations
- Provider-context logging for MoneyGram initiation and webhook events
- `insurance:process-parametric-claims` scheduled at 05:00 daily
- `wallet:sync-balances` scheduled at 04:00 daily for Stellar balance reconciliation
- Datadog monitoring stack planned for T3 at `riwe.io/status`

---

## Planned roadmap

### T1: Contract logic completion and UX foundation

- Replace placeholder returns in `processPremiumPayment()`, `submitClaim()`, and `processParametricPayout()` with real on-chain invocations
- Complete `insurance-payment` contract: USDC pool management, SAC transfer logic, escrow and payout flows
- Complete `parametric-oracle` contract: Merkle root storage, TEE attestation verification, quorum enforcement, 24-hour challenge window logic
- Achieve >=90% `cargo test` coverage across all four contracts
- Protocol CLI: full lifecycle simulation (Escrow -> Trigger -> Payout) in local Soroban sandbox
- MoneyGram SEP-24 UX/UI Figma designs: minimum 8 screens covering authentication, policy purchase, claim trigger, and MoneyGram withdrawal

### T2: Testnet deployment and oracle activation

- Deploy all four contracts to Stellar Testnet. Contract IDs published on Stellar.expert
- Activate Acurast TEE oracle pipeline: Merkle-batched Sentinel Hub data, quorum validation, on-chain root storage
- Integrate SEP-10 authentication, SEP-6 deposits, and SEP-24 withdrawals on the backend
- Deploy partner underwriting console with SEP-7 support and risk pool visualisation
- Full E2E lifecycle test with >=90% GitHub Actions CI coverage
- MoneyGram SEP-24 test anchor simulation with recorded end-to-end video walkthrough

### T3: Mainnet production launch

- SDF audit and Mainnet deployment of all four contracts
- SEP-1 `stellar.toml` configuration for protocol discovery
- HSM integration for critical pool key management
- SEP-30 account recovery for partner-managed wallets
- Public TypeScript SDK and NPM package at `riwe.io/developers`
- Institutional onboarding of underwriting and distribution partners to Mainnet protocol
- Datadog monitoring stack at `riwe.io/status`
- Mainnet launch report with quantified pilot outcomes
