use soroban_sdk::{
    contract, contractimpl, contractmeta, xdr::ToXdr, Address, Bytes, BytesN, Env, String, Vec, log
};
use insurance_shared::{
    ContractConfig, InsuranceError, Oracle, OracleSubmission, ParametricData,
    Location, Measurement, validate_location, 
    validate_data_freshness, validate_confidence_score, is_authorized_oracle,
    get_oracle_public_key
};
use crate::storage;

// Contract metadata
contractmeta!(
    key = "Description",
    val = "Parametric Oracle Data Management Contract"
);

/// Parametric Oracle Contract
/// 
/// This contract manages environmental data feeds including:
/// - Oracle data submission
/// - Data validation and confidence scoring
/// - Historical data storage
/// - Location-based data queries
#[contract]
pub struct ParametricOracleContract;

#[contractimpl]
impl ParametricOracleContract {
    /// Initialize the contract with configuration
    pub fn initialize(
        env: Env,
        admin: Address,
        authorized_oracles: Vec<Oracle>,
        data_retention_period: u64,
        minimum_confidence_score: u32,
    ) -> Result<(), InsuranceError> {
        let config = ContractConfig {
            admin: admin.clone(),
            oracles: authorized_oracles,
            minimum_confidence_score,
            auto_payout_threshold: 80, // Default
            fee_percentage: 0, // No fees for oracle
            fee_recipient: admin,
        };

        storage::initialize_contract(&env, &config, data_retention_period);
        
        log!(&env, "Parametric Oracle Contract initialized");
        Ok(())
    }

    /// Submit environmental data
    pub fn submit_data(
        env: Env,
        oracle_address: Address,
        location: Location,
        measurements: Vec<Measurement>,
        confidence_score: u32,
        data_source: String,
        signature: BytesN<64>,
    ) -> Result<String, InsuranceError> {
        // Verify oracle authorization
        let config = storage::get_config(&env);
        if !is_authorized_oracle(&oracle_address, &config.oracles) {
            return Err(InsuranceError::OracleNotAuthorized);
        }
        oracle_address.require_auth();

        // Validate inputs
        validate_location(&location)?;
        validate_confidence_score(confidence_score, config.minimum_confidence_score)?;

        // Create parametric data
        let current_time = env.ledger().timestamp();
        let parametric_data = ParametricData {
            location: location.clone(),
            measurements,
            confidence_score,
            data_source,
            timestamp: current_time,
        };

        // Hash the data to be signed
        let data_hash = env.crypto().sha256(&parametric_data.clone().to_xdr(&env));

        // Validate the signature
        validate_signature(
            &env,
            &oracle_address,
            &config.oracles,
            data_hash.into(),
            signature.clone(),
        )?;

        // Create oracle submission
        let submission = OracleSubmission {
            oracle: oracle_address.clone(),
            data: parametric_data,
            signature,
            submitted_at: current_time,
        };

        // Store submission and get the canonical ID
        let submission_id = storage::set_submission(&env, &submission);

        log!(&env, "Oracle data submitted: {} by oracle: {}", submission_id, oracle_address);
        Ok(submission_id)
    }

    /// Get latest data for location
    pub fn get_latest_data(
        env: Env,
        location: Location,
    ) -> Result<ParametricData, InsuranceError> {
        let latest_submission_id = storage::get_latest_submission_for_location(&env, &location)
            .ok_or(InsuranceError::DataTooOld)?;

        let submission = storage::get_submission(&env, &latest_submission_id)
            .ok_or(InsuranceError::DataTooOld)?;

        // Check if data is still fresh
        let retention_period = storage::get_data_retention_period(&env);
        validate_data_freshness(&env, submission.data.timestamp, retention_period)?;

        Ok(submission.data)
    }

    /// Get historical data for location
    pub fn get_historical_data(
        env: Env,
        location: Location,
        from_timestamp: u64,
        to_timestamp: u64,
    ) -> Vec<ParametricData> {
        let submission_ids = storage::get_submissions_by_location(&env, &location);
        
        let mut historical_data = Vec::new(&env);
        
        for submission_id in submission_ids {
            if let Some(submission) = storage::get_submission(&env, &submission_id) {
                if submission.data.timestamp >= from_timestamp && submission.data.timestamp <= to_timestamp {
                    historical_data.push_back(submission.data);
                }
            }
        }
        
        historical_data
    }

    /// Get submissions by oracle
    pub fn get_oracle_submissions(env: Env, oracle: Address) -> Vec<String> {
        storage::get_submissions_by_oracle(&env, &oracle)
    }

    /// Get total submission count
    pub fn get_submission_count(env: Env) -> u64 {
        storage::get_submission_count(&env)
    }

    /// Add authorized oracle (admin only)
    pub fn add_oracle(
        env: Env,
        caller: Address,
        new_oracle: Oracle,
    ) -> Result<(), InsuranceError> {
        let mut config = storage::get_config(&env);
        if caller != config.admin {
            return Err(InsuranceError::Unauthorized);
        }
        caller.require_auth();

        if !is_authorized_oracle(&new_oracle.address, &config.oracles) {
            config.oracles.push_back(new_oracle.clone());
            storage::set_config(&env, &config);
            log!(&env, "Oracle added: {}", new_oracle.address);
        }

        Ok(())
    }

    /// Remove authorized oracle (admin only)
    pub fn remove_oracle(
        env: Env,
        caller: Address,
        oracle_to_remove: Address,
    ) -> Result<(), InsuranceError> {
        let mut config = storage::get_config(&env);
        if caller != config.admin {
            return Err(InsuranceError::Unauthorized);
        }
        caller.require_auth();

        // Find and remove oracle
        if let Some(index) = config.oracles.iter().position(|o| o.address == oracle_to_remove) {
            config.oracles.remove(index as u32);
            storage::set_config(&env, &config);
            log!(&env, "Oracle removed: {}", oracle_to_remove);
        }

        Ok(())
    }

    /// Get contract configuration
    pub fn get_config(env: Env) -> ContractConfig {
        storage::get_config(&env)
    }

    /// Update contract configuration (admin only)
    pub fn update_config(
        env: Env,
        caller: Address,
        new_config: ContractConfig,
    ) -> Result<(), InsuranceError> {
        let config = storage::get_config(&env);
        if caller != config.admin {
            return Err(InsuranceError::Unauthorized);
        }
        caller.require_auth();

        storage::set_config(&env, &new_config);
        log!(&env, "Oracle contract configuration updated");
        Ok(())
    }
}

fn validate_signature(
    env: &Env,
    oracle_address: &Address,
    oracles: &Vec<Oracle>,
    data_hash: BytesN<32>,
    signature: BytesN<64>,
) -> Result<(), InsuranceError> {
    let public_key = get_oracle_public_key(oracle_address, oracles)
        .ok_or(InsuranceError::OracleNotAuthorized)?;

    let message = Bytes::from_array(env, &data_hash.to_array());
    env.crypto().ed25519_verify(&public_key, &message, &signature);

    Ok(())
}
