# Technical Architecture
## Riwe Technologies — Parametric Climate Insurance on Stellar

Related: [System-Architecture.md](./System-Architecture.md) · [Contract-Specifications.md](./Contract-Specifications.md) · [DeFi-and-Moneygram-Claims-Payout.md](./DeFi-and-Moneygram-Claims-Payout.md)

---

## Platform Baseline

| Layer | Baseline |
|---|---|
| Application framework | Laravel 10 |
| Language runtime | PHP 8.2 |
| Primary data store | PostgreSQL |
| API style | REST — Sanctum-protected API groups plus authenticated web routes |
| Blockchain settlement network | Stellar |
| Smart contract framework | Soroban (Rust) under `/contracts` |
| Contract suite | `insurance-policy` · `insurance-claim` · `insurance-payment` · `parametric-oracle` |
| Fiat rails | Paystack (bank and mobile money) · MoneyGram (USDC cash ramps) |
| Climate data provider | Sentinel Hub via Copernicus Data Space Ecosystem |
| Decentralised oracle layer | Acurast TEE-based compute (T2) |

---

## Architecture Principles

**Backend-mediated orchestration.** The Laravel backend coordinates policy state, external data, wallet state, provider callbacks, and contract invocation. The frontend is not the integration point for Soroban or provider webhooks.

**Backend-mediated oracle flow.** Authorised oracle data is submitted to `parametric-oracle`. The backend retrieves that oracle data and invokes claim-processing logic using the retrieved oracle context.

**Custodial settlement, fiat-facing UX.** Claim proceeds settle in USDC to a Riwe-managed custodial Stellar wallet on the farmer's behalf. The farmer never interacts with USDC directly — their experience is entirely in NGN. MoneyGram converts and disburses at the agent point. The USDC layer is internal to the settlement infrastructure.

**Fiat rails are application integrations, not smart contracts.** Paystack and MoneyGram are backend service integrations. They are not on-chain components.

---

## System Topology

```
Web and Mobile Clients
        │
        ▼
Laravel Application Layer
        │
        ├── StellarService              → Stellar Network
        ├── StellarWalletService        → Stellar Network
        ├── StellarSmartContractService → Soroban Contract Suite → Stellar Network
        ├── StellarClaimService
        ├── MoneyGramRampsService       → MoneyGram
        ├── PostgreSQL
        ├── Sentinel Hub / Copernicus   (off-chain data retrieval)
        └── Paystack
```

### Service Responsibilities

| Service | Role |
|---|---|
| `StellarService` | Account creation · Friendbot funding · balance reads · payments · trustlines · network config |
| `StellarWalletService` | Wallet lifecycle · encrypted secret handling · balance reads · payment dispatch |
| `StellarSmartContractService` | Contract ID management · `invokeContract()` / `queryContract()` · policy/claim/payment surfaces |
| `StellarClaimService` | Claim submission · trigger evaluation · payout orchestration · wallet settlement |
| `MoneyGramRampsService` | Deposit/withdrawal initiation · webhook-driven transaction status updates |

---

## Stellar Integration

### Network Profiles

`config/stellar.php` supports three network profiles — `testnet`, `mainnet`, and `futurenet`. Key config values include `stellar.default_network`, per-network Horizon and Soroban RPC endpoints, `stellar.insurance.*_contract_id` for all four contracts, and mainnet hardening settings under `security.mainnet_security`.

### `StellarService`

Initialises against `stellar.default_network`. Provides account creation, Friendbot funding (testnet only), account state reads, payment execution, trustline management, and network config exposure for downstream services.

### `StellarWalletService`

- `createWallet(User $user, bool $fundTestnet = true)` — generates a Stellar keypair, encrypts the secret key using Laravel `Crypt`, optionally funds via Friendbot, and persists to the database
- Reads balances, dispatches user-level payments, manages trustlines

### `StellarSmartContractService`

Owns all Soroban contract interaction. Contract IDs are sourced from `config/stellar.php` under `stellar.insurance`. Core surfaces:

```php
public function invokeContract(string $contractId, string $method, array $params): array
public function queryContract(string $contractId, string $method, array $params): array
public function createPolicy(array $policyData): array
public function submitClaim(array $claimData): array
public function processParametricPayout(string $claimId, array $parametricData): array
```

---

## Soroban Contract Architecture

### Live Testnet Contract IDs

