#!/bin/bash

# =============================================================================
# STELLAR MAINNET SMART CONTRACT DEPLOYMENT SCRIPT
# =============================================================================
# ⚠️  CRITICAL: This deploys to PRODUCTION mainnet
# ⚠️  Ensure all testing is complete before running
# ⚠️  Have sufficient XLM in deployment account

set -e

# Configuration
NETWORK="mainnet"
ACCOUNT_NAME="riwe_mainnet"
BUILD_DIR="contracts/target/wasm32-unknown-unknown/release"
DEPLOYMENT_LOG="mainnet_deployment_$(date +%Y%m%d_%H%M%S).log"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1" | tee -a "$DEPLOYMENT_LOG"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1" | tee -a "$DEPLOYMENT_LOG"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1" | tee -a "$DEPLOYMENT_LOG"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1" | tee -a "$DEPLOYMENT_LOG"
}

log_critical() {
    echo -e "${PURPLE}[CRITICAL]${NC} $1" | tee -a "$DEPLOYMENT_LOG"
}

# Banner
echo -e "${PURPLE}"
echo "============================================================================="
echo "🚀 STELLAR MAINNET SMART CONTRACT DEPLOYMENT"
echo "============================================================================="
echo -e "${NC}"
echo "⚠️  WARNING: This will deploy to PRODUCTION mainnet"
echo "⚠️  Ensure you have:"
echo "   - Tested all contracts on testnet"
echo "   - Sufficient XLM for deployment"
echo "   - Backup of all contract code"
echo "   - Rollback plan ready"
echo ""

# Confirmation prompt
read -p "Are you sure you want to deploy to MAINNET? (type 'YES' to continue): " confirm
if [ "$confirm" != "YES" ]; then
    log_error "Deployment cancelled by user"
    exit 1
fi

# Start deployment log
log_critical "Starting mainnet deployment at $(date)"
log_info "Network: $NETWORK"
log_info "Account: $ACCOUNT_NAME"
log_info "Build directory: $BUILD_DIR"

# Check prerequisites
check_prerequisites() {
    log_info "Checking prerequisites..."
    
    # Check if soroban CLI is installed
    if ! command -v soroban &> /dev/null; then
        log_error "Soroban CLI not found. Please install it first."
        exit 1
    fi
    
    # Check soroban version
    SOROBAN_VERSION=$(soroban --version | grep -o '[0-9]\+\.[0-9]\+\.[0-9]\+')
    log_info "Soroban CLI version: $SOROBAN_VERSION"
    
    # Check if account exists
    if ! soroban keys show "$ACCOUNT_NAME" &> /dev/null; then
        log_error "Account '$ACCOUNT_NAME' not found. Please create it first."
        exit 1
    fi
    
    # Check if build directory exists
    if [ ! -d "$BUILD_DIR" ]; then
        log_error "Build directory not found: $BUILD_DIR"
        log_info "Please run 'cargo build --target wasm32-unknown-unknown --release' first"
        exit 1
    fi
    
    log_success "Prerequisites check passed"
}

# Setup mainnet network
setup_network() {
    log_info "Setting up mainnet network configuration..."
    
    soroban network add mainnet \
        --rpc-url https://soroban-mainnet.stellar.org \
        --network-passphrase "Public Global Stellar Network ; September 2015" \
        2>/dev/null || true
    
    log_success "Mainnet network configured"
}

# Check account balance
check_balance() {
    log_info "Checking account balance..."
    
    ACCOUNT_ADDRESS=$(soroban keys address "$ACCOUNT_NAME")
    log_info "Account address: $ACCOUNT_ADDRESS"
    
    # Note: Balance check would require additional API call
    log_warning "Please ensure account has sufficient XLM for deployment"
    log_info "Recommended minimum: 100 XLM"
}

# Build contracts
build_contracts() {
    log_info "Building smart contracts..."
    
    cd contracts
    cargo build --target wasm32-unknown-unknown --release
    
    if [ $? -eq 0 ]; then
        log_success "Contracts built successfully"
    else
        log_error "Failed to build contracts"
        exit 1
    fi
    
    cd ..
}

# Deploy a single contract
deploy_contract() {
    local contract_name=$1
    local wasm_file="$BUILD_DIR/${contract_name}.wasm"
    
    log_info "Deploying ${contract_name}..."
    
    if [ ! -f "$wasm_file" ]; then
        log_error "WASM file not found: $wasm_file"
        return 1
    fi
    
    local contract_id=$(soroban contract deploy \
        --wasm "$wasm_file" \
        --source "$ACCOUNT_NAME" \
        --network "$NETWORK" 2>/dev/null)
    
    if [ $? -eq 0 ]; then
        log_success "${contract_name} deployed with ID: $contract_id"
        echo "$contract_id"
    else
        log_error "Failed to deploy ${contract_name}"
        return 1
    fi
}

