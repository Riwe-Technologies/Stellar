# Soroban Smart Contracts Overview
## Stellar Smart Contract Platform Integration

---

## 📋 Table of Contents

1. [Soroban Introduction](#soroban-introduction)
2. [Contract Architecture](#contract-architecture)
3. [Insurance Contract Suite](#insurance-contract-suite)
4. [Contract Deployment](#contract-deployment)
5. [PHP Integration](#php-integration)
6. [Security Features](#security-features)
7. [Performance & Optimization](#performance--optimization)

---

## 🌟 Soroban Introduction

### What is Soroban?
Soroban is Stellar's smart contract platform that enables developers to build and deploy smart contracts on the Stellar network. It provides:

- **WebAssembly (WASM) Runtime**: High-performance contract execution
- **Rust Programming Language**: Memory-safe, efficient contract development
- **Stellar Integration**: Native access to Stellar's payment and asset features
- **Scalability**: Optimized for high-throughput applications
- **Security**: Built-in security features and formal verification support

### Key Advantages
- **Low Cost**: Minimal transaction fees compared to other platforms
- **Fast Finality**: 3-5 second transaction confirmation
- **Built-in Assets**: Native support for tokens and payments
- **Cross-Border**: Global accessibility through Stellar network
- **Compliance**: Regulatory-friendly design

---

## 🏗️ Contract Architecture

### Contract Hierarchy
```
┌─────────────────────────────────────────────────────────────────┐
│                    Insurance Contract Suite                     │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────┐    ┌─────────────┐    ┌─────────────┐         │
│  │   Policy    │    │   Payment   │    │    Claim    │         │
│  │  Contract   │◄──►│  Contract   │◄──►│  Contract   │         │
│  │             │    │             │    │             │         │
│  │ • Create    │    │ • Premiums  │    │ • Process   │         │
│  │ • Validate  │    │ • Escrow    │    │ • Evaluate  │         │
│  │ • Lifecycle │    │ • Payouts   │    │ • Automate  │         │
│  └─────────────┘    └─────────────┘    └─────────────┘         │
│         │                   │                   │              │
│         └───────────────────┼───────────────────┘              │
│                             │                                  │
│  ┌─────────────────────────────────────────────────────────────┤
│  │                Oracle Contract                              │
│  │                                                             │
│  │ • Weather Data Sources    • Satellite Imagery              │
│  │ • Data Validation         • Confidence Scoring             │
│  │ • Multi-Source Feeds      • Anomaly Detection              │
│  │ • Timestamp Verification  • Geographic Validation          │
│  └─────────────────────────────────────────────────────────────┘
│                             │                                  │
│  ┌─────────────────────────────────────────────────────────────┤
│  │              Governance Contract                            │
│  │                                                             │
│  │ • Admin Functions         • Emergency Controls             │
│  │ • Parameter Updates       • Contract Upgrades              │
│  │ • Fee Management          • Access Control                 │
│  └─────────────────────────────────────────────────────────────┘
└─────────────────────────────────────────────────────────────────┘
```

### Data Flow Architecture
```
User Request → Policy Contract → Payment Contract → Escrow
     ↓              ↓                ↓               ↓
Oracle Data → Claim Contract → Evaluation → Payout Decision
     ↓              ↓                ↓               ↓
Validation → Automated Processing → Payment Contract → User Wallet
```

---

## 🛡️ Insurance Contract Suite

### 1. Simple Insurance Contract
**File**: `contracts/simple-insurance/src/lib.rs`

```rust
#[contract]
pub struct SimpleInsuranceContract;

#[contractimpl]
impl SimpleInsuranceContract {
    /// Initialize contract with admin
    pub fn initialize(env: Env, admin: Address) {
        admin.require_auth();
        env.storage().instance().set(&ADMIN, &admin);
    }

    /// Create new insurance policy
    pub fn create_policy(
        env: Env,
        policy_holder: Address,
        policy_id: String,
        premium: i128,
        coverage: i128,
    ) -> String {
        policy_holder.require_auth();
        
        let mut policies: Map<String, (Address, i128, i128, bool)> = env
            .storage()
            .persistent()
            .get(&POLICIES)
            .unwrap_or_else(|| Map::new(&env));
        
        policies.set(policy_id.clone(), (policy_holder, premium, coverage, true));
        env.storage().persistent().set(&POLICIES, &policies);
        
        policy_id
    }

    /// Submit insurance claim
    pub fn submit_claim(
        env: Env,
        claimant: Address,
        policy_id: String,
        claim_amount: i128,
    ) -> String {
        claimant.require_auth();
        
        // Verify policy exists and is active
        let policies: Map<String, (Address, i128, i128, bool)> = env
            .storage()
            .persistent()
            .get(&POLICIES)
            .unwrap_or_else(|| Map::new(&env));
        
        if let Some((policy_holder, _premium, coverage, active)) = policies.get(policy_id.clone()) {
            if policy_holder == claimant && active && claim_amount <= coverage {
                let claim_id = String::from_str(&env, "CLAIM_");
                // Store claim logic...
                return claim_id;
            }
        }
        
        panic!("Invalid claim");
    }
}
```

### 2. Advanced Policy Contract
**Features**:
- **Parametric Triggers**: Weather-based automatic claim processing
- **Multi-Asset Support**: XLM, USDC, and custom tokens
- **Geographic Validation**: Location-based policy verification
- **Time-Based Logic**: Seasonal and temporal policy management

```rust
pub struct ParametricPolicy {
    pub id: String,
    pub policyholder: Address,
    pub farm_location: Location,
    pub premium_amount: i128,
    pub coverage_amount: i128,
    pub start_date: u64,
    pub end_date: u64,
    pub parametric_triggers: Vec<ParametricTrigger>,
    pub status: PolicyStatus,
}

pub struct ParametricTrigger {
    pub trigger_type: TriggerType, // Rainfall, Temperature, etc.
    pub threshold_value: i64,
    pub comparison: ComparisonOperator, // LessThan, GreaterThan
    pub measurement_period: u64,
    pub payout_percentage: u32,
}
```

### 3. Payment Contract
**Responsibilities**:
- Premium collection and escrow
- Multi-asset payment processing
- Fee calculation and distribution
- Payout execution

```rust
#[contractimpl]
impl PaymentContract {
    /// Process premium payment
    pub fn process_premium(
        env: Env,
        policy_id: String,
        payer: Address,
        amount: i128,
        asset: Address,
    ) -> String {
        payer.require_auth();
        
        // Validate policy exists
        // Transfer premium to escrow
        // Update policy status
        // Return transaction hash
    }

    /// Execute claim payout
    pub fn process_payout(
        env: Env,
        policy_id: String,
        claim_id: String,
        recipient: Address,
        amount: i128,
        asset: Address,
    ) -> String {
        // Verify claim approval
        // Transfer funds from escrow
        // Update claim status
        // Return transaction hash
    }
}
```

### 4. Oracle Contract
**Data Sources**:
- Weather APIs (OpenWeatherMap, AccuWeather)
- Satellite imagery (Sentinel-2, Landsat)
- Agricultural databases (FAO, USDA)
- IoT sensor networks

```rust
#[contractimpl]
impl OracleContract {
    /// Submit environmental data
    pub fn submit_data(
        env: Env,
        oracle: Address,
        location: Location,
        data_type: DataType,
        value: i64,
        timestamp: u64,
        confidence: u32,
    ) -> Result<(), OracleError> {
        oracle.require_auth();
        
        // Validate oracle authorization
        // Verify data freshness
        // Store data with confidence score
        // Trigger claim evaluation if needed
    }

    /// Get aggregated data for location
    pub fn get_aggregated_data(
        env: Env,
        location: Location,
        data_type: DataType,
        start_time: u64,
        end_time: u64,
    ) -> Result<AggregatedData, OracleError> {
        // Aggregate data from multiple sources
        // Calculate confidence scores
        // Return weighted average
    }
}
```

---

## 🚀 Contract Deployment

### Development Environment Setup
```bash
# Install Soroban CLI
cargo install --locked soroban-cli

# Add WASM target
rustup target add wasm32-unknown-unknown

# Configure network
soroban network add testnet \
  --rpc-url https://soroban-testnet.stellar.org:443 \
  --network-passphrase "Test SDF Network ; September 2015"

# Generate deployer account
soroban keys generate deployer --network testnet
soroban keys fund deployer --network testnet
```

### Build Process
```bash
cd contracts

# Build all contracts
cargo build --target wasm32-unknown-unknown --release

# Optimize WASM files
soroban contract optimize \
  --wasm target/wasm32-unknown-unknown/release/simple_insurance.wasm

# Verify build
ls -la target/wasm32-unknown-unknown/release/*.wasm
```

### Deployment Script
```bash
#!/bin/bash
# deploy.sh

NETWORK=${1:-testnet}
DEPLOYER_KEY="deployer"

echo "Deploying contracts to $NETWORK..."

# Deploy Simple Insurance Contract
SIMPLE_CONTRACT_ID=$(soroban contract deploy \
  --wasm target/wasm32-unknown-unknown/release/simple_insurance.wasm \
  --source $DEPLOYER_KEY \
  --network $NETWORK)

echo "Simple Insurance Contract: $SIMPLE_CONTRACT_ID"

# Deploy Policy Contract
POLICY_CONTRACT_ID=$(soroban contract deploy \
  --wasm target/wasm32-unknown-unknown/release/insurance_policy.wasm \
  --source $DEPLOYER_KEY \
  --network $NETWORK)

echo "Policy Contract: $POLICY_CONTRACT_ID"

# Initialize contracts
soroban contract invoke \
  --id $SIMPLE_CONTRACT_ID \
  --source $DEPLOYER_KEY \
  --network $NETWORK \
  -- initialize \
  --admin $(soroban keys address $DEPLOYER_KEY)

echo "Deployment complete!"
```

---

## 🔗 PHP Integration

### StellarSmartContractService
```php
class StellarSmartContractService
{
    protected $sorobanRpcUrl;
    protected $networkPassphrase;

    public function __construct()
    {
        $this->sorobanRpcUrl = config('stellar.networks.testnet.soroban_rpc_url');
        $this->networkPassphrase = config('stellar.networks.testnet.network_passphrase');
    }

    /**
     * Invoke smart contract method
     */
    public function invokeContract(
        string $contractId,
        string $method,
        array $params,
        string $sourceSecret
    ): array {
        try {
            // Build contract invocation
            $contractAddress = Address::fromContractId($contractId);
            $sourceKeyPair = KeyPair::fromSeed($sourceSecret);
            
            // Create transaction
            $transaction = $this->buildContractTransaction(
                $contractAddress,
                $method,
                $params,
                $sourceKeyPair
            );
            
            // Submit to network
            $response = $this->submitTransaction($transaction);
            
            return [
                'success' => true,
                'transaction_hash' => $response->getHash(),
                'result' => $response->getResultValue(),
            ];
            
        } catch (Exception $e) {
            Log::error('Contract invocation failed', [
                'contract_id' => $contractId,
                'method' => $method,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create insurance policy on blockchain
     */
    public function createPolicy(array $policyData): array
    {
        $contractId = config('stellar.insurance.policy_contract_id');
        
        return $this->invokeContract(
            $contractId,
            'create_policy',
            [
                'policy_holder' => $policyData['policy_holder'],
                'farm_location' => $policyData['farm_location'],
                'premium_amount' => $policyData['premium_amount'],
                'coverage_amount' => $policyData['coverage_amount'],
                'start_date' => $policyData['start_date'],
                'end_date' => $policyData['end_date'],
                'parametric_triggers' => $policyData['parametric_triggers'],
            ],
            config('stellar.insurance.master_account_secret')
        );
    }

    /**
     * Submit insurance claim
     */
    public function submitClaim(array $claimData): array
    {
        $contractId = config('stellar.insurance.claim_contract_id');
        
        return $this->invokeContract(
            $contractId,
            'submit_claim',
            [
                'policy_id' => $claimData['policy_id'],
                'claimant' => $claimData['claimant'],
                'trigger_data' => $claimData['trigger_data'],
                'supporting_evidence' => $claimData['supporting_evidence'],
            ],
            $claimData['claimant_secret']
        );
    }
}
```

### Contract Integration Example
```php
// Create policy on blockchain
$contractService = app(StellarSmartContractService::class);

$policyResult = $contractService->createPolicy([
    'policy_holder' => $user->stellarWallet->public_key,
    'farm_location' => [
        'latitude' => $farm->latitude * 1000000, // Scale for precision
        'longitude' => $farm->longitude * 1000000,
        'region' => $farm->region,
        'country_code' => 'NG',
    ],
    'premium_amount' => $policy->premium_amount * 10000000, // Convert to stroops
    'coverage_amount' => $policy->coverage_amount * 10000000,
    'start_date' => $policy->start_date->timestamp,
    'end_date' => $policy->end_date->timestamp,
    'parametric_triggers' => [
        [
            'trigger_type' => 'rainfall',
            'threshold_value' => 50, // mm
            'comparison' => 'less_than',
            'measurement_period' => 30 * 24 * 3600, // 30 days
            'payout_percentage' => 80,
        ]
    ],
]);

if ($policyResult['success']) {
    $policy->update([
        'stellar_policy_id' => $policyResult['policy_id'],
        'blockchain_transaction_hash' => $policyResult['transaction_hash'],
        'status' => 'active',
    ]);
}
```

---

## 🔒 Security Features

### Access Control
- **Multi-signature**: Critical operations require multiple signatures
- **Role-based permissions**: Admin, oracle, user role separation
- **Time locks**: Mandatory delays for sensitive operations
- **Emergency pause**: Circuit breaker for anomalous situations

### Data Validation
- **Input sanitization**: All contract inputs validated
- **Range checks**: Numeric values within acceptable bounds
- **Signature verification**: All transactions cryptographically signed
- **Replay protection**: Prevent transaction replay attacks

### Oracle Security
- **Multiple data sources**: Redundant oracle feeds
- **Confidence scoring**: Quality assessment of data
- **Outlier detection**: Automatic anomaly identification
- **Timestamp validation**: Prevent stale data usage

---

## ⚡ Performance & Optimization

### Gas Optimization
- **Efficient data structures**: Optimized storage patterns
- **Batch operations**: Multiple operations in single transaction
- **Lazy evaluation**: Compute only when necessary
- **Storage optimization**: Minimize persistent storage usage

### Scalability Features
- **Horizontal scaling**: Multiple contract instances
- **Load balancing**: Distribute operations across contracts
- **Caching**: Cache frequently accessed data
- **Asynchronous processing**: Non-blocking operations

### Monitoring & Analytics
- **Contract events**: Comprehensive event logging
- **Performance metrics**: Gas usage and execution time
- **Error tracking**: Detailed error reporting
- **Usage analytics**: Contract interaction patterns

---

This Soroban integration provides a robust, secure, and scalable smart contract platform for automated insurance operations on the Stellar network.
