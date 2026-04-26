#!/bin/bash

# Soroban Smart Contract Deployment Script
# This script deploys all insurance smart contracts to Stellar

set -e

# Configuration
NETWORK=${SOROBAN_NETWORK:-testnet}
ACCOUNT=${SOROBAN_ACCOUNT:-alice}
BUILD_DIR="target/wasm32-unknown-unknown/release"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check prerequisites
check_prerequisites() {
    log_info "Checking prerequisites..."
    
    if ! command -v soroban &> /dev/null; then
        log_error "Soroban CLI not found. Please install it first."
        exit 1
    fi
    
    if ! command -v cargo &> /dev/null; then
        log_error "Cargo not found. Please install Rust first."
        exit 1
    fi
    
    log_success "Prerequisites check passed"
}

# Build all contracts
build_contracts() {
    log_info "Building smart contracts..."
    
    cargo build --target wasm32-unknown-unknown --release
    
    if [ $? -eq 0 ]; then
        log_success "All contracts built successfully"
    else
        log_error "Contract build failed"
        exit 1
    fi
}

# Deploy a single contract
deploy_contract() {
    local contract_name=$1
    local wasm_file="${BUILD_DIR}/${contract_name}.wasm"
    
    log_info "Deploying ${contract_name}..."
    
    if [ ! -f "$wasm_file" ]; then
        log_error "WASM file not found: $wasm_file"
        return 1
    fi
    
    local contract_id=$(soroban contract deploy \
        --wasm "$wasm_file" \
        --source "$ACCOUNT" \
        --network "$NETWORK" 2>/dev/null)
    
    if [ $? -eq 0 ]; then
        log_success "${contract_name} deployed with ID: $contract_id"
        echo "$contract_id"
    else
        log_error "Failed to deploy ${contract_name}"
        return 1
    fi
}

# Initialize a contract
initialize_contract() {
    local contract_id=$1
    local contract_type=$2
    
    log_info "Initializing ${contract_type} contract..."
    
    case $contract_type in
        "policy")
            soroban contract invoke \
                --id "$contract_id" \
                --source "$ACCOUNT" \
                --network "$NETWORK" \
                -- initialize \
                --admin "$ACCOUNT" \
                --oracle_addresses "[]" \
                --minimum_confidence_score 70 \
                --auto_payout_threshold 80 \
                --fee_percentage 100 \
                --fee_recipient "$ACCOUNT"
            ;;
        "claim")
            soroban contract invoke \
                --id "$contract_id" \
                --source "$ACCOUNT" \
                --network "$NETWORK" \
                -- initialize \
                --admin "$ACCOUNT" \
                --oracle_addresses "[]" \
                --minimum_confidence_score 70 \
                --auto_payout_threshold 80 \
                --fee_percentage 100 \
                --fee_recipient "$ACCOUNT" \
                --policy_contract "$POLICY_CONTRACT_ID" \
                --payment_contract "$PAYMENT_CONTRACT_ID"
            ;;
        "payment")
            soroban contract invoke \
                --id "$contract_id" \
                --source "$ACCOUNT" \
                --network "$NETWORK" \
                -- initialize \
                --admin "$ACCOUNT" \
                --oracle_addresses "[]" \
                --minimum_confidence_score 70 \
                --auto_payout_threshold 80 \
                --fee_percentage 100 \
                --fee_recipient "$ACCOUNT" \
                --policy_contract "$POLICY_CONTRACT_ID" \
                --claim_contract "$CLAIM_CONTRACT_ID" \
                --supported_tokens "[]"
            ;;
        "oracle")
            soroban contract invoke \
                --id "$contract_id" \
                --source "$ACCOUNT" \
                --network "$NETWORK" \
                -- initialize \
                --admin "$ACCOUNT" \
                --authorized_oracles "[$ACCOUNT]" \
                --data_retention_period 86400 \
                --minimum_confidence_score 70
            ;;
    esac
    
    if [ $? -eq 0 ]; then
        log_success "${contract_type} contract initialized"
    else
        log_error "Failed to initialize ${contract_type} contract"
        return 1
    fi
}

# Save contract addresses to file
save_contract_addresses() {
    local env_file="contracts/.env.deployed"
    
    log_info "Saving contract addresses to $env_file"
    
    cat > "$env_file" << EOF
# Deployed Smart Contract Addresses
# Network: $NETWORK
# Deployed at: $(date)

INSURANCE_POLICY_CONTRACT=$POLICY_CONTRACT_ID
INSURANCE_CLAIM_CONTRACT=$CLAIM_CONTRACT_ID
INSURANCE_PAYMENT_CONTRACT=$PAYMENT_CONTRACT_ID
PARAMETRIC_ORACLE_CONTRACT=$ORACLE_CONTRACT_ID

# Copy these to your main .env file
EOF
    
    log_success "Contract addresses saved to $env_file"
}

# Main deployment function
main() {
    log_info "Starting smart contract deployment to $NETWORK network"
    
    check_prerequisites
    build_contracts
    
    # Deploy contracts in order
    log_info "Deploying contracts..."
    
    # Deploy policy contract first
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
    
    log_success "All contracts deployed successfully!"
    
    # Initialize contracts
    log_info "Initializing contracts..."
    
    initialize_contract "$POLICY_CONTRACT_ID" "policy"
    initialize_contract "$PAYMENT_CONTRACT_ID" "payment"
    initialize_contract "$CLAIM_CONTRACT_ID" "claim"
    initialize_contract "$ORACLE_CONTRACT_ID" "oracle"
    
    log_success "All contracts initialized successfully!"
    
    # Save addresses
    save_contract_addresses
    
    log_success "Deployment completed successfully!"
    log_info "Contract addresses:"
    log_info "  Policy Contract: $POLICY_CONTRACT_ID"
    log_info "  Claim Contract: $CLAIM_CONTRACT_ID"
    log_info "  Payment Contract: $PAYMENT_CONTRACT_ID"
    log_info "  Oracle Contract: $ORACLE_CONTRACT_ID"
    
    log_warning "Don't forget to update your .env file with these contract addresses!"
}

# Handle script arguments
case "${1:-deploy}" in
    "deploy")
        main
        ;;
    "build")
        check_prerequisites
        build_contracts
        ;;
    "clean")
        log_info "Cleaning build artifacts..."
        cargo clean
        log_success "Build artifacts cleaned"
        ;;
    "help")
        echo "Usage: $0 [deploy|build|clean|help]"
        echo "  deploy: Build and deploy all contracts (default)"
        echo "  build:  Build contracts only"
        echo "  clean:  Clean build artifacts"
        echo "  help:   Show this help message"
        ;;
    *)
        log_error "Unknown command: $1"
        echo "Use '$0 help' for usage information"
        exit 1
        ;;
esac
