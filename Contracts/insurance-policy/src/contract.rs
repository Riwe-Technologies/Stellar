use soroban_sdk::{
    contract, contractimpl, contractmeta, Address, Env, String, Vec, log
};
use insurance_shared::{
    Policy, PolicyStatus, ContractConfig, InsuranceError, InsuranceEvent,
    Location, Oracle, ParametricTrigger, generate_id, validate_future_date,
    validate_date_range, validate_positive_amount, is_policy_active,
    is_policy_expired, validate_location, validate_parametric_trigger
};
use crate::storage;

// Contract metadata
contractmeta!(
    key = "Description",
    val = "Insurance Policy Management Contract"
);

/// Insurance Policy Contract
/// 
/// This contract manages the lifecycle of insurance policies including:
/// - Policy creation and activation
/// - Premium payment tracking
/// - Policy status management
/// - Parametric trigger configuration
#[contract]
pub struct InsurancePolicyContract;

#[contractimpl]
impl InsurancePolicyContract {
    /// Initialize the contract with configuration
    pub fn initialize(
        env: Env,
        admin: Address,
        oracles: Vec<Oracle>,
        minimum_confidence_score: u32,
        auto_payout_threshold: u32,
        fee_percentage: u32,
        fee_recipient: Address,
    ) -> Result<(), InsuranceError> {
        // Ensure contract is not already initialized
        if env.storage().instance().has(&crate::storage::DataKey::Config) {
            return Err(InsuranceError::AlreadyExists);
        }

        let config = ContractConfig {
            admin,
            oracles,
            minimum_confidence_score,
            auto_payout_threshold,
            fee_percentage,
            fee_recipient,
        };

        storage::initialize_contract(&env, &config);
        
        log!(&env, "Insurance Policy Contract initialized");
        Ok(())
    }

    /// Create a new insurance policy
    pub fn create_policy(
        env: Env,
        policyholder: Address,
        farm_location: Location,
        asset: Address,
        premium_amount: i128,
        coverage_amount: i128,
        start_date: u64,
        end_date: u64,
        parametric_triggers: Vec<ParametricTrigger>,
    ) -> Result<String, InsuranceError> {
        // Validate caller authorization
        policyholder.require_auth();

        // Validate inputs
        validate_positive_amount(premium_amount)?;
        validate_positive_amount(coverage_amount)?;
        validate_future_date(&env, start_date)?;
        validate_date_range(start_date, end_date)?;
        validate_location(&farm_location)?;

        // Validate parametric triggers
        for trigger in parametric_triggers.iter() {
            validate_parametric_trigger(&trigger)?;
        }

        // Generate unique policy ID
        let policy_id = generate_id(&env, "POL", &policyholder);

        // Create policy
        let current_time = env.ledger().timestamp();
        let policy = Policy {
            id: policy_id.clone(),
            policyholder: policyholder.clone(),
            farm_location,
            asset,
            premium_amount,
            coverage_amount,
            start_date,
            end_date,
            status: PolicyStatus::Draft,
            parametric_triggers,
            created_at: current_time,
            updated_at: current_time,
        };

        // Store policy
        storage::set_policy(&env, &policy);
        storage::add_policy_to_holder(&env, &policyholder, &policy_id);
        storage::get_next_policy_count(&env);

        // Emit event
        env.events().publish(
            (
                String::from_str(&env, "policy_created"),
                &policyholder,
            ),
            InsuranceEvent::PolicyCreated(policy_id.clone(), policyholder.clone(), premium_amount),
        );

        log!(&env, "Policy created: {}", policy_id);
        Ok(policy_id)
    }

    /// Activate a policy (called after premium payment)
    pub fn activate_policy(
        env: Env,
        policy_id: String,
        caller: Address,
    ) -> Result<(), InsuranceError> {
        // Get policy
        let mut policy = storage::get_policy(&env, &policy_id)
            .ok_or(InsuranceError::PolicyNotFound)?;

        // Verify authorization (policyholder or admin)
        let config = storage::get_config(&env);
        if caller != policy.policyholder && caller != config.admin {
            return Err(InsuranceError::Unauthorized);
        }
        caller.require_auth();

        // Check policy status
        if policy.status != PolicyStatus::Draft {
            return Err(InsuranceError::InvalidStatus);
        }

        // Activate policy
        policy.status = PolicyStatus::Active;
        policy.updated_at = env.ledger().timestamp();

        // Store updated policy
        storage::set_policy(&env, &policy);
        storage::add_to_active_policies(&env, &policy_id);

        // Emit event
        env.events().publish(
            (
                String::from_str(&env, "policy_activated"),
                &policy.policyholder,
            ),
            InsuranceEvent::PolicyActivated(policy_id.clone()),
        );

        log!(&env, "Policy activated: {}", policy_id);
        Ok(())
    }

