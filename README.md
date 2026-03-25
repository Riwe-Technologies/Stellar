# Riwe — Stellar Integration
## Parametric Climate Insurance on Stellar

This repository contains the on-chain implementation of Riwe's parametric climate insurance protocol. It covers the Soroban smart contract suite, the Laravel backend integration layer, and the oracle pipeline that connects Sentinel Hub satellite data to automated farmer payouts.

---

## What This Repository Contains

Riwe's parametric insurance works like this: a smallholder farmer in Benue State pays a $10 seasonal premium. Sentinel Hub satellites monitor their GPS-tagged farm polygon throughout the growing season. If NDVI (crop health index) drops below a defined threshold for a sustained period, a claim fires automatically — no inspector visit, no paperwork, no bank account required. The farmer collects NGN cash at a MoneyGram agent point within 48 hours.

The Stellar layer is what makes the payout step trustworthy and auditable. Every policy is registered on-chain, every oracle submission is cryptographically signed, every payout is a traceable USDC transaction on Stellar. The on-chain record is verifiable by Leadway Assurance, reinsurers, and regulators without depending on Riwe as the sole data source.

---

## Codebase Repository Structure

- **[View App Services](./app/Services/)** — Core business logic and service integrations. This is where the Laravel backend coordinates policy state, Stellar wallet operations, contract invocations, and MoneyGram fiat flows.
- **[View Rust Contracts](./Contracts/)** — The Soroban smart contracts written in Rust. Four contracts: `insurance-policy`, `insurance-claim`, `insurance-payment`, and `parametric-oracle`.
- **[Stellar Integration Tests](./tests/)** — Automated test suite covering the end-to-end parametric insurance flow from policy creation through oracle submission to USDC payout.

---

## Smart Contract Suite

Four contracts, each with a single responsibility:

| Contract | What it does | Testnet Status |
|---|---|---|
| `insurance-policy` | Registers policies on-chain with farm polygon, crop type, season dates, and trigger parameters | Live |
| `insurance-claim` | Evaluates parametric trigger conditions and authorises payouts | Live |
| `insurance-payment` | Holds the insurance pool and executes USDC transfers to farmer wallets | T2 deployment |
| `parametric-oracle` | Accepts Acurast TEE-signed satellite data and stores it for claim evaluation | T2 deployment |

Live Testnet contract IDs:
- Policy: `CCRXGROY4THHIB7QRGMJHBXXN7TPMVEYGBBEFVKGWQXOYH4RHJDB3SHR`
- Claims: `CCFYJDOFQAQT5DVB2UNU4SWOXMVFLLVWNG47J6G5ZPQGPDMRWSXO75WQ`