# Save contract addresses
save_contract_addresses() {
    local env_file=".env.mainnet.contracts"
    
    log_info "Saving contract addresses to $env_file"
    
    cat > "$env_file" << EOF
# Mainnet Smart Contract Addresses
# Deployed at: $(date)
# Network: $NETWORK

# Insurance Smart Contracts
STELLAR_POLICY_CONTRACT_ID=$POLICY_CONTRACT_ID
STELLAR_CLAIM_CONTRACT_ID=$CLAIM_CONTRACT_ID
STELLAR_PAYMENT_CONTRACT_ID=$PAYMENT_CONTRACT_ID
STELLAR_ORACLE_CONTRACT_ID=$ORACLE_CONTRACT_ID

# Oracle Contracts
# Reuse STELLAR_ORACLE_CONTRACT_ID in application configuration

# Copy these to your main .env file for mainnet deployment
EOF
    
    log_success "Contract addresses saved to $env_file"
}

# Generate deployment summary
generate_summary() {
    log_info "Generating deployment summary..."
    
    cat > "mainnet_deployment_summary_$(date +%Y%m%d_%H%M%S).md" << EOF
# Mainnet Deployment Summary

**Deployment Date:** $(date)
**Network:** $NETWORK
**Account:** $ACCOUNT_NAME

## Deployed Contracts

### Insurance Contracts
- **Policy Contract:** \`$POLICY_CONTRACT_ID\`
- **Claim Contract:** \`$CLAIM_CONTRACT_ID\`
- **Payment Contract:** \`$PAYMENT_CONTRACT_ID\`

### Oracle Contracts
- **Parametric Oracle:** \`$ORACLE_CONTRACT_ID\`

## Next Steps

1. Update .env file with new contract addresses
2. Test contract functionality
3. Update frontend to use mainnet contracts
4. Monitor contract performance
5. Implement proper monitoring and alerting

## Verification

Verify contracts on Stellar Expert:
- https://stellar.expert/explorer/public/contract/$POLICY_CONTRACT_ID
- https://stellar.expert/explorer/public/contract/$CLAIM_CONTRACT_ID
- https://stellar.expert/explorer/public/contract/$PAYMENT_CONTRACT_ID
- https://stellar.expert/explorer/public/contract/$ORACLE_CONTRACT_ID

## Rollback Plan

If issues are discovered:
1. Revert .env to testnet configuration
2. Deploy hotfix contracts if needed
3. Communicate with users about any service interruption
EOF
    
    log_success "Deployment summary generated"
}

# Main deployment function
main() {
    log_critical "Starting mainnet smart contract deployment"
    
    check_prerequisites
    setup_network
    check_balance
    build_contracts
    
    # Deploy contracts in order
    log_info "Deploying contracts to mainnet..."
    
    # Deploy policy contract
    POLICY_CONTRACT_ID=$(deploy_contract "insurance_policy")
    if [ $? -ne 0 ]; then
        log_error "Failed to deploy policy contract"
        exit 1
    fi
    
    # Deploy payment contract
    PAYMENT_CONTRACT_ID=$(deploy_contract "insurance_payment")
    if [ $? -ne 0 ]; then
        log_error "Failed to deploy payment contract"
        exit 1
    fi
    
    # Deploy claim contract
    CLAIM_CONTRACT_ID=$(deploy_contract "insurance_claim")
    if [ $? -ne 0 ]; then
        log_error "Failed to deploy claim contract"
        exit 1
    fi
    
    # Deploy oracle contract
    ORACLE_CONTRACT_ID=$(deploy_contract "parametric_oracle")
    if [ $? -ne 0 ]; then
        log_error "Failed to deploy oracle contract"
        exit 1
    fi
    
    # Save results
    save_contract_addresses
    generate_summary
    
    log_critical "Mainnet deployment completed successfully!"
    log_info "Contract addresses saved to .env.mainnet.contracts"
    log_info "Deployment log saved to $DEPLOYMENT_LOG"
    
    echo ""
    echo -e "${GREEN}✅ DEPLOYMENT SUCCESSFUL!${NC}"
    echo ""
    echo "📋 Next steps:"
    echo "   1. Update your .env file with the new contract addresses"
    echo "   2. Test contract functionality"
    echo "   3. Update frontend configuration"
    echo "   4. Monitor contract performance"
    echo ""
}

# Run deployment
main "$@"
