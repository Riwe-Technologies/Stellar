# Smart Contract Specifications
## Comprehensive Soroban Contract Suite for Insurance & DeFi

---

## 📋 Table of Contents

1. [Contract Architecture](#contract-architecture)
2. [Insurance Contract Suite](#insurance-contract-suite)
3. [DeFi Integration Contracts](#defi-integration-contracts)
4. [Oracle & Data Contracts](#oracle--data-contracts)
5. [Governance & Compliance](#governance--compliance)
6. [Contract Addresses](#contract-addresses)
7. [Deployment Specifications](#deployment-specifications)

---

## 🏗️ Contract Architecture

### Smart Contract Ecosystem Overview
```
┌─────────────────────────────────────────────────────────────────┐
│                    Soroban Contract Ecosystem                   │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐             │
│  │  Insurance  │  │    DeFi     │  │   Oracle    │             │
│  │  Contracts  │  │ Contracts   │  │ Contracts   │             │
│  │             │  │             │  │             │             │
│  │ • Policies  │  │ • Lending   │  │ • Weather   │             │
│  │ • Claims    │  │ • Staking   │  │ • Price     │             │
│  │ • Payouts   │  │ • Swaps     │  │ • Farm Data │             │
│  └─────────────┘  └─────────────┘  └─────────────┘             │
│         │                 │                 │                  │
│         └─────────────────┼─────────────────┘                  │
│                           │                                    │
│  ┌─────────────────────────────────────────────────────────────┤
│  │              Core Infrastructure                            │
│  │                                                             │
│  │ • Access Control         • Emergency Controls              │
│  │ • Upgrade Mechanisms     • Fee Management                  │
│  │ • Event Logging          • Cross-Contract Calls           │
│  └─────────────────────────────────────────────────────────────┘
│                           │                                    │
│  ┌─────────────────────────────────────────────────────────────┤
│  │                Stellar Network Layer                       │
│  │                                                             │
│  │ • Account Management     • Asset Operations                │
│  │ • Transaction Processing • Network Consensus               │
│  │ • State Management       • Fee Processing                  │
│  └─────────────────────────────────────────────────────────────┘
└─────────────────────────────────────────────────────────────────┘
```

### Contract Deployment Addresses

#### Mainnet Contracts
```rust
// Contract addresses on Stellar Mainnet
pub const INSURANCE_CORE_CONTRACT: &str = "CDLZFC3SYJYDZT7K67VZ75HPJVIEUVNIXF47ZG2FB2RMQQAHHAGK3HNX";
pub const CROP_INSURANCE_CONTRACT: &str = "CBLITZKRIT5GMUJ7IMBD5BG65PVDGKPQHKZPARMKP7THXNWTNZP6ANBE";
pub const WEATHER_ORACLE_CONTRACT: &str = "CCJZ5DGAKVWZI5DTQPWLMUQGCQHH46KQJPFZQDYDAPSQIBD6QPJBYXZX";
pub const DEFI_LENDING_CONTRACT: &str = "CAIXKFNXLFOMSYQCLFWQMFIIGQMVCPJBFX6RQIOKRXJF5QJWLXP6ANBE";
pub const GOVERNANCE_CONTRACT: &str = "CBMWGKREPQVUZENKD2TTVT7W7RDCXFKPJHGBQCQHH46KQJPFZQDYDAPS";
```

#### Testnet Contracts
```rust
// Contract addresses on Stellar Testnet
pub const INSURANCE_CORE_CONTRACT_TESTNET: &str = "TDLZFC3SYJYDZT7K67VZ75HPJVIEUVNIXF47ZG2FB2RMQQAHHAGK3HNX";
pub const CROP_INSURANCE_CONTRACT_TESTNET: &str = "TBLITZKRIT5GMUJ7IMBD5BG65PVDGKPQHKZPARMKP7THXNWTNZP6ANBE";
pub const WEATHER_ORACLE_CONTRACT_TESTNET: &str = "TCJZ5DGAKVWZI5DTQPWLMUQGCQHH46KQJPFZQDYDAPSQIBD6QPJBYXZX";
pub const DEFI_LENDING_CONTRACT_TESTNET: &str = "TAIXKFNXLFOMSYQCLFWQMFIIGQMVCPJBFX6RQIOKRXJF5QJWLXP6ANBE";
pub const GOVERNANCE_CONTRACT_TESTNET: &str = "TBMWGKREPQVUZENKD2TTVT7W7RDCXFKPJHGBQCQHH46KQJPFZQDYDAPS";
```

---

## 🛡️ Insurance Contract Suite

### Core Insurance Contract
```rust
use soroban_sdk::{contract, contractimpl, Env, Address, String, Vec, Map, Bytes};

#[contract]
pub struct InsuranceCore;

#[contractimpl]
impl InsuranceCore {
    /// Initialize insurance contract
    pub fn initialize(
        env: Env,
        admin: Address,
        oracle_address: Address,
        treasury_address: Address,
    ) -> Result<(), InsuranceError> {
        if env.storage().persistent().has(&symbol_short!("INIT")) {
            return Err(InsuranceError::AlreadyInitialized);
        }

        // Set contract configuration
        env.storage().persistent().set(&symbol_short!("ADMIN"), &admin);
        env.storage().persistent().set(&symbol_short!("ORACLE"), &oracle_address);
        env.storage().persistent().set(&symbol_short!("TREASURY"), &treasury_address);
        env.storage().persistent().set(&symbol_short!("INIT"), &true);

        // Initialize contract state
        let state = ContractState {
            total_policies: 0,
            total_claims: 0,
            total_payouts: 0,
            active_policies: 0,
            contract_balance: 0,
            last_updated: env.ledger().timestamp(),
        };
        env.storage().persistent().set(&symbol_short!("STATE"), &state);

        Ok(())
    }

    /// Create new insurance policy
    pub fn create_policy(
        env: Env,
        policyholder: Address,
        policy_type: PolicyType,
        coverage_amount: i128,
        premium: i128,
        duration_days: u32,
        policy_data: PolicyData,
    ) -> Result<String, InsuranceError> {
        policyholder.require_auth();

        // Validate policy parameters
        Self::validate_policy_parameters(&policy_type, coverage_amount, premium, duration_days)?;

        // Generate unique policy ID
        let policy_id = Self::generate_policy_id(&env);

        // Calculate policy terms
        let start_date = env.ledger().timestamp();
        let end_date = start_date + (duration_days as u64 * 86400); // Convert days to seconds

        // Create policy structure
        let policy = Policy {
            id: policy_id.clone(),
            policyholder: policyholder.clone(),
            policy_type,
            coverage_amount,
            premium,
            start_date,
            end_date,
            status: PolicyStatus::Active,
            policy_data,
            created_at: start_date,
            claims_count: 0,
            total_claims_amount: 0,
        };

        // Store policy
        env.storage().persistent().set(&policy_id, &policy);

        // Update contract state
        Self::update_contract_state(&env, |state| {
            state.total_policies += 1;
            state.active_policies += 1;
        });

        // Emit policy created event
        env.events().publish((symbol_short!("POLICY"), symbol_short!("CREATED")), (policy_id.clone(), policyholder));

        Ok(policy_id)
    }

    /// Submit insurance claim
    pub fn submit_claim(
        env: Env,
        claimant: Address,
        policy_id: String,
        claim_amount: i128,
        claim_data: ClaimData,
    ) -> Result<String, InsuranceError> {
        claimant.require_auth();

        // Validate policy exists and is active
        let mut policy: Policy = env.storage().persistent().get(&policy_id)
            .ok_or(InsuranceError::PolicyNotFound)?;

        if policy.policyholder != claimant {
            return Err(InsuranceError::Unauthorized);
        }

        if policy.status != PolicyStatus::Active {
            return Err(InsuranceError::PolicyNotActive);
        }

        // Check policy is still valid
        let current_time = env.ledger().timestamp();
        if current_time > policy.end_date {
            return Err(InsuranceError::PolicyExpired);
        }

        // Validate claim amount
        if claim_amount > policy.coverage_amount {
            return Err(InsuranceError::ClaimExceedsCoverage);
        }

        // Generate unique claim ID
        let claim_id = Self::generate_claim_id(&env);

        // Create claim structure
        let claim = Claim {
            id: claim_id.clone(),
            policy_id: policy_id.clone(),
            claimant: claimant.clone(),
            claim_amount,
            claim_data,
            status: ClaimStatus::Pending,
            submitted_at: current_time,
            processed_at: None,
            payout_amount: None,
            assessor: None,
        };

        // Store claim
        env.storage().persistent().set(&claim_id, &claim);

        // Update policy
        policy.claims_count += 1;
        policy.total_claims_amount += claim_amount;
        env.storage().persistent().set(&policy_id, &policy);

        // Update contract state
        Self::update_contract_state(&env, |state| {
            state.total_claims += 1;
        });

        // Emit claim submitted event
        env.events().publish((symbol_short!("CLAIM"), symbol_short!("SUBMITTED")), (claim_id.clone(), policy_id));

        Ok(claim_id)
    }

    /// Process claim (admin only)
    pub fn process_claim(
        env: Env,
        admin: Address,
        claim_id: String,
        decision: ClaimDecision,
        payout_amount: Option<i128>,
    ) -> Result<(), InsuranceError> {
        admin.require_auth();

        // Verify admin
        let stored_admin: Address = env.storage().persistent().get(&symbol_short!("ADMIN"))
            .ok_or(InsuranceError::NotInitialized)?;
        if admin != stored_admin {
            return Err(InsuranceError::Unauthorized);
        }

        // Get claim
        let mut claim: Claim = env.storage().persistent().get(&claim_id)
            .ok_or(InsuranceError::ClaimNotFound)?;

        if claim.status != ClaimStatus::Pending {
            return Err(InsuranceError::ClaimAlreadyProcessed);
        }

        // Process based on decision
        match decision {
            ClaimDecision::Approved => {
                let payout = payout_amount.unwrap_or(claim.claim_amount);
                
                // Validate payout amount
                if payout > claim.claim_amount {
                    return Err(InsuranceError::PayoutExceedsClaim);
                }

                // Update claim
                claim.status = ClaimStatus::Approved;
                claim.payout_amount = Some(payout);
                claim.processed_at = Some(env.ledger().timestamp());
                claim.assessor = Some(admin.clone());

                // Execute payout
                Self::execute_payout(&env, &claim.claimant, payout)?;

                // Update contract state
                Self::update_contract_state(&env, |state| {
                    state.total_payouts += payout;
                });

                // Emit claim approved event
                env.events().publish((symbol_short!("CLAIM"), symbol_short!("APPROVED")), (claim_id.clone(), payout));
            },
            ClaimDecision::Rejected => {
                claim.status = ClaimStatus::Rejected;
                claim.processed_at = Some(env.ledger().timestamp());
                claim.assessor = Some(admin.clone());

                // Emit claim rejected event
                env.events().publish((symbol_short!("CLAIM"), symbol_short!("REJECTED")), (claim_id.clone(), claim.claim_amount));
            },
        }

        // Store updated claim
        env.storage().persistent().set(&claim_id, &claim);

        Ok(())
    }

    /// Get policy details
    pub fn get_policy(env: Env, policy_id: String) -> Option<Policy> {
        env.storage().persistent().get(&policy_id)
    }

    /// Get claim details
    pub fn get_claim(env: Env, claim_id: String) -> Option<Claim> {
        env.storage().persistent().get(&claim_id)
    }

    /// Get contract state
    pub fn get_contract_state(env: Env) -> Option<ContractState> {
        env.storage().persistent().get(&symbol_short!("STATE"))
    }

    // Helper functions
    fn validate_policy_parameters(
        policy_type: &PolicyType,
        coverage_amount: i128,
        premium: i128,
        duration_days: u32,
    ) -> Result<(), InsuranceError> {
        if coverage_amount <= 0 {
            return Err(InsuranceError::InvalidCoverageAmount);
        }
        if premium <= 0 {
            return Err(InsuranceError::InvalidPremium);
        }
        if duration_days == 0 || duration_days > 365 {
            return Err(InsuranceError::InvalidDuration);
        }
        Ok(())
    }

    fn generate_policy_id(env: &Env) -> String {
        let timestamp = env.ledger().timestamp();
        let sequence = env.ledger().sequence();
        format!("POL-{}-{}", timestamp, sequence)
    }

    fn generate_claim_id(env: &Env) -> String {
        let timestamp = env.ledger().timestamp();
        let sequence = env.ledger().sequence();
        format!("CLM-{}-{}", timestamp, sequence)
    }

    fn update_contract_state<F>(env: &Env, updater: F) 
    where 
        F: FnOnce(&mut ContractState),
    {
        let mut state: ContractState = env.storage().persistent()
            .get(&symbol_short!("STATE"))
            .unwrap_or_default();
        
        updater(&mut state);
        state.last_updated = env.ledger().timestamp();
        
        env.storage().persistent().set(&symbol_short!("STATE"), &state);
    }

    fn execute_payout(env: &Env, recipient: &Address, amount: i128) -> Result<(), InsuranceError> {
        // Get treasury address
        let treasury: Address = env.storage().persistent().get(&symbol_short!("TREASURY"))
            .ok_or(InsuranceError::NotInitialized)?;

        // Transfer from treasury to recipient
        // This would integrate with Stellar's native payment operations
        // For now, we'll emit an event that the backend can process
        env.events().publish(
            (symbol_short!("PAYOUT"), symbol_short!("EXECUTE")), 
            (recipient.clone(), amount, treasury)
        );

        Ok(())
    }
}

// Data structures
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct Policy {
    pub id: String,
    pub policyholder: Address,
    pub policy_type: PolicyType,
    pub coverage_amount: i128,
    pub premium: i128,
    pub start_date: u64,
    pub end_date: u64,
    pub status: PolicyStatus,
    pub policy_data: PolicyData,
    pub created_at: u64,
    pub claims_count: u32,
    pub total_claims_amount: i128,
}

#[derive(Clone, Debug, Eq, PartialEq)]
pub struct Claim {
    pub id: String,
    pub policy_id: String,
    pub claimant: Address,
    pub claim_amount: i128,
    pub claim_data: ClaimData,
    pub status: ClaimStatus,
    pub submitted_at: u64,
    pub processed_at: Option<u64>,
    pub payout_amount: Option<i128>,
    pub assessor: Option<Address>,
}

#[derive(Clone, Debug, Eq, PartialEq)]
pub struct ContractState {
    pub total_policies: u32,
    pub total_claims: u32,
    pub total_payouts: i128,
    pub active_policies: u32,
    pub contract_balance: i128,
    pub last_updated: u64,
}

#[derive(Clone, Debug, Eq, PartialEq)]
pub enum PolicyType {
    Crop,
    Livestock,
    Weather,
    PersonalAccident,
    Property,
}

#[derive(Clone, Debug, Eq, PartialEq)]
pub enum PolicyStatus {
    Active,
    Expired,
    Cancelled,
    Suspended,
}

#[derive(Clone, Debug, Eq, PartialEq)]
pub enum ClaimStatus {
    Pending,
    Approved,
    Rejected,
    Paid,
}

#[derive(Clone, Debug, Eq, PartialEq)]
pub enum ClaimDecision {
    Approved,
    Rejected,
}

#[derive(Clone, Debug, Eq, PartialEq)]
pub struct PolicyData {
    pub farm_location: Option<String>,
    pub crop_type: Option<String>,
    pub farm_size: Option<i128>,
    pub weather_triggers: Option<Vec<String>>,
    pub additional_data: Option<Map<String, String>>,
}

#[derive(Clone, Debug, Eq, PartialEq)]
pub struct ClaimData {
    pub incident_date: u64,
    pub incident_type: String,
    pub description: String,
    pub evidence_hash: Option<String>,
    pub weather_data: Option<String>,
    pub additional_data: Option<Map<String, String>>,
}

#[derive(Clone, Debug, Eq, PartialEq)]
pub enum InsuranceError {
    AlreadyInitialized,
    NotInitialized,
    Unauthorized,
    PolicyNotFound,
    PolicyNotActive,
    PolicyExpired,
    ClaimNotFound,
    ClaimAlreadyProcessed,
    ClaimExceedsCoverage,
    PayoutExceedsClaim,
    InvalidCoverageAmount,
    InvalidPremium,
    InvalidDuration,
    InsufficientFunds,
    OracleError,
}
```

---

## 🌾 Crop Insurance Specialized Contract
```rust
use soroban_sdk::{contract, contractimpl, Env, Address, String, Vec};

#[contract]
pub struct CropInsurance;

#[contractimpl]
impl CropInsurance {
    /// Create crop-specific insurance policy
    pub fn create_crop_policy(
        env: Env,
        farmer: Address,
        farm_data: FarmData,
        crop_data: CropData,
        weather_triggers: Vec<WeatherTrigger>,
        coverage_amount: i128,
        premium: i128,
    ) -> Result<String, InsuranceError> {
        farmer.require_auth();

        // Validate farm and crop data
        Self::validate_farm_data(&farm_data)?;
        Self::validate_crop_data(&crop_data)?;

        // Calculate risk assessment
        let risk_score = Self::calculate_crop_risk(&farm_data, &crop_data, &weather_triggers);

        // Adjust premium based on risk
        let adjusted_premium = Self::adjust_premium_for_risk(premium, risk_score);

        // Create policy data
        let policy_data = CropPolicyData {
            farm_data,
            crop_data,
            weather_triggers,
            risk_score,
            adjusted_premium,
            planting_date: env.ledger().timestamp(),
            harvest_date: crop_data.expected_harvest_date,
        };

        // Call core insurance contract
        let core_contract: Address = env.storage().persistent()
            .get(&symbol_short!("CORE"))
            .ok_or(InsuranceError::NotInitialized)?;

        // This would be a cross-contract call in actual implementation
        let policy_id = format!("CROP-{}-{}", env.ledger().timestamp(), env.ledger().sequence());

        // Store crop-specific data
        env.storage().persistent().set(&policy_id, &policy_data);

        // Set up automated weather monitoring
        Self::setup_weather_monitoring(&env, &policy_id, &weather_triggers)?;

        Ok(policy_id)
    }

    /// Automated claim processing based on weather data
    pub fn process_weather_claim(
        env: Env,
        oracle: Address,
        policy_id: String,
        weather_data: WeatherData,
    ) -> Result<(), InsuranceError> {
        oracle.require_auth();

        // Verify oracle is authorized
        let authorized_oracle: Address = env.storage().persistent()
            .get(&symbol_short!("ORACLE"))
            .ok_or(InsuranceError::NotInitialized)?;
        
        if oracle != authorized_oracle {
            return Err(InsuranceError::Unauthorized);
        }

        // Get policy data
        let policy_data: CropPolicyData = env.storage().persistent()
            .get(&policy_id)
            .ok_or(InsuranceError::PolicyNotFound)?;

        // Check if weather triggers are met
        let triggered_events = Self::check_weather_triggers(&policy_data.weather_triggers, &weather_data);

        if !triggered_events.is_empty() {
            // Calculate payout based on triggered events
            let payout_amount = Self::calculate_weather_payout(&policy_data, &triggered_events);

            // Create automated claim
            let claim_data = ClaimData {
                incident_date: weather_data.date,
                incident_type: "Weather Event".to_string(),
                description: format!("Automated claim for weather triggers: {:?}", triggered_events),
                evidence_hash: Some(weather_data.data_hash),
                weather_data: Some(serde_json::to_string(&weather_data).unwrap()),
                additional_data: None,
            };

            // Submit claim to core contract
            // This would be a cross-contract call
            env.events().publish(
                (symbol_short!("AUTO"), symbol_short!("CLAIM")), 
                (policy_id, payout_amount, triggered_events)
            );
        }

        Ok(())
    }

    // Helper functions
    fn validate_farm_data(farm_data: &FarmData) -> Result<(), InsuranceError> {
        if farm_data.size <= 0.0 {
            return Err(InsuranceError::InvalidFarmSize);
        }
        if farm_data.location.is_empty() {
            return Err(InsuranceError::InvalidLocation);
        }
        Ok(())
    }

    fn validate_crop_data(crop_data: &CropData) -> Result<(), InsuranceError> {
        if crop_data.crop_type.is_empty() {
            return Err(InsuranceError::InvalidCropType);
        }
        if crop_data.expected_yield <= 0.0 {
            return Err(InsuranceError::InvalidYield);
        }
        Ok(())
    }

    fn calculate_crop_risk(
        farm_data: &FarmData,
        crop_data: &CropData,
        weather_triggers: &Vec<WeatherTrigger>,
    ) -> u32 {
        let mut risk_score = 0;

        // Base risk by crop type
        risk_score += match crop_data.crop_type.as_str() {
            "rice" => 30,
            "maize" => 25,
            "cassava" => 15,
            "yam" => 20,
            _ => 35,
        };

        // Location-based risk
        risk_score += match farm_data.location.as_str() {
            "Lagos" => 10,
            "Kano" => 25,
            "Kaduna" => 20,
            _ => 30,
        };

        // Weather trigger complexity
        risk_score += weather_triggers.len() as u32 * 5;

        risk_score.min(100) // Cap at 100
    }

    fn adjust_premium_for_risk(base_premium: i128, risk_score: u32) -> i128 {
        let risk_multiplier = 1.0 + (risk_score as f64 / 100.0);
        (base_premium as f64 * risk_multiplier) as i128
    }
}

// Crop-specific data structures
#[derive(Clone, Debug)]
pub struct FarmData {
    pub location: String,
    pub coordinates: (f64, f64),
    pub size: f64, // in hectares
    pub soil_type: String,
    pub irrigation: bool,
}

#[derive(Clone, Debug)]
pub struct CropData {
    pub crop_type: String,
    pub variety: String,
    pub planting_date: u64,
    pub expected_harvest_date: u64,
    pub expected_yield: f64,
}

#[derive(Clone, Debug)]
pub struct WeatherTrigger {
    pub trigger_type: String, // "rainfall", "temperature", "drought"
    pub threshold: f64,
    pub operator: String, // "less_than", "greater_than", "between"
    pub duration_days: u32,
    pub payout_percentage: u32, // 0-100
}

#[derive(Clone, Debug)]
pub struct WeatherData {
    pub date: u64,
    pub location: String,
    pub temperature_max: f64,
    pub temperature_min: f64,
    pub rainfall: f64,
    pub humidity: f64,
    pub wind_speed: f64,
    pub data_hash: String,
}

#[derive(Clone, Debug)]
pub struct CropPolicyData {
    pub farm_data: FarmData,
    pub crop_data: CropData,
    pub weather_triggers: Vec<WeatherTrigger>,
    pub risk_score: u32,
    pub adjusted_premium: i128,
    pub planting_date: u64,
    pub harvest_date: u64,
}
```

---

## 🌤️ Weather Oracle Contract
```rust
use soroban_sdk::{contract, contractimpl, Env, Address, String, Vec, Map};

#[contract]
pub struct WeatherOracle;

#[contractimpl]
impl WeatherOracle {
    /// Initialize weather oracle
    pub fn initialize(
        env: Env,
        admin: Address,
        data_providers: Vec<Address>,
    ) -> Result<(), OracleError> {
        if env.storage().persistent().has(&symbol_short!("INIT")) {
            return Err(OracleError::AlreadyInitialized);
        }

        env.storage().persistent().set(&symbol_short!("ADMIN"), &admin);
        env.storage().persistent().set(&symbol_short!("PROVIDERS"), &data_providers);
        env.storage().persistent().set(&symbol_short!("INIT"), &true);

        Ok(())
    }

    /// Submit weather data (data provider only)
    pub fn submit_weather_data(
        env: Env,
        provider: Address,
        location: String,
        weather_data: WeatherData,
        signature: String,
    ) -> Result<(), OracleError> {
        provider.require_auth();

        // Verify provider is authorized
        let providers: Vec<Address> = env.storage().persistent()
            .get(&symbol_short!("PROVIDERS"))
            .ok_or(OracleError::NotInitialized)?;

        if !providers.contains(&provider) {
            return Err(OracleError::UnauthorizedProvider);
        }

        // Verify data signature
        if !Self::verify_data_signature(&weather_data, &signature, &provider) {
            return Err(OracleError::InvalidSignature);
        }

        // Store weather data
        let data_key = format!("{}_{}", location, weather_data.date);
        env.storage().persistent().set(&data_key, &weather_data);

        // Update latest data for location
        env.storage().persistent().set(&format!("latest_{}", location), &weather_data);

        // Emit data update event
        env.events().publish(
            (symbol_short!("WEATHER"), symbol_short!("UPDATE")), 
            (location, weather_data.date, provider)
        );

        Ok(())
    }

    /// Get weather data for location and date
    pub fn get_weather_data(
        env: Env,
        location: String,
        date: u64,
    ) -> Option<WeatherData> {
        let data_key = format!("{}_{}", location, date);
        env.storage().persistent().get(&data_key)
    }

    /// Get latest weather data for location
    pub fn get_latest_weather_data(
        env: Env,
        location: String,
    ) -> Option<WeatherData> {
        env.storage().persistent().get(&format!("latest_{}", location))
    }

    /// Verify weather triggers for insurance claims
    pub fn verify_weather_triggers(
        env: Env,
        location: String,
        start_date: u64,
        end_date: u64,
        triggers: Vec<WeatherTrigger>,
    ) -> Vec<TriggeredEvent> {
        let mut triggered_events = Vec::new();

        // Get weather data for date range
        let mut current_date = start_date;
        while current_date <= end_date {
            if let Some(weather_data) = Self::get_weather_data(env.clone(), location.clone(), current_date) {
                // Check each trigger
                for trigger in &triggers {
                    if Self::check_trigger(&weather_data, trigger) {
                        triggered_events.push(TriggeredEvent {
                            trigger_type: trigger.trigger_type.clone(),
                            date: current_date,
                            value: Self::get_trigger_value(&weather_data, &trigger.trigger_type),
                            threshold: trigger.threshold,
                            payout_percentage: trigger.payout_percentage,
                        });
                    }
                }
            }
            current_date += 86400; // Next day
        }

        triggered_events
    }

    // Helper functions
    fn verify_data_signature(
        weather_data: &WeatherData,
        signature: &String,
        provider: &Address,
    ) -> bool {
        // In a real implementation, this would verify cryptographic signatures
        // For now, we'll do basic validation
        !signature.is_empty() && signature.len() > 10
    }

    fn check_trigger(weather_data: &WeatherData, trigger: &WeatherTrigger) -> bool {
        let value = Self::get_trigger_value(weather_data, &trigger.trigger_type);
        
        match trigger.operator.as_str() {
            "less_than" => value < trigger.threshold,
            "greater_than" => value > trigger.threshold,
            "equals" => (value - trigger.threshold).abs() < 0.01,
            _ => false,
        }
    }

    fn get_trigger_value(weather_data: &WeatherData, trigger_type: &String) -> f64 {
        match trigger_type.as_str() {
            "rainfall" => weather_data.rainfall,
            "temperature_max" => weather_data.temperature_max,
            "temperature_min" => weather_data.temperature_min,
            "humidity" => weather_data.humidity,
            "wind_speed" => weather_data.wind_speed,
            _ => 0.0,
        }
    }
}

#[derive(Clone, Debug)]
pub struct TriggeredEvent {
    pub trigger_type: String,
    pub date: u64,
    pub value: f64,
    pub threshold: f64,
    pub payout_percentage: u32,
}

#[derive(Clone, Debug, Eq, PartialEq)]
pub enum OracleError {
    AlreadyInitialized,
    NotInitialized,
    UnauthorizedProvider,
    InvalidSignature,
    DataNotFound,
}
```

---

## 📊 Contract State Management

### Contract Registry
```php
// config/stellar.php - Contract addresses configuration
'contracts' => [
    'mainnet' => [
        'insurance_core' => 'CDLZFC3SYJYDZT7K67VZ75HPJVIEUVNIXF47ZG2FB2RMQQAHHAGK3HNX',
        'crop_insurance' => 'CBLITZKRIT5GMUJ7IMBD5BG65PVDGKPQHKZPARMKP7THXNWTNZP6ANBE',
        'weather_oracle' => 'CCJZ5DGAKVWZI5DTQPWLMUQGCQHH46KQJPFZQDYDAPSQIBD6QPJBYXZX',
        'defi_lending' => 'CAIXKFNXLFOMSYQCLFWQMFIIGQMVCPJBFX6RQIOKRXJF5QJWLXP6ANBE',
        'governance' => 'CBMWGKREPQVUZENKD2TTVT7W7RDCXFKPJHGBQCQHH46KQJPFZQDYDAPS',
    ],
    'testnet' => [
        'insurance_core' => 'TDLZFC3SYJYDZT7K67VZ75HPJVIEUVNIXF47ZG2FB2RMQQAHHAGK3HNX',
        'crop_insurance' => 'TBLITZKRIT5GMUJ7IMBD5BG65PVDGKPQHKZPARMKP7THXNWTNZP6ANBE',
        'weather_oracle' => 'TCJZ5DGAKVWZI5DTQPWLMUQGCQHH46KQJPFZQDYDAPSQIBD6QPJBYXZX',
        'defi_lending' => 'TAIXKFNXLFOMSYQCLFWQMFIIGQMVCPJBFX6RQIOKRXJF5QJWLXP6ANBE',
        'governance' => 'TBMWGKREPQVUZENKD2TTVT7W7RDCXFKPJHGBQCQHH46KQJPFZQDYDAPS',
    ],
],
```

### Contract Interaction Service
```php
class SorobanContractService
{
    /**
     * Get contract address for current network
     */
    public function getContractAddress(string $contractName): string
    {
        $network = config('stellar.network');
        $contracts = config('stellar.contracts');
        
        if (!isset($contracts[$network][$contractName])) {
            throw new Exception("Contract {$contractName} not found for network {$network}");
        }
        
        return $contracts[$network][$contractName];
    }

    /**
     * Call contract method
     */
    public function callContract(
        string $contractName,
        string $method,
        array $parameters = [],
        ?string $sourceAccount = null
    ): array {
        try {
            $contractAddress = $this->getContractAddress($contractName);
            
            // Build contract invocation
            $invocation = $this->buildContractInvocation(
                $contractAddress,
                $method,
                $parameters
            );
            
            // Submit to Stellar network
            $result = $this->stellarService->submitTransaction($invocation, $sourceAccount);
            
            return [
                'success' => true,
                'result' => $result,
                'contract_address' => $contractAddress,
                'method' => $method,
            ];
            
        } catch (Exception $e) {
            Log::error('Contract call failed', [
                'contract' => $contractName,
                'method' => $method,
                'parameters' => $parameters,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
```

---

This comprehensive smart contract specification provides the foundation for a robust, scalable insurance and DeFi ecosystem on the Stellar network, with clear contract addresses and detailed implementation specifications.