| Contract | Role | Testnet ID |
|---|---|---|
| `insurance-policy` | Policy creation, registry, lifecycle state | `CCRXGROY4THHIB7QRGMJHBXXN7TPMVEYGBBEFVKGWQXOYH4RHJDB3SHR` |
| `insurance-claim` | Claim submission, validation, decision logic | `CCFYJDOFQAQT5DVB2UNU4SWOXMVFLLVWNG47J6G5ZPQGPDMRWSXO75WQ` |
| `insurance-payment` | Premium and payout orchestration, SAC settlement | `CAWLYJZHPSZ7YLXGTAPARWEW27GNDQ7ZLJVWW5RKN27XKSOJOGRDPEVT` |
| `parametric-oracle` | Authorised oracle input, retained environmental data | `CBYGCVAFPPYVLKWZE2XQKX6RMPLBCNBZKWOVHTJIJX3LSRNYRZSI7TTM` |

### Contract Interaction Sequence

```
Authorized Oracle
        │  submit_data(polygon_id, ndvi, timestamp, ed25519_sig)
        ▼
parametric-oracle
        │  get_latest_data(polygon_id)
        ▼
Laravel Backend
        │  process_parametric_claim(policy_id, oracle_payload)
        ▼
insurance-claim
        │  validate → process_claim_payout(policy_id, amount)
        ▼
insurance-payment
        │  SAC transfer → Riwe-managed custodial wallet (farmer's account)
        ▼
Laravel Backend  ←  result / status  →  reconcile and notify
```

### Contract Naming Reference

| Application name | Contract(s) | Role |
|---|---|---|
| Policy Factory | `insurance-policy` | Risk position minting and policy lifecycle |
| Climate Oracle | `parametric-oracle` | Verifiable environmental data ingestion |
| Claim Engine | `insurance-claim` + `insurance-payment` | Claim validation and automated SAC disbursement |

The Claim Engine presents as a single settlement primitive to end users. `insurance-claim` handles validation; `insurance-payment` handles the USDC pool and SAC transfer. Keeping them separate means an insurer can audit settlement logic and fund custody independently.

### On-Chain Security

```rust
// All state-changing calls require caller authorisation
require_auth!(&env, &caller);

// Oracle submissions verified against a registered allow-list
let allowed = env.storage().get(&DataKey::AllowedProcessors).unwrap();
assert!(allowed.contains(&oracle_id), Error::UnauthorizedOracle);

// ed25519 signature check on every oracle payload
env.crypto().ed25519_verify(&oracle_key, &payload_bytes, &signature);

// Payout flow: CHECK policy state → EFFECT claim update → INTERACT with payment contract
```

---

## Current Implementation Maturity

| Surface | Status |
|---|---|
| Contract ID configuration | Live — all four IDs in `config/stellar.php` |
| `invokeContract()` / `queryContract()` | Live |
| Policy creation | Live — `createPolicy()` calls the `insurance-policy` contract |
| Premium processing | Placeholder — `processPremiumPayment()` returns `PLACEHOLDER_HASH` (T1) |
| Claim submission | Placeholder — `submitClaim()` returns `PLACEHOLDER_HASH` (T1) |
| Parametric payout | Placeholder — `processParametricPayout()` returns `PLACEHOLDER_HASH` (T1) |
| Oracle data submission | Backend-mediated operator path today — Acurast TEE in T2 |
| MoneyGram integration | Sandbox-first — routes, service class, and webhook handler exist; live anchor in T2 |
| Mainnet deployment | Testnet only — mainnet is a T3 deliverable |

---

## Wallet and Settlement Model

| Layer | Role |
|---|---|
| Riwe-managed custodial Stellar wallet | Primary settlement destination — Riwe holds keys on farmer's behalf |
| Custodial wallet (`DefiWalletService`) | Broader asset-access layer |
| WalletPlus (`WalletPlusService`) | Self-custodial operations for institutional partners and higher-assurance accounts |

**Settlement pattern:** policy and claim state are coordinated by the backend → claim outcomes flow into wallet settlement via `StellarClaimService` → USDC settles into a Riwe-managed custodial Stellar wallet → MoneyGram converts and disburses as NGN cash at a local agent. The farmer's experience is entirely fiat.

| Wallet mode | Key management |
|---|---|
| Custodial (farmer wallets) | AES-256 encrypted private keys in PostgreSQL via Laravel `Crypt` |
| Self-custodial (WalletPlus) | Device-bound storage, biometric authentication |
| Critical pool operations | HSM integration (T3) |
| Account recovery | SEP-30 multi-factor recovery (T3) |

---

## MoneyGram Integration

