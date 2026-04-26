use soroban_sdk::{contracttype, Address, Env, String, Vec};
use insurance_shared::{Policy, ContractConfig};

/// Storage keys for the insurance policy contract
#[contracttype]
#[derive(Clone)]
pub enum DataKey {
    /// Contract configuration
    Config,
    /// Policy data by policy ID
    Policy(String),
    /// Policy count for generating IDs
    PolicyCount,
    /// Payment contract authorized to activate policies after premium settlement
    PaymentContract,
    /// Policies by policyholder address
    PoliciesByHolder(Address),
    /// Active policies list
    ActivePolicies,
    /// Expired policies list
    ExpiredPolicies,
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
pub fn initialize_contract(env: &Env, config: &ContractConfig, payment_contract: &Address) {
    env.storage().instance().set(&DataKey::Config, config);
    env.storage().instance().set(&DataKey::PolicyCount, &0u64);
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

/// Update contract configuration (admin only)
pub fn set_config(env: &Env, config: &ContractConfig) {
    env.storage().instance().set(&DataKey::Config, config);
    bump_instance_ttl(env);
}

pub fn get_payment_contract(env: &Env) -> Address {
    let payment_contract = env.storage()
        .instance()
        .get(&DataKey::PaymentContract)
        .unwrap_or_else(|| panic!("Payment contract not configured"));

    bump_instance_ttl(env);

    payment_contract
}

/// Store a policy
pub fn set_policy(env: &Env, policy: &Policy) {
    let key = DataKey::Policy(policy.id.clone());
    env.storage().persistent().set(&key, policy);
    bump_persistent_ttl(env, &key);
}

/// Get a policy by ID
pub fn get_policy(env: &Env, policy_id: &String) -> Option<Policy> {
    let key = DataKey::Policy(policy_id.clone());
    let policy = env.storage().persistent().get(&key);

    if policy.is_some() {
        bump_persistent_ttl(env, &key);
    }

    policy
}

/// Check if a policy exists
pub fn has_policy(env: &Env, policy_id: &String) -> bool {
    let key = DataKey::Policy(policy_id.clone());
    let exists = env.storage().persistent().has(&key);

    if exists {
        bump_persistent_ttl(env, &key);
    }

    exists
}

/// Remove a policy
pub fn remove_policy(env: &Env, policy_id: &String) {
    env.storage()
        .persistent()
        .remove(&DataKey::Policy(policy_id.clone()));
}

/// Get and increment policy count
pub fn get_next_policy_count(env: &Env) -> u64 {
    let current_count: u64 = env.storage()
        .instance()
        .get(&DataKey::PolicyCount)
        .unwrap_or(0);
    
    let next_count = current_count + 1;
    env.storage()
        .instance()
        .set(&DataKey::PolicyCount, &next_count);

    bump_instance_ttl(env);

    next_count
}

/// Get current policy count
pub fn get_policy_count(env: &Env) -> u64 {
    let count = env.storage()
        .instance()
        .get(&DataKey::PolicyCount)
        .unwrap_or(0);

    bump_instance_ttl(env);

    count
}

/// Add policy to policyholder's list
pub fn add_policy_to_holder(env: &Env, holder: &Address, policy_id: &String) {
    let key = DataKey::PoliciesByHolder(holder.clone());
    let mut policies: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    policies.push_back(policy_id.clone());
    env.storage().persistent().set(&key, &policies);
    bump_persistent_ttl(env, &key);
}

/// Get policies by policyholder
pub fn get_policies_by_holder(env: &Env, holder: &Address) -> Vec<String> {
    let key = DataKey::PoliciesByHolder(holder.clone());
    let policies = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if env.storage().persistent().has(&key) {
        bump_persistent_ttl(env, &key);
    }

    policies
}

/// Add policy to active policies list
pub fn add_to_active_policies(env: &Env, policy_id: &String) {
    let key = DataKey::ActivePolicies;
    let mut active_policies: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    active_policies.push_back(policy_id.clone());
    env.storage().persistent().set(&key, &active_policies);
    bump_persistent_ttl(env, &key);
}

/// Remove policy from active policies list
pub fn remove_from_active_policies(env: &Env, policy_id: &String) {
    let key = DataKey::ActivePolicies;
    let mut active_policies: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if let Some(index) = active_policies.iter().position(|id| id == policy_id.clone()) {
        active_policies.remove(index as u32);
        env.storage().persistent().set(&key, &active_policies);
        bump_persistent_ttl(env, &key);
    }
}

/// Get active policies list
pub fn get_active_policies(env: &Env) -> Vec<String> {
    let key = DataKey::ActivePolicies;
    let policies = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if env.storage().persistent().has(&key) {
        bump_persistent_ttl(env, &key);
    }

    policies
}

/// Add policy to expired policies list
pub fn add_to_expired_policies(env: &Env, policy_id: &String) {
    let key = DataKey::ExpiredPolicies;
    let mut expired_policies: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    expired_policies.push_back(policy_id.clone());
    env.storage().persistent().set(&key, &expired_policies);
    bump_persistent_ttl(env, &key);
}

/// Get expired policies list
pub fn get_expired_policies(env: &Env) -> Vec<String> {
    let key = DataKey::ExpiredPolicies;
    let policies = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if env.storage().persistent().has(&key) {
        bump_persistent_ttl(env, &key);
    }

    policies
}
