use soroban_sdk::{contracttype, Address, Env, String, Vec};
use insurance_shared::{Payment, ContractConfig};

/// Storage keys for the insurance payment contract
#[contracttype]
#[derive(Clone)]
pub enum DataKey {
    /// Contract configuration
    Config,
    /// Payment data by payment ID
    Payment(String),
    /// Payment count for generating IDs
    PaymentCount,
    /// Payments by payer address
    PaymentsByPayer(Address),
    /// Payments by recipient address
    PaymentsByRecipient(Address),
    /// Payments by policy ID
    PaymentsByPolicy(String),
    /// Payments by claim ID
    PaymentsByClaim(String),
    /// Pending payments list
    PendingPayments,
    /// Completed payments list
    CompletedPayments,
    /// Failed payments list
    FailedPayments,
    /// Policy contract address
    PolicyContract,
    /// Claim contract address
    ClaimContract,
    /// Insurance pool balance
    InsurancePool,
    /// Supported tokens
    SupportedTokens,
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
    claim_contract: &Address,
    supported_tokens: &Vec<Address>,
) {
    env.storage().instance().set(&DataKey::Config, config);
    env.storage().instance().set(&DataKey::PaymentCount, &0u64);
    env.storage().instance().set(&DataKey::PolicyContract, policy_contract);
    env.storage().instance().set(&DataKey::ClaimContract, claim_contract);
    env.storage().instance().set(&DataKey::SupportedTokens, supported_tokens);
    env.storage().instance().set(&DataKey::InsurancePool, &0i128);
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

/// Get claim contract address
pub fn get_claim_contract(env: &Env) -> Address {
    let claim_contract = env.storage()
        .instance()
        .get(&DataKey::ClaimContract)
        .unwrap_or_else(|| panic!("Claim contract not set"));

    bump_instance_ttl(env);

    claim_contract
}

/// Get supported tokens
pub fn get_supported_tokens(env: &Env) -> Vec<Address> {
    let tokens = env.storage()
        .instance()
        .get(&DataKey::SupportedTokens)
        .unwrap_or_else(|| Vec::new(env));

    bump_instance_ttl(env);

    tokens
}

/// Add supported token
pub fn add_supported_token(env: &Env, token: &Address) {
    let mut tokens = get_supported_tokens(env);
    if !tokens.contains(token) {
        tokens.push_back(token.clone());
        env.storage().instance().set(&DataKey::SupportedTokens, &tokens);
        bump_instance_ttl(env);
    }
}

/// Remove supported token
pub fn remove_supported_token(env: &Env, token: &Address) {
    let mut tokens = get_supported_tokens(env);
    if let Some(index) = tokens.iter().position(|t| t == token.clone()) {
        tokens.remove(index as u32);
        env.storage().instance().set(&DataKey::SupportedTokens, &tokens);
        bump_instance_ttl(env);
    }
}

/// Store a payment
pub fn set_payment(env: &Env, payment: &Payment) {
    let key = DataKey::Payment(payment.id.clone());
    env.storage().persistent().set(&key, payment);
    bump_persistent_ttl(env, &key);
}

/// Get a payment by ID
pub fn get_payment(env: &Env, payment_id: &String) -> Option<Payment> {
    let key = DataKey::Payment(payment_id.clone());
    let payment = env.storage().persistent().get(&key);

    if payment.is_some() {
        bump_persistent_ttl(env, &key);
    }

    payment
}

/// Check if a payment exists
pub fn has_payment(env: &Env, payment_id: &String) -> bool {
    let key = DataKey::Payment(payment_id.clone());
    let exists = env.storage().persistent().has(&key);

    if exists {
        bump_persistent_ttl(env, &key);
    }

    exists
}

/// Get and increment payment count
pub fn get_next_payment_count(env: &Env) -> u64 {
    let current_count: u64 = env.storage()
        .instance()
        .get(&DataKey::PaymentCount)
        .unwrap_or(0);
    
    let next_count = current_count + 1;
    env.storage()
        .instance()
        .set(&DataKey::PaymentCount, &next_count);

    bump_instance_ttl(env);

    next_count
}

/// Get current payment count
pub fn get_payment_count(env: &Env) -> u64 {
    let count = env.storage()
        .instance()
        .get(&DataKey::PaymentCount)
        .unwrap_or(0);

    bump_instance_ttl(env);

    count
}

/// Add payment to payer's list
pub fn add_payment_to_payer(env: &Env, payer: &Address, payment_id: &String) {
    let key = DataKey::PaymentsByPayer(payer.clone());
    let mut payments: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    payments.push_back(payment_id.clone());
    env.storage().persistent().set(&key, &payments);
    bump_persistent_ttl(env, &key);
}

/// Get payments by payer
pub fn get_payments_by_payer(env: &Env, payer: &Address) -> Vec<String> {
    let key = DataKey::PaymentsByPayer(payer.clone());
    let payments = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if env.storage().persistent().has(&key) {
        bump_persistent_ttl(env, &key);
    }

    payments
}

/// Add payment to recipient's list
pub fn add_payment_to_recipient(env: &Env, recipient: &Address, payment_id: &String) {
    let key = DataKey::PaymentsByRecipient(recipient.clone());
    let mut payments: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    payments.push_back(payment_id.clone());
    env.storage().persistent().set(&key, &payments);
    bump_persistent_ttl(env, &key);
}

/// Get payments by recipient
pub fn get_payments_by_recipient(env: &Env, recipient: &Address) -> Vec<String> {
    let key = DataKey::PaymentsByRecipient(recipient.clone());
    let payments = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if env.storage().persistent().has(&key) {
        bump_persistent_ttl(env, &key);
    }

    payments
}

/// Add payment to policy's list
pub fn add_payment_to_policy(env: &Env, policy_id: &String, payment_id: &String) {
    let key = DataKey::PaymentsByPolicy(policy_id.clone());
    let mut payments: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    payments.push_back(payment_id.clone());
    env.storage().persistent().set(&key, &payments);
    bump_persistent_ttl(env, &key);
}

/// Get payments by policy
pub fn get_payments_by_policy(env: &Env, policy_id: &String) -> Vec<String> {
    let key = DataKey::PaymentsByPolicy(policy_id.clone());
    let payments = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if env.storage().persistent().has(&key) {
        bump_persistent_ttl(env, &key);
    }

    payments
}

/// Add payment to claim's list
pub fn add_payment_to_claim(env: &Env, claim_id: &String, payment_id: &String) {
    let key = DataKey::PaymentsByClaim(claim_id.clone());
    let mut payments: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    payments.push_back(payment_id.clone());
    env.storage().persistent().set(&key, &payments);
    bump_persistent_ttl(env, &key);
}

/// Get payments by claim
pub fn get_payments_by_claim(env: &Env, claim_id: &String) -> Vec<String> {
    let key = DataKey::PaymentsByClaim(claim_id.clone());
    let payments = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if env.storage().persistent().has(&key) {
        bump_persistent_ttl(env, &key);
    }

    payments
}

/// Add payment to pending payments list
pub fn add_to_pending_payments(env: &Env, payment_id: &String) {
    let key = DataKey::PendingPayments;
    let mut pending_payments: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    pending_payments.push_back(payment_id.clone());
    env.storage().persistent().set(&key, &pending_payments);
    bump_persistent_ttl(env, &key);
}

/// Remove payment from pending payments list
pub fn remove_from_pending_payments(env: &Env, payment_id: &String) {
    let key = DataKey::PendingPayments;
    let mut pending_payments: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if let Some(index) = pending_payments.iter().position(|id| id == payment_id.clone()) {
        pending_payments.remove(index as u32);
        env.storage().persistent().set(&key, &pending_payments);
        bump_persistent_ttl(env, &key);
    }
}

/// Get pending payments list
pub fn get_pending_payments(env: &Env) -> Vec<String> {
    let key = DataKey::PendingPayments;
    let payments = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if env.storage().persistent().has(&key) {
        bump_persistent_ttl(env, &key);
    }

    payments
}

/// Add payment to completed payments list
pub fn add_to_completed_payments(env: &Env, payment_id: &String) {
    let key = DataKey::CompletedPayments;
    let mut completed_payments: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    completed_payments.push_back(payment_id.clone());
    env.storage().persistent().set(&key, &completed_payments);
    bump_persistent_ttl(env, &key);
}

/// Get completed payments list
pub fn get_completed_payments(env: &Env) -> Vec<String> {
    let key = DataKey::CompletedPayments;
    let payments = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if env.storage().persistent().has(&key) {
        bump_persistent_ttl(env, &key);
    }

    payments
}

/// Add payment to failed payments list
pub fn add_to_failed_payments(env: &Env, payment_id: &String) {
    let key = DataKey::FailedPayments;
    let mut failed_payments: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    failed_payments.push_back(payment_id.clone());
    env.storage().persistent().set(&key, &failed_payments);
    bump_persistent_ttl(env, &key);
}

/// Get failed payments list
pub fn get_failed_payments(env: &Env) -> Vec<String> {
    let key = DataKey::FailedPayments;
    let payments = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if env.storage().persistent().has(&key) {
        bump_persistent_ttl(env, &key);
    }

    payments
}

/// Get insurance pool balance
pub fn get_insurance_pool_balance(env: &Env) -> i128 {
    let balance = env.storage()
        .instance()
        .get(&DataKey::InsurancePool)
        .unwrap_or(0);

    bump_instance_ttl(env);

    balance
}

/// Update insurance pool balance
pub fn update_insurance_pool_balance(env: &Env, new_balance: i128) {
    env.storage().instance().set(&DataKey::InsurancePool, &new_balance);
    bump_instance_ttl(env);
}

/// Add to insurance pool
pub fn add_to_insurance_pool(env: &Env, amount: i128) {
    let current_balance = get_insurance_pool_balance(env);
    update_insurance_pool_balance(env, current_balance + amount);
}

/// Subtract from insurance pool
pub fn subtract_from_insurance_pool(env: &Env, amount: i128) -> Result<(), insurance_shared::InsuranceError> {
    let current_balance = get_insurance_pool_balance(env);
    if current_balance < amount {
        return Err(insurance_shared::InsuranceError::InsufficientFunds);
    }
    update_insurance_pool_balance(env, current_balance - amount);
    Ok(())
}