MoneyGram is a backend service integration — not an on-chain component. It sits after wallet settlement.

### Configuration

| Setting | Value |
|---|---|
| Default environment | Sandbox |
| USDC issuer (testnet) | `GBBD47IF6LWK7P7MDEVSCWR7DPUWV3NY3DTQEVFL4NAT4AQH3ZLLFLA5` |
| USDC issuer (mainnet) | `GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN` |
| Deposit limits | 5 USDC min / 950 USDC max |
| Withdrawal limits | 5 USDC min / 2,500 USDC max |

### Payout Sequence

```
1. USDC settles to Riwe-managed custodial wallet on Stellar (on-chain)
2. Farmer taps "Collect Payout" in Riwe app — sees NGN amount only
3. Backend calls MoneyGramRampsService::initiateWithdrawal()
   using custodially-managed Stellar keypair for SEP-10 auth
4. MoneyGram converts USDC to NGN and disburses to nearest agent
5. Webhook fires with `completed` status
6. Backend marks claim record as `disbursed`
```

### Webhook Status Mapping

| MoneyGram status | Local status |
|---|---|
| `pending_user_transfer_start` | `pending` |
| `pending_anchor` / `pending_stellar` / `pending_external` | `processing` |
| `pending_trust` / `pending_user` | `pending` |
| `completed` | `completed` |
| `error` / `incomplete` | `failed` |

---

## Operational Data Model

| Table | Purpose |
|---|---|
| `stellar_wallets` | User settlement-wallet records and encrypted key metadata |
| `defi_wallets` | Broader custodial wallet records |
| `defi_transactions` | Wallet-related transaction history and state |
| `fiat_onramps` | Provider-side fiat transactions — Paystack and MoneyGram |
| `insurance_policies` | Off-chain policy state mirrored against on-chain identifiers |
| `claims` | Claim records, trigger data, oracle context, processing metadata |
| `stellar_smart_contracts` | Contract references and deployment/linking metadata |

---

## Security and Observability

**Application-layer controls:**
- Custodial private keys encrypted with AES-256 via Laravel `Crypt`
- Per-environment Stellar network selection
- Webhook secret validation on all MoneyGram callbacks
- Sanctum token authentication on all insurance API routes
- SEP-10 JWT verification on all Stellar-touching operations (T2)

**Observability:**
- Laravel logging channels for Stellar operations and contract invocations
- Provider-context logging for MoneyGram initiation and webhook events
- `insurance:process-parametric-claims` as the scheduled processing surface
- Queue/job patterns for settlement background work

---

## Planned Roadmap

### T1 — Contract Logic Completion
- Replace `PLACEHOLDER_HASH` returns in `processPremiumPayment()`, `submitClaim()`, and `processParametricPayout()` with real on-chain invocations
- Complete and test `insurance-payment` and `parametric-oracle` contract logic
- Achieve ≥90% `cargo test` coverage across all four contracts
- Protocol CLI — full lifecycle simulation in local Soroban sandbox
- MoneyGram SEP-24 UX/UI Figma designs (8+ screens)

### T2 — Testnet Deployment and Oracle Activation
- Deploy all four contracts to Stellar Testnet
- Activate Acurast TEE oracle pipeline — replaces backend-mediated oracle submission
- Integrate SEP-10, SEP-24, and SEP-6 on the backend
- Deploy partner underwriting console with SEP-7 support
- E2E lifecycle test and MoneyGram SEP-24 anchor simulation
- Vault Factory contract — automated Regional Risk Pool Deployment

### T3 — Mainnet
- SDF audit and Mainnet deployment of all four contracts
- SEP-1 `stellar.toml` configuration
- Public TypeScript SDK and NPM package at `riwe.io/developers`
- SEP-30 account recovery for partner-managed wallets
- Institutional onboarding: Leadway Assurance and NIA-member banks
- Datadog monitoring at `riwe.io/status`

### Acurast Oracle — Why TEE, Not Centralised Submission

The current oracle model relies on our Laravel application retrieving NDVI data from Sentinel Hub and submitting it to `parametric-oracle` as a single authorised operator. It works, but any party verifying a claim trigger has to trust Riwe as the sole data source.

Acurast runs the same Sentinel Hub retrieval inside a hardware Trusted Execution Environment. The TEE attests that the data was fetched and signed in a tamper-resistant environment — the attestation is verifiable on-chain by any counterparty without trusting us. The data source doesn't change. The trust model does.

---

*Riwe Technologies Limited · riwe.io · partnerships@riwe.io*
