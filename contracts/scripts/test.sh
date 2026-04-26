#!/bin/bash

# Soroban Smart Contract Testing Script
# This script runs comprehensive tests for all insurance smart contracts

set -e

# Configuration
NETWORK=${SOROBAN_NETWORK:-testnet}
ACCOUNT=${SOROBAN_ACCOUNT:-alice}

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

# Run unit tests
run_unit_tests() {
    log_info "Running unit tests..."
    
    cargo test --lib
    
    if [ $? -eq 0 ]; then
        log_success "Unit tests passed"
    else
        log_error "Unit tests failed"
        return 1
    fi
}

# Run integration tests
run_integration_tests() {
    log_info "Running integration tests..."
    
    cargo test --test integration
    
    if [ $? -eq 0 ]; then
        log_success "Integration tests passed"
    else
        log_error "Integration tests failed"
        return 1
    fi
}

# Test contract deployment
test_deployment() {
    log_info "Testing contract deployment..."
    
    # Build contracts
    cargo build --target wasm32-unknown-unknown --release
    
    if [ $? -ne 0 ]; then
        log_error "Contract build failed"
        return 1
    fi
    
    # Test deploy policy contract
    local policy_id=$(soroban contract deploy \
        --wasm target/wasm32-unknown-unknown/release/insurance_policy.wasm \
        --source "$ACCOUNT" \
        --network "$NETWORK" 2>/dev/null)
    
    if [ $? -eq 0 ]; then
        log_success "Policy contract deployment test passed: $policy_id"
        
        # Clean up test deployment
        log_info "Cleaning up test deployment..."
        # Note: Soroban doesn't have a delete function, so we just note the ID
        echo "Test policy contract ID: $policy_id" >> test_deployments.log
    else
        log_error "Policy contract deployment test failed"
        return 1
    fi
}

# Test contract interactions
test_contract_interactions() {
    log_info "Testing contract interactions..."
    
    # Load deployed contract addresses
    if [ -f "contracts/.env.deployed" ]; then
        source contracts/.env.deployed
    else
        log_warning "No deployed contracts found. Run deployment first."
        return 1
    fi
    
    # Test policy creation
    log_info "Testing policy creation..."
    
    local policy_result=$(soroban contract invoke \
        --id "$INSURANCE_POLICY_CONTRACT" \
        --source "$ACCOUNT" \
        --network "$NETWORK" \
        -- create_policy \
        --policyholder "$ACCOUNT" \
        --farm_location '{"latitude": 40000000, "longitude": -74000000, "region": "Test Farm"}' \
        --premium_amount 1000000000 \
        --coverage_amount 10000000000 \
        --start_date $(date +%s) \
        --end_date $(($(date +%s) + 31536000)) \
        --parametric_triggers '[]' 2>/dev/null)
    
    if [ $? -eq 0 ]; then
        log_success "Policy creation test passed: $policy_result"
    else
        log_error "Policy creation test failed"
        return 1
    fi
    
    # Test policy activation
    log_info "Testing policy activation..."
    
    soroban contract invoke \
        --id "$INSURANCE_POLICY_CONTRACT" \
        --source "$ACCOUNT" \
        --network "$NETWORK" \
        -- activate_policy \
        --policy_id "$policy_result" \
        --caller "$ACCOUNT" >/dev/null 2>&1
    
    if [ $? -eq 0 ]; then
        log_success "Policy activation test passed"
    else
        log_error "Policy activation test failed"
        return 1
    fi
}

# Run performance tests
run_performance_tests() {
    log_info "Running performance tests..."
    
    # Test with criterion if available
    if cargo test --help | grep -q "criterion"; then
        cargo test --benches
        
        if [ $? -eq 0 ]; then
            log_success "Performance tests passed"
        else
            log_warning "Performance tests had issues"
        fi
    else
        log_warning "Criterion not available, skipping performance tests"
    fi
}

# Generate test report
generate_test_report() {
    local report_file="test_report_$(date +%Y%m%d_%H%M%S).md"
    
    log_info "Generating test report: $report_file"
    
    cat > "$report_file" << EOF
# Smart Contract Test Report

**Date:** $(date)
**Network:** $NETWORK
**Account:** $ACCOUNT

## Test Results

### Unit Tests
- Status: $([ $UNIT_TEST_RESULT -eq 0 ] && echo "✅ PASSED" || echo "❌ FAILED")

### Integration Tests
- Status: $([ $INTEGRATION_TEST_RESULT -eq 0 ] && echo "✅ PASSED" || echo "❌ FAILED")

### Deployment Tests
- Status: $([ $DEPLOYMENT_TEST_RESULT -eq 0 ] && echo "✅ PASSED" || echo "❌ FAILED")

### Contract Interaction Tests
- Status: $([ $INTERACTION_TEST_RESULT -eq 0 ] && echo "✅ PASSED" || echo "❌ FAILED")

### Performance Tests
- Status: $([ $PERFORMANCE_TEST_RESULT -eq 0 ] && echo "✅ PASSED" || echo "⚠️ SKIPPED/ISSUES")

## Contract Addresses

$([ -f "contracts/.env.deployed" ] && cat contracts/.env.deployed || echo "No deployed contracts found")

## Recommendations

$([ $OVERALL_RESULT -eq 0 ] && echo "All tests passed. Contracts are ready for production deployment." || echo "Some tests failed. Please review and fix issues before production deployment.")

EOF
    
    log_success "Test report generated: $report_file"
}

# Main testing function
main() {
    log_info "Starting comprehensive smart contract testing"
    
    # Initialize result variables
    UNIT_TEST_RESULT=0
    INTEGRATION_TEST_RESULT=0
    DEPLOYMENT_TEST_RESULT=0
    INTERACTION_TEST_RESULT=0
    PERFORMANCE_TEST_RESULT=0
    OVERALL_RESULT=0
    
    # Run all tests
    run_unit_tests || UNIT_TEST_RESULT=1
    run_integration_tests || INTEGRATION_TEST_RESULT=1
    test_deployment || DEPLOYMENT_TEST_RESULT=1
    test_contract_interactions || INTERACTION_TEST_RESULT=1
    run_performance_tests || PERFORMANCE_TEST_RESULT=1
    
    # Calculate overall result
    OVERALL_RESULT=$((UNIT_TEST_RESULT + INTEGRATION_TEST_RESULT + DEPLOYMENT_TEST_RESULT + INTERACTION_TEST_RESULT))
    
    # Generate report
    generate_test_report
    
    # Final status
    if [ $OVERALL_RESULT -eq 0 ]; then
        log_success "All critical tests passed! 🎉"
        log_info "Contracts are ready for production deployment."
    else
        log_error "Some tests failed. Please review and fix issues."
        log_info "Check the test report for details."
        exit 1
    fi
}

# Handle script arguments
case "${1:-all}" in
    "all")
        main
        ;;
    "unit")
        run_unit_tests
        ;;
    "integration")
        run_integration_tests
        ;;
    "deploy")
        test_deployment
        ;;
    "interact")
        test_contract_interactions
        ;;
    "performance")
        run_performance_tests
        ;;
    "help")
        echo "Usage: $0 [all|unit|integration|deploy|interact|performance|help]"
        echo "  all:         Run all tests (default)"
        echo "  unit:        Run unit tests only"
        echo "  integration: Run integration tests only"
        echo "  deploy:      Test contract deployment"
        echo "  interact:    Test contract interactions"
        echo "  performance: Run performance tests"
        echo "  help:        Show this help message"
        ;;
    *)
        log_error "Unknown command: $1"
        echo "Use '$0 help' for usage information"
        exit 1
        ;;
esac
