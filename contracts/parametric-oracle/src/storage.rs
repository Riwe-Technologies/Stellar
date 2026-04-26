use soroban_sdk::{contracttype, xdr::ToXdr, Address, Env, String, Vec};
use insurance_shared::{ContractConfig, Location, OracleSubmission};

const HEX_CHARS: &[u8; 16] = b"0123456789abcdef";
const SUBMISSION_ID_HASH_BYTES: usize = 12;

/// Storage keys for the parametric oracle contract
#[contracttype]
#[derive(Clone)]
pub enum DataKey {
    /// Contract configuration
    Config,
    /// Oracle submission by ID
    Submission(String),
    /// Submissions by oracle address
    SubmissionsByOracle(Address),
    /// Submissions by location
    SubmissionsByLocation(Location),
    /// Latest submission for location
    LatestSubmission(Location),
    /// Submission count
    SubmissionCount,
    /// Data retention period in seconds
    DataRetentionPeriod,
}

const TTL_RENEWAL_WINDOW: u32 = 10_000;

fn build_submission_id(env: &Env, submission: &OracleSubmission, submission_count: u64) -> String {
    let payload = (
        String::from_str(env, "SUB"),
        submission.submitted_at,
        submission_count,
        submission.oracle.clone(),
    )
        .to_xdr(env);
    let hash_bytes = env.crypto().sha256(&payload).to_array();

    let mut buffer = [0u8; 32];
    let mut length = 0usize;

    buffer[length..length + 4].copy_from_slice(b"SUB-");
    length += 4;

    for byte in &hash_bytes[..SUBMISSION_ID_HASH_BYTES] {
        buffer[length] = HEX_CHARS[(byte >> 4) as usize];
        length += 1;
        buffer[length] = HEX_CHARS[(byte & 0x0f) as usize];
        length += 1;
    }

    String::from_bytes(env, &buffer[..length])
}

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
    data_retention_period: u64,
) {
    env.storage().instance().set(&DataKey::Config, config);
    env.storage().instance().set(&DataKey::SubmissionCount, &0u64);
    env.storage()
        .instance()
        .set(&DataKey::DataRetentionPeriod, &data_retention_period);
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

/// Store an oracle submission
pub fn set_submission(env: &Env, submission: &OracleSubmission) -> String {
    let submission_count = get_next_submission_count(env);
    let submission_key = build_submission_id(env, submission, submission_count);

    let submission_storage_key = DataKey::Submission(submission_key.clone());
    env.storage().persistent().set(&submission_storage_key, submission);
    bump_persistent_ttl(env, &submission_storage_key);
    
    // Add to oracle's submissions
    add_submission_to_oracle(env, &submission.oracle, &submission_key);
    
    // Add to location submissions
    let location_key = submission.data.location.clone();
    add_submission_to_location(env, &location_key, &submission_key);
    
    // Update latest submission for location
    set_latest_submission_for_location(env, &location_key, &submission_key);

    submission_key
}

/// Get an oracle submission by ID
pub fn get_submission(env: &Env, submission_id: &String) -> Option<OracleSubmission> {
    let key = DataKey::Submission(submission_id.clone());
    let submission = env.storage().persistent().get(&key);

    if submission.is_some() {
        bump_persistent_ttl(env, &key);
    }

    submission
}

/// Add submission to oracle's list
pub fn add_submission_to_oracle(env: &Env, oracle: &Address, submission_id: &String) {
    let key = DataKey::SubmissionsByOracle(oracle.clone());
    let mut submissions: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    submissions.push_back(submission_id.clone());
    env.storage().persistent().set(&key, &submissions);
    bump_persistent_ttl(env, &key);
}

/// Get submissions by oracle
pub fn get_submissions_by_oracle(env: &Env, oracle: &Address) -> Vec<String> {
    let key = DataKey::SubmissionsByOracle(oracle.clone());
    let submissions = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if env.storage().persistent().has(&key) {
        bump_persistent_ttl(env, &key);
    }

    submissions
}

/// Add submission to location's list
pub fn add_submission_to_location(env: &Env, location_key: &Location, submission_id: &String) {
    let key = DataKey::SubmissionsByLocation(location_key.clone());
    let mut submissions: Vec<String> = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    submissions.push_back(submission_id.clone());
    env.storage().persistent().set(&key, &submissions);
    bump_persistent_ttl(env, &key);
}

/// Get submissions by location
pub fn get_submissions_by_location(env: &Env, location_key: &Location) -> Vec<String> {
    let key = DataKey::SubmissionsByLocation(location_key.clone());
    let submissions = env.storage()
        .persistent()
        .get(&key)
        .unwrap_or_else(|| Vec::new(env));

    if env.storage().persistent().has(&key) {
        bump_persistent_ttl(env, &key);
    }

    submissions
}

/// Set latest submission for location
pub fn set_latest_submission_for_location(env: &Env, location_key: &Location, submission_id: &String) {
    let key = DataKey::LatestSubmission(location_key.clone());
    env.storage().persistent().set(&key, submission_id);
    bump_persistent_ttl(env, &key);
}

/// Get latest submission for location
pub fn get_latest_submission_for_location(env: &Env, location_key: &Location) -> Option<String> {
    let key = DataKey::LatestSubmission(location_key.clone());
    let submission = env.storage().persistent().get(&key);

    if submission.is_some() {
        bump_persistent_ttl(env, &key);
    }

    submission
}

/// Get and increment submission count
pub fn get_next_submission_count(env: &Env) -> u64 {
    let current_count: u64 = env.storage()
        .instance()
        .get(&DataKey::SubmissionCount)
        .unwrap_or(0);
    
    let next_count = current_count + 1;
    env.storage()
        .instance()
        .set(&DataKey::SubmissionCount, &next_count);

    bump_instance_ttl(env);

    next_count
}

/// Get current submission count
pub fn get_submission_count(env: &Env) -> u64 {
    let count = env.storage()
        .instance()
        .get(&DataKey::SubmissionCount)
        .unwrap_or(0);

    bump_instance_ttl(env);

    count
}

/// Get data retention period
pub fn get_data_retention_period(env: &Env) -> u64 {
    let retention_period = env.storage()
        .instance()
        .get(&DataKey::DataRetentionPeriod)
        .unwrap_or(86400);

    bump_instance_ttl(env);

    retention_period
}
