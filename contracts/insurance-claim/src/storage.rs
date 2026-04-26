use soroban_sdk::{contracttype, Address, Env, String, Vec};
use insurance_shared::{Claim, ContractConfig};

/// Storage keys for the insurance claim contract
#[contracttype]
#[derive(Clone)]
pub enum DataKey {
    /// Contract configuration
    Config,
    /// Claim data by claim ID
    Claim(String),
    /// Claim count for generating IDs
    ClaimCount,
    /// Claims by claimant address
    ClaimsByClaimant(Address),
    /// Claims by policy ID
    ClaimsByPolicy(String),
    /// Pending claims list
    PendingClaims,
    /// Approved claims list
    ApprovedClaims,
    /// Rejected claims list
    RejectedClaims,
    /// Paid claims list
    PaidClaims,
    /// Policy contract address
    PolicyContract,
    /// Payment contract address
    PaymentContract,
}

const TTL_RENEWAL_WINDOW: u32 = 10_000;

fn ttl_bump_values(env: &Env) -> (u32, u32) {
    let extend_to = env.storage().max_ttl();
    let threshold = extend_to.saturating_sub(TTL_RENEWAL_WINDOW);
    (threshold, extend_to)
}

fn bump_instance_ttl(env: &Env) {
    let (threshold, extend_to) = ttl_bump_values(env);
    env.storage().instance().extend_ttl(threshold, extend_to);
}

fn bump_persistent_ttl(env: &Env, key: &DataKey) {
    let (threshold, extend_to) = ttl_bump_values(env);
    env.storage().persistent().extend_ttl(key, threshold, extend_to);
}

/// Initialize contract storage
pub fn initialize_contract(
    env: &Env, 
    config: &ContractConfig,
    policy_contract: &Address,
    payment_contract: &Address,
) {
    env.storage().instance().set(&DataKey::Config, config);
    env.storage().instance().set(&DataKey::ClaimCount, &0u64);
    env.storage().instance().set(&DataKey::PolicyContract, policy_contract);
    env.storage().instance().set(&DataKey::PaymentContract, payment_contract);
    bump_instance_ttl(env);
}

/// Get contract configuration
pub fn get_config(env: &Env) -> ContractConfig {
    let config = env.storage()
        .instance()
        .get(&DataKey::Config)
        .unwrap_or_else(|| panic!("Contract not initialized"));

    bump_instance_ttl(env);

    config
}

/// Update contract configuration
pub fn set_config(env: &Env, config: &ContractConfig) {
    env.storage().instance().set(&DataKey::Config, config);
    bump_instance_ttl(env);
}

/// Get policy contract address
pub fn get_policy_contract(env: &Env) -> Address {
    let policy_contract = env.storage()
        .instance()
        .get(&DataKey::PolicyContract)
        .unwrap_or_else(|| panic!("Policy contract not set"));

    bump_instance_ttl(env);

    policy_contract
}

/// Get payment contract address
pub fn get_payment_contract(env: &Env) -> Address {
    let payment_contract = env.storage()
        .instance()
        .get(&DataKey::PaymentContract)
        .unwrap_or_else(|| panic!("Payment contract not set"));

    bump_instance_ttl(env);

    payment_contract
}

/// Store a claim
pub fn set_claim(env: &Env, claim: &Claim) {
    let key = DataKey::Claim(claim.id.clone());
    env.storage().persistent().set(&key, claim);
    bump_persistent_ttl(env, &key);
}

/// Get a claim by ID
pub fn get_claim(env: &Env, claim_id: &String) -> Option<Claim> {
    let key = DataKey::Claim(claim_id.clone());
    let claim = env.storage().persistent().get(&key);

    if claim.is_some() {
        bump_persistent_ttl(env, &key);
    }

    claim
}

/// Check if a claim exists
pub fn has_claim(env: &Env, claim_id: &String) -> bool {
    let key = DataKey::Claim(claim_id.clone());
    let exists = env.storage().persistent().has(&key);

    if exists {
        bump_persistent_ttl(env, &key);
    }

    exists
}

/// Remove a claim
pub fn remove_claim(env: &Env, claim_id: &String) {
    env.storage()
        .persistent()
        .remove(&DataKey::Claim(claim_id.clone()));
}

/// Get and increment claim count
pub fn get_next_claim_count(env: &Env) -> u64 {
    let current_count: u64 = env.storage()
        .instance()
        .get(&DataKey::ClaimCount)
        .unwrap_or(0);
    
    let next_count = current_count + 1;
    env.storage()
        .instance()
        .set(&DataKey::ClaimCount, &next_count);

    bump_instance_ttl(env);

    next_count
}

/// Get current claim count
pub fn get_claim_count(env: &Env) -> u64 {
    let count = env.storage()
        .instance()
        .get(&DataKey::ClaimCount)
        .unwrap_or(0);

    bump_instance_ttl(env);

    count
}