Both verifiable at [stellar.expert/explorer/testnet](https://stellar.expert/explorer/testnet).

---

## Tech Stack

| Layer | Technology |
|---|---|
| Application framework | Laravel 10, PHP 8.2 |
| Primary data store | PostgreSQL |
| Blockchain network | Stellar |
| Smart contracts | Soroban (Rust) |
| Climate data | Sentinel Hub via Copernicus Data Space Ecosystem |
| Decentralised oracle | Acurast TEE network (T2) |
| Premium collection | Paystack (NGN and MTN Mobile Money) |
| Claim disbursement | MoneyGram SEP-24 (USDC to NGN at agent point) |

---

## How the End-to-End Flow Works

```
Farmer enrolls via USSD (*384#) or mobile app
        │
        ▼
Laravel backend validates KYC and calls insurance-policy contract
Policy registered on-chain with farm GPS polygon and trigger config
        │
        ▼
Sentinel Hub satellite data retrieved by Acurast TEE processor
ed25519-signed payload submitted to parametric-oracle contract
        │
        ▼
Scheduled artisan command reads oracle data
Trigger condition evaluated against active policies
        │
        ▼
insurance-claim contract validates oracle data and policy status
insurance-payment contract releases USDC to farmer Stellar wallet
        │
        ▼
Farmer notified via SMS
MoneyGram SEP-24 withdrawal converts USDC to NGN
Farmer collects cash at nearest agent point within 48 hours
```

---

## Why Stellar

Three specific reasons, not generic ones.

First, MoneyGram already operates as a SEP-24 anchor on Stellar. That means USDC settled on Stellar can be converted to NGN and disbursed as cash through MoneyGram's Nigerian agent network without building a custom fiat bridge. We did not choose Stellar and then figure out the last-mile problem — the last-mile solution already existed on Stellar when we chose it.

Second, Soroban's deterministic execution model is the right fit for parametric insurance. The same oracle data and trigger conditions must always produce the same claim outcome. No discretion, no dispute, no adjuster. Soroban guarantees that.

Third, USDC as a native Stellar asset means the insurance pool, premium flows, and payouts are all denominated in a stablecoin that is auditable by our insurers and any reinsurer without exposure to crypto volatility. Farmers never touch USDC — they pay in NGN and collect in NGN. The USDC layer is internal settlement infrastructure.

---

## Why Acurast and Not Chainlink

We get this question a lot, especially since Stellar and Chainlink announced their integration in early 2026.

The Stellar-Chainlink integration is about cross-chain interoperability and price data feeds — moving assets between blockchains and getting reliable market prices on-chain. That is not what we need. We need verified NDVI readings for a specific GPS farm polygon on a specific date submitted to a Soroban contract. Chainlink Data Feeds do not cover geospatial agricultural indices.

Acurast runs our Sentinel Hub data retrieval inside a hardware Trusted Execution Environment. The TEE attests that the data was fetched correctly and has not been tampered with between retrieval and on-chain submission. The attestation is verifiable on-chain by any counterparty without trusting Riwe. The data source does not change — we still use Sentinel Hub. The trust model does.

---

## Build Status

| Component | Status |
|---|---|
| `insurance-policy` contract | Live on Testnet |
| `insurance-claim` contract | Live on Testnet |
| `insurance-payment` contract | Source complete, T2 deployment |
| `parametric-oracle` contract | Source complete, T2 deployment |
| Laravel Stellar service layer | Integrated and operational |
| Paystack NGN premium collection | Live |
| Sentinel Hub satellite data pipeline | Operational off-chain |
| MoneyGram SEP-24 service layer | T2 deliverable |
| Mainnet deployment | T3 deliverable |

---

## Prerequisites

```bash
PHP 8.1+ with Laravel 10+
Stellar PHP SDK v1.8.0+
Soroban CLI v21.0.0+
Rust toolchain with WASM target
PostgreSQL
Redis
```

## Installation

```bash
composer require soneso/stellar-php-sdk:1.8.0
cargo install --locked soroban-cli
rustup target add wasm32-unknown-unknown

cp .env.stellar.example .env
php artisan migrate
```

## Deploy Contracts

```bash
cd contracts
./scripts/deploy.sh testnet
```

## Run Parametric Claim Processing

```bash
php artisan insurance:process-parametric-claims
```

---

## Demo Access

Live application demo — off-chain dashboard and insurance flows:

- URL: https://riwe.io/login
- Email: demouser@riwe.io
- OTP: 612738 (also displayed on the login page)

---

## Documentation

- [Technical Architecture](./Technical-Architecture.md)
- [System Architecture](./System-Architecture.md)
- [Soroban Smart Contracts Overview](./Soroban-Smart-Contracts-Overview.md)
- [Contract Specifications](./Contract-Specifications.md)
- [[DeFi-Wallet-System.md](./DeFi-Wallet-System.md)

---

## SCF Project Scope

This repository is the technical deliverable for Riwe's Stellar Community Fund submission. The project handles three tranches of work:

- **T1** — Complete the four-contract Soroban suite with full unit test coverage and a protocol CLI for end-to-end sandbox simulation
- **T2** — Deploy all four contracts to Stellar Testnet, activate the Acurast oracle pipeline, and validate the MoneyGram SEP-24 withdrawal flow end to end
- **T3** — Mainnet deployment following SDF audit, public TypeScript SDK, and institutional onboarding with Leadway Assurance and NIA-member banks

---

*Riwe Technologies Limited · riwe.io · partnerships@riwe.io*
