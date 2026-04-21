# Soroban Smart Contract Development Setup

Please Read! This guide is deeloped to help all developers on the team, with or without prior ecosystem background onn stellar, setup soroban on any machine wiwth ease.
## Prerequisites

- macOS, Linux, or Windows with WSL2
- Git
- A code editor (VS Code recommended)

## 1. Install Rust

```bash
# Install Rust using rustup
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh

# Restart your terminal or run:
source ~/.cargo/env

# Verify installation
rustc --version
cargo --version
```

## 2. Install Soroban CLI

```bash
# Install Soroban CLI
cargo install --locked soroban-cli

# Verify installation
soroban --version
```

## 3. Configure Soroban for Development

```bash
# Add the WebAssembly target (modern target for Rust 1.85.0+)
rustup target add wasm32v1-none

# Also add legacy target for compatibility
rustup target add wasm32-unknown-unknown

# Configure Soroban network
soroban network add \
  --global testnet \
  --rpc-url https://soroban-testnet.stellar.org:443 \
  --network-passphrase "Test SDF Network ; September 2015"

# Create a development identity
soroban keys generate --global alice --network testnet

# Fund the account (testnet only)
soroban keys fund alice --network testnet
```

## 4. Project Structure

Create the smart contracts directory structure:

```
contracts/
├── Cargo.toml                 # Workspace configuration
├── insurance-policy/          # Policy management contract
│   ├── Cargo.toml
│   └── src/
│       ├── lib.rs
│       ├── contract.rs
│       ├── storage.rs
│       └── types.rs
├── insurance-claim/           # Claim processing contract
│   ├── Cargo.toml
│   └── src/
│       ├── lib.rs
│       ├── contract.rs
│       ├── storage.rs
│       └── types.rs
├── insurance-payment/         # Payment processing contract
│   ├── Cargo.toml
│   └── src/
│       ├── lib.rs
│       ├── contract.rs
│       ├── storage.rs
│       └── types.rs
├── parametric-oracle/         # Oracle for environmental data
│   ├── Cargo.toml
│   └── src/
│       ├── lib.rs
│       ├── contract.rs
│       ├── storage.rs
│       └── types.rs
└── shared/                    # Shared types and utilities
    ├── Cargo.toml
    └── src/
        ├── lib.rs
        ├── types.rs
        └── utils.rs
```

## 5. Development Tools

### VS Code Extensions
- Rust Analyzer
- Soroban Extension (if available)
- TOML Language Support

### Useful Commands

```bash
# Build all contracts
cargo build --target wasm32-unknown-unknown --release

# Run tests
cargo test

# Deploy to testnet
soroban contract deploy \
  --wasm target/wasm32-unknown-unknown/release/insurance_policy.wasm \
  --source alice \
  --network testnet

# Invoke contract function
soroban contract invoke \
  --id CONTRACT_ID \
  --source alice \
  --network testnet \
  -- function_name --arg1 value1
```

## 6. Environment Variables

Create a `.env` file in the contracts directory:

```env
# Soroban Configuration
SOROBAN_NETWORK=testnet
SOROBAN_RPC_URL=https://soroban-testnet.stellar.org:443
SOROBAN_NETWORK_PASSPHRASE="Test SDF Network ; September 2015"

# Development Account
SOROBAN_ACCOUNT=alice

# Contract Addresses (will be populated after deployment)
INSURANCE_POLICY_CONTRACT=
INSURANCE_CLAIM_CONTRACT=
INSURANCE_PAYMENT_CONTRACT=
PARAMETRIC_ORACLE_CONTRACT=
```

## 7. Testing Setup

```bash
# Install additional testing dependencies
cargo install cargo-nextest

# Run tests with nextest (faster)
cargo nextest run

# Run tests with coverage
cargo install cargo-tarpaulin
cargo tarpaulin --out Html
```

## 8. Deployment Scripts

Create deployment scripts for easy contract management:

```bash
# Create scripts directory
mkdir scripts

# Make scripts executable
chmod +x scripts/*.sh
```

## Next Steps

1. Initialize the project structure
2. Implement the smart contracts
3. Write comprehensive tests
4. Deploy to testnet
5. Integrate with PHP backend
6. Deploy to mainnet

## Troubleshooting

### Common Issues

1. **Rust compilation errors**: Ensure you have the latest Rust version
2. **Soroban CLI not found**: Make sure `~/.cargo/bin` is in your PATH
3. **Network connection issues**: Check your internet connection and RPC URL
4. **Account funding issues**: Use the Stellar Laboratory for manual funding

### Getting Help

- [Soroban Documentation](https://soroban.stellar.org/)
- [Stellar Discord](https://discord.gg/stellardev)
- [Soroban Examples](https://github.com/stellar/soroban-examples)
- [Software Versions - Latest Protocol 25 (Mainnet, January 22, 2026](https://developers.stellar.org/docs/networks/software-versions)


## Security Considerations

- Never commit private keys to version control
- Use environment variables for sensitive data
- Test thoroughly on testnet before mainnet deployment
- Consider multi-signature setups for production
- Implement proper access controls in contracts
- Regular security audits for production contracts