/// Add claim to claimant's list
pub fn add_claim_to_claimant(env: &Env, claimant: &Address, claim_id: &String) {
    let key = DataKey::ClaimsByClaimant(claimant.clone());
    let mut claims: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    claims.push_back(claim_id.clone());
    env.storage().persistent().set(&key, &claims);
    bump_persistent_ttl(env, &key);
}

/// Get claims by claimant
pub fn get_claims_by_claimant(env: &Env, claimant: &Address) -> Vec<String> {
    let key = DataKey::ClaimsByClaimant(claimant.clone());
    let claims = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if env.storage().persistent().has(&key) {
        bump_persistent_ttl(env, &key);
    }

    claims
}

/// Add claim to policy's list
pub fn add_claim_to_policy(env: &Env, policy_id: &String, claim_id: &String) {
    let key = DataKey::ClaimsByPolicy(policy_id.clone());
    let mut claims: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    claims.push_back(claim_id.clone());
    env.storage().persistent().set(&key, &claims);
    bump_persistent_ttl(env, &key);
}

/// Get claims by policy
pub fn get_claims_by_policy(env: &Env, policy_id: &String) -> Vec<String> {
    let key = DataKey::ClaimsByPolicy(policy_id.clone());
    let claims = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if env.storage().persistent().has(&key) {
        bump_persistent_ttl(env, &key);
    }

    claims
}

/// Add claim to pending claims list
pub fn add_to_pending_claims(env: &Env, claim_id: &String) {
    let key = DataKey::PendingClaims;
    let mut pending_claims: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    pending_claims.push_back(claim_id.clone());
    env.storage().persistent().set(&key, &pending_claims);
    bump_persistent_ttl(env, &key);
}

/// Remove claim from pending claims list
pub fn remove_from_pending_claims(env: &Env, claim_id: &String) {
    let key = DataKey::PendingClaims;
    let mut pending_claims: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if let Some(index) = pending_claims.iter().position(|id| id == claim_id.clone()) {
        pending_claims.remove(index as u32);
        env.storage().persistent().set(&key, &pending_claims);
        bump_persistent_ttl(env, &key);
    }
}

/// Get pending claims list
pub fn get_pending_claims(env: &Env) -> Vec<String> {
    let key = DataKey::PendingClaims;
    let claims = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if env.storage().persistent().has(&key) {
        bump_persistent_ttl(env, &key);
    }

    claims
}

/// Add claim to approved claims list
pub fn add_to_approved_claims(env: &Env, claim_id: &String) {
    let key = DataKey::ApprovedClaims;
    let mut approved_claims: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    approved_claims.push_back(claim_id.clone());
    env.storage().persistent().set(&key, &approved_claims);
    bump_persistent_ttl(env, &key);
}

/// Get approved claims list
pub fn get_approved_claims(env: &Env) -> Vec<String> {
    let key = DataKey::ApprovedClaims;
    let claims = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if env.storage().persistent().has(&key) {
        bump_persistent_ttl(env, &key);
    }

    claims
}

/// Remove claim from approved claims list
pub fn remove_from_approved_claims(env: &Env, claim_id: &String) {
    let key = DataKey::ApprovedClaims;
    let mut approved_claims: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if let Some(index) = approved_claims.iter().position(|id| id == claim_id.clone()) {
        approved_claims.remove(index as u32);
        env.storage().persistent().set(&key, &approved_claims);
        bump_persistent_ttl(env, &key);
    }
}

/// Add claim to rejected claims list
pub fn add_to_rejected_claims(env: &Env, claim_id: &String) {
    let key = DataKey::RejectedClaims;
    let mut rejected_claims: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    rejected_claims.push_back(claim_id.clone());
    env.storage().persistent().set(&key, &rejected_claims);
    bump_persistent_ttl(env, &key);
}

/// Get rejected claims list
pub fn get_rejected_claims(env: &Env) -> Vec<String> {
    let key = DataKey::RejectedClaims;
    let claims = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if env.storage().persistent().has(&key) {
        bump_persistent_ttl(env, &key);
    }

    claims
}

/// Add claim to paid claims list
pub fn add_to_paid_claims(env: &Env, claim_id: &String) {
    let key = DataKey::PaidClaims;
    let mut paid_claims: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    paid_claims.push_back(claim_id.clone());
    env.storage().persistent().set(&key, &paid_claims);
    bump_persistent_ttl(env, &key);
}

/// Get paid claims list
pub fn get_paid_claims(env: &Env) -> Vec<String> {
    let key = DataKey::PaidClaims;
    let claims = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if env.storage().persistent().has(&key) {
        bump_persistent_ttl(env, &key);
    }

    claims
}