    /// Suspend a policy
    pub fn suspend_policy(
        env: Env,
        policy_id: String,
        caller: Address,
    ) -> Result<(), InsuranceError> {
        // Only admin can suspend policies
        let config = storage::get_config(&env);
        if caller != config.admin {
            return Err(InsuranceError::Unauthorized);
        }
        caller.require_auth();

        // Get policy
        let mut policy = storage::get_policy(&env, &policy_id)
            .ok_or(InsuranceError::PolicyNotFound)?;

        // Check policy status
        if policy.status != PolicyStatus::Active {
            return Err(InsuranceError::InvalidStatus);
        }

        // Suspend policy
        policy.status = PolicyStatus::Suspended;
        policy.updated_at = env.ledger().timestamp();

        // Store updated policy
        storage::set_policy(&env, &policy);
        storage::remove_from_active_policies(&env, &policy_id);

        log!(&env, "Policy suspended: {}", policy_id);
        Ok(())
    }

    /// Cancel a policy
    pub fn cancel_policy(
        env: Env,
        policy_id: String,
        caller: Address,
    ) -> Result<(), InsuranceError> {
        // Get policy
        let mut policy = storage::get_policy(&env, &policy_id)
            .ok_or(InsuranceError::PolicyNotFound)?;

        // Verify authorization (policyholder or admin)
        let config = storage::get_config(&env);
        if caller != policy.policyholder && caller != config.admin {
            return Err(InsuranceError::Unauthorized);
        }
        caller.require_auth();

        // Cancel policy
        policy.status = PolicyStatus::Cancelled;
        policy.updated_at = env.ledger().timestamp();

        // Store updated policy
        storage::set_policy(&env, &policy);
        storage::remove_from_active_policies(&env, &policy_id);

        log!(&env, "Policy cancelled: {}", policy_id);
        Ok(())
    }

    /// Expire policies that have passed their end date
    pub fn expire_policies(env: Env, caller: Address) -> Result<u32, InsuranceError> {
        // Only admin can expire policies
        let config = storage::get_config(&env);
        if caller != config.admin {
            return Err(InsuranceError::Unauthorized);
        }
        caller.require_auth();

        let active_policies = storage::get_active_policies(&env);
        let mut expired_count = 0u32;

        for policy_id in active_policies {
            if let Some(mut policy) = storage::get_policy(&env, &policy_id) {
                if is_policy_expired(&env, &policy) {
                    policy.status = PolicyStatus::Expired;
                    policy.updated_at = env.ledger().timestamp();

                    storage::set_policy(&env, &policy);
                    storage::remove_from_active_policies(&env, &policy_id);
                    storage::add_to_expired_policies(&env, &policy_id);

                    // Emit event
                    env.events().publish(
                        (
                            String::from_str(&env, "policy_expired"),
                            &policy.policyholder,
                        ),
                        InsuranceEvent::PolicyExpired(policy_id.clone()),
                    );

                    expired_count += 1;
                }
            }
        }

        log!(&env, "Expired {} policies", expired_count);
        Ok(expired_count)
    }

    /// Get policy details
    pub fn get_policy(env: Env, policy_id: String) -> Result<Policy, InsuranceError> {
        storage::get_policy(&env, &policy_id)
            .ok_or(InsuranceError::PolicyNotFound)
    }

    /// Get policy status
    pub fn get_policy_status(env: Env, policy_id: String) -> Result<PolicyStatus, InsuranceError> {
        let policy = storage::get_policy(&env, &policy_id)
            .ok_or(InsuranceError::PolicyNotFound)?;
        Ok(policy.status)
    }

    /// Check if policy is active
    pub fn is_policy_active(env: Env, policy_id: String) -> Result<bool, InsuranceError> {
        let policy = storage::get_policy(&env, &policy_id)
            .ok_or(InsuranceError::PolicyNotFound)?;
        Ok(is_policy_active(&env, &policy))
    }

    /// Get policies by policyholder
    pub fn get_policies_by_holder(env: Env, holder: Address) -> Vec<String> {
        storage::get_policies_by_holder(&env, &holder)
    }

    /// Get active policies
    pub fn get_active_policies(env: Env) -> Vec<String> {
        storage::get_active_policies(&env)
    }

    /// Get expired policies
    pub fn get_expired_policies(env: Env) -> Vec<String> {
        storage::get_expired_policies(&env)
    }

    /// Get total policy count
    pub fn get_policy_count(env: Env) -> u64 {
        storage::get_policy_count(&env)
    }

    /// Update contract configuration (admin only)
    pub fn update_config(
        env: Env,
        new_config: ContractConfig,
    ) -> Result<(), InsuranceError> {
        let config = storage::get_config(&env);
        config.admin.require_auth();

        storage::set_config(&env, &new_config);
        log!(&env, "Contract configuration updated");
        Ok(())
    }

    /// Get contract configuration
    pub fn get_config(env: Env) -> ContractConfig {
        storage::get_config(&env)
    }
}
