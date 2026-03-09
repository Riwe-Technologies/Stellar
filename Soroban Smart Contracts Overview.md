# Soroban Smart Contracts Overview

## Table of Contents

1. [Soroban Introduction](#soroban-introduction)
2. [Contract Architecture](#contract-architecture)
3. [Contract Inventory](#contract-inventory)
4. [Contract Deployment](#contract-deployment)
5. [Application Integration](#application-integration)
6. [Security Features](#security-features)
7. [Performance & Optimization](#performance--optimization)
8. [Testing & Validation](#testing--validation)
9. [Known Inconsistencies](#known-inconsistencies)
10. [Relevant Files](#relevant-files)
11. [Conclusion](#conclusion)

## Soroban Introduction

### What Soroban is

Soroban is Stellar's smart contract platform. In this repository, Soroban is used to implement insurance-related business logic as Rust contracts that are compiled to WebAssembly (WASM) and deployed to the Stellar network.

### Why it fits this project

Soroban is a good fit for this codebase because the application needs:

- deterministic on-chain execution
- low-cost and fast settlement on Stellar
- composable cross-contract interactions
- token-aware payment flows
- support for oracle-driven parametric insurance logic

### Core development model used in this repository

The repository follows the typical Soroban development model:

1. write contracts in Rust
2. annotate contract types with `#[contract]` and `#[contractimpl]`
3. compile to WASM with Cargo
4. deploy the WASM artifact to Soroban
5. initialize deployed contracts with network-specific configuration

This is the standard contract creation pattern for Soroban-based applications.

## Contract Architecture

### Workspace structure

The smart contracts live in the Rust workspace defined in `contracts/Cargo.toml`.

Workspace members:

- `insurance-policy`
- `insurance-claim`
- `insurance-payment`
- `parametric-oracle`
- `shared`

Only the first four are deployable smart contracts. The `shared` crate contains reusable types, helpers, validation logic, and storage-related structures used across the suite.

### High-level architecture

The contract suite is modular rather than monolithic:

- **Policy contract** manages policy lifecycle and configuration
- **Claim contract** manages claims and claim decisions
- **Payment contract** handles premium collection and payout execution
- **Oracle contract** manages environmental/parametric data submission and validation

### Dependency model

The contracts are intentionally separated by domain responsibility:

- the **policy contract** is the core registry for policy state
- the **claim contract** depends on the policy contract for policy lookup
- the **claim contract** also calls the payment contract for payout execution
- the **payment contract** tracks supported assets and insurance pool balance
- the **oracle contract** provides trusted input data for parametric decisions

### Cross-contract interaction flow

The codebase uses Soroban cross-contract calls for coordination:

1. a policy is created and stored in the policy contract
2. a premium is processed through the payment contract
3. a claim is submitted to the claim contract
4. oracle data is submitted and validated
5. the claim contract evaluates payout conditions
6. the payment contract executes payout and marks the claim as paid

This separation is standard and improves maintainability, auditability, and reuse.

## Contract Inventory

### Current source-defined smart contracts: 4

The repository currently contains **4 real smart contracts defined in source**.

#### 1. InsurancePolicyContract

- package: `contracts/insurance-policy`
- main file: `contracts/insurance-policy/src/contract.rs`
- responsibilities:
  - initialize policy system configuration
  - create policies
  - activate or manage policy lifecycle
  - store and query policy data
  - expose contract configuration

#### 2. InsuranceClaimContract

- package: `contracts/insurance-claim`
- main file: `contracts/insurance-claim/src/contract.rs`
- responsibilities:
  - submit claims
  - validate claimant and policy status
  - evaluate parametric claim conditions
  - approve or reject claims
  - trigger payouts through the payment contract

#### 3. InsurancePaymentContract

- package: `contracts/insurance-payment`
- main file: `contracts/insurance-payment/src/contract.rs`
- responsibilities:
  - process premium payments
  - track supported tokens/assets
  - maintain the insurance pool balance
  - process claim payouts
  - notify the claim contract when payouts complete

#### 4. ParametricOracleContract

- package: `contracts/parametric-oracle`
- main file: `contracts/parametric-oracle/src/contract.rs`
- responsibilities:
  - register and authorize oracle sources
  - accept environmental data submissions
  - verify oracle signatures
  - enforce freshness and confidence thresholds
  - expose retained data for downstream evaluation

### Shared crate

`contracts/shared` is **not a deployable smart contract**. It is a support crate used by the deployable contracts for:

- shared domain models
- validation helpers
- error types
- event types
- common business rules

## Contract Deployment

### Standard deployment model

The main deployment workflow is standard for Soroban:

1. compile contract packages into WASM
2. deploy each WASM artifact to the target network
3. initialize each contract with its runtime configuration
4. store or export deployed contract IDs for application use

### Build configuration

The workspace uses an optimized release profile in `contracts/Cargo.toml`:

- `opt-level = "z"`
- `overflow-checks = true`
- `strip = "symbols"`
- `panic = "abort"`
- `codegen-units = 1`
- `lto = true`

These settings indicate explicit attention to WASM size, determinism, and runtime efficiency.

### Deployment script

The primary deployment automation is in:

- `contracts/scripts/deploy.sh`

That script performs the following major steps:

#### 1. Prerequisite checks

It verifies that the required tools are installed, including:

- `soroban`
- `cargo`

#### 2. Build step

It compiles the contracts with:

- `cargo build --target wasm32-unknown-unknown --release`

#### 3. Deployment step

It deploys contracts using:

- `soroban contract deploy --wasm ... --source ... --network ...`

#### 4. Initialization step

It initializes each deployed contract using:

- `soroban contract invoke --id ... -- initialize ...`

#### 5. Address export

It writes deployed contract IDs into:

- `contracts/.env.deployed`

### Deployment order

The deployment script deploys contracts in this order:

1. policy
2. payment
3. claim
4. oracle

It then initializes the deployed contracts with references required for cross-contract interactions.

### Is the deployment approach standard?

Yes. The compile → deploy → initialize flow is a standard Soroban deployment pattern.

## Application Integration

### Laravel/PHP integration points

The smart contracts are connected to the application through PHP services, mainly:

- `app/Services/StellarSmartContractService.php`
- `app/Services/StellarSdkContractService.php`
- `config/stellar.php`

### How the application uses contracts

The app-side services are designed to:

- trigger deployment of contract WASM artifacts
- invoke contract methods
- store resulting contract identifiers and transaction metadata
- coordinate policy creation and payment-related operations with the backend

### Runtime orchestration

The Laravel layer appears to be responsible for:

- preparing off-chain business data
- formatting arguments for contract invocation
- mapping deployed contract IDs from configuration
- updating database records after deployment or invocation succeeds

This is a common pattern for web applications that use smart contracts as part of a larger business workflow.

## Security Features

The contract suite contains multiple security-oriented patterns visible in the source.

### 1. Authorization checks

The contracts use `require_auth()` to enforce caller authorization. Examples include:

- claim submission requiring claimant authorization
- payment operations requiring payer or contract authorization
- admin-only configuration updates
- oracle management requiring authorized admin callers

### 2. Input validation

The contracts validate critical inputs before performing state transitions. Validation patterns include:

- positive amount checks
- policy status checks
- date freshness checks
- confidence score thresholds
- location validation
- supported token checks

### 3. Cross-contract state controls

The claim and payment contracts coordinate state carefully during payout flows. The claim contract updates claim state before certain external-style interactions, and the code explicitly references a **CHECKS-EFFECTS-INTERACTIONS** pattern in payout processing.

### 4. Oracle controls

The oracle contract includes important data integrity protections:

- authorized oracle allow-list checks
- signature verification using `ed25519_verify`
- confidence score enforcement
- data freshness enforcement
- retention period logic for submitted data

### 5. Contract-level configuration separation

Each contract keeps and exposes its own configuration, which improves separation of concerns and reduces the chance of unrelated state corruption across modules.

### 6. Error-based rejection paths

The contracts reject unauthorized or invalid operations using explicit error returns such as:

- `Unauthorized`
- `InvalidStatus`
- `InvalidAmount`
- `ClaimNotFound`
- `OracleNotAuthorized`

### Security assessment

Overall, the source-defined contracts show a reasonable baseline of on-chain security practices for Soroban. The strongest visible protections are authorization, validation, signature verification, and explicit state transition checks.

## Performance & Optimization

### Build-time optimizations

The release profile in `contracts/Cargo.toml` indicates several optimization choices:

- size optimization via `opt-level = "z"`
- link-time optimization via `lto = true`
- reduced code size via symbol stripping
- fewer codegen units for tighter optimization
- `panic = "abort"` for leaner WASM output

### Contract modularity

The system splits policy, claim, payment, and oracle logic into separate contracts. This helps by:

- reducing per-contract complexity
- improving auditability
- localizing storage and business rules
- allowing focused upgrades or replacements per domain

### Storage and execution considerations

The contracts appear to optimize around focused storage responsibilities:

- policy data is stored in the policy contract
- claims are stored in the claim contract
- payment ledger and pool balance are stored in the payment contract
- retained oracle submissions are stored in the oracle contract

This can improve clarity and reduce unnecessary state coupling.

### Operational performance considerations

The main trade-off of the modular design is that cross-contract calls add orchestration complexity. In practice, the design favors correctness and separation over a single giant contract, which is usually the better long-term approach.

## Testing & Validation

### Available validation sources in the repository

The repository includes contract-focused documentation and some contract tests, including references to:

- contract unit tests in the Rust packages
- deployment scripts
- broader technical docs covering testing and validation

### What should be validated routinely

For a healthy Soroban workflow, the following should be verified regularly:

- contract compilation to WASM
- successful deployment to the target network
- initialization with valid addresses and config
- cross-contract payout flow
- oracle submission and signature verification flow
- premium collection and insurance pool accounting

## Known Inconsistencies

### Legacy `simple_insurance` path

Although the current workspace clearly defines a **4-contract modular architecture**, the application code also references a separate artifact:

- `contracts/target/wasm32v1-none/release/simple_insurance.wasm`

This legacy path appears in places such as:

- `app/Services/StellarSmartContractService.php`
- `config/stellar.php`

### What this means

The repository currently appears to mix:

- a newer **modular Soroban contract suite**
- an older **single-contract deployment artifact**

### Practical interpretation

If counting only current source-defined contracts, the total is:

- **4 smart contracts**

If counting the legacy artifact still referenced by the application, the repository references:

- **4 current contracts + 1 legacy contract identity**

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
- `docs/04-Soroban-Overview.md`
- `docs/05-Contract-Specifications.md`
- `docs/stellar-smart-contracts-technical-guide.md`

### Application integration

- `app/Services/StellarSmartContractService.php`
- `app/Services/StellarSdkContractService.php`
- `config/stellar.php`

## Conclusion

This repository uses a largely standard Soroban smart contract architecture built around **four source-defined contracts**:

- policy
- claim
- payment
- oracle
