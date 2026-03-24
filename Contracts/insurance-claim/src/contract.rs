use soroban_sdk::{
    contract, contractimpl, contractmeta, log, Address, Env, String, Symbol, TryIntoVal, Vec,
};
use insurance_shared::{
    Claim, ClaimStatus, Policy, PolicyStatus, ContractConfig, InsuranceError, 
    InsuranceEvent, OptionalParametricData, Oracle, ParametricData, generate_id, validate_positive_amount,
    calculate_parametric_payout, validate_data_freshness, validate_confidence_score,
    is_authorized_oracle, is_claim_within_coverage
};
use crate::storage;

// Contract metadata
contractmeta!(
    key = "Description",
    val = "Insurance Claim Processing Contract"
);

/// Insurance Claim Contract
/// 
/// This contract manages insurance claims including:
/// - Claim submission and validation
/// - Parametric claim evaluation
/// - Automated payout processing
/// - Claim status management
#[contract]
pub struct InsuranceClaimContract;

#[contractimpl]
impl InsuranceClaimContract {
    /// Initialize the contract with configuration
    pub fn initialize(
        env: Env,
        admin: Address,
        oracles: Vec<Oracle>,
        minimum_confidence_score: u32,
        auto_payout_threshold: u32,
        fee_percentage: u32,
        fee_recipient: Address,
        policy_contract: Address,
        payment_contract: Address,
    ) -> Result<(), InsuranceError> {
        let config = ContractConfig {
            admin,
            oracles,
            minimum_confidence_score,
            auto_payout_threshold,
            fee_percentage,
            fee_recipient,
        };

        storage::initialize_contract(&env, &config, &policy_contract, &payment_contract);
        
        log!(&env, "Insurance Claim Contract initialized");
        Ok(())
    }

    /// Submit a new insurance claim
    pub fn submit_claim(
        env: Env,
        claimant: Address,
        policy_id: String,
        incident_type: String,
        incident_date: u64,
        amount_claimed: i128,
        evidence_hash: Option<String>,
    ) -> Result<String, InsuranceError> {
        // Validate caller authorization
        claimant.require_auth();

        // Validate inputs
        validate_positive_amount(amount_claimed)?;

        // Get policy from policy contract
        let policy_contract = storage::get_policy_contract(&env);
        let policy: Policy = env.invoke_contract(
            &policy_contract,
            &Symbol::new(&env, "get_policy"),
            (policy_id.clone(),).try_into_val(&env).unwrap(),
        );

        // Verify claimant is the policyholder
        if claimant != policy.policyholder {
            return Err(InsuranceError::Unauthorized);
        }

        // Check if policy is active
        if policy.status != PolicyStatus::Active {
            return Err(InsuranceError::InvalidStatus);
        }

        // Check if incident is within coverage period
        if !is_claim_within_coverage(&policy, incident_date) {
            return Err(InsuranceError::InvalidDate);
        }

        // Check if claimed amount doesn't exceed coverage
        if amount_claimed > policy.coverage_amount {
            return Err(InsuranceError::InvalidAmount);
        }

        // Generate unique claim ID
        let claim_id = generate_id(&env, "CLM", &claimant);

        // Create claim
        let current_time = env.ledger().timestamp();
        let claim = Claim {
            id: claim_id.clone(),
            policy_id: policy_id.clone(),
            claimant: claimant.clone(),
            incident_type,
            incident_date,
            amount_claimed,
            amount_approved: 0,
            status: ClaimStatus::Submitted,
            evidence_hash,
            parametric_data: OptionalParametricData::None,
            created_at: current_time,
            processed_at: None,
        };

        // Store claim
        storage::set_claim(&env, &claim);
        storage::add_claim_to_claimant(&env, &claimant, &claim_id);
        storage::add_claim_to_policy(&env, &policy_id, &claim_id);
        storage::add_to_pending_claims(&env, &claim_id);

        // Emit event
        env.events().publish((
            String::from_str(&env, "claim_submitted"),
            &claimant,
        ), InsuranceEvent::ClaimSubmitted(claim_id.clone(), policy_id, claimant.clone(), amount_claimed));

        log!(&env, "Claim submitted: {}", claim_id);
        Ok(claim_id)
    }

    /// Process parametric claim with oracle data
    pub fn process_parametric_claim(
        env: Env,
        claim_id: String,
        parametric_data: ParametricData,
        oracle: Address,
    ) -> Result<(), InsuranceError> {
        // Verify oracle authorization
        let config = storage::get_config(&env);
        if !is_authorized_oracle(&oracle, &config.oracles) {
            return Err(InsuranceError::OracleNotAuthorized);
        }
        oracle.require_auth();

        // Get claim
        let mut claim = storage::get_claim(&env, &claim_id)
            .ok_or(InsuranceError::ClaimNotFound)?;

        // Check claim status
        if claim.status != ClaimStatus::Submitted && claim.status != ClaimStatus::UnderReview {
            return Err(InsuranceError::InvalidStatus);
        }

        // Validate data freshness (max 24 hours old)
        validate_data_freshness(&env, parametric_data.timestamp, 86400)?;

        // Validate confidence score
        validate_confidence_score(
            parametric_data.confidence_score,
            config.minimum_confidence_score,
        )?;

        // Get policy
        let policy_contract = storage::get_policy_contract(&env);
        let policy: Policy = env.invoke_contract(
            &policy_contract,
            &Symbol::new(&env, "get_policy"),
            (claim.policy_id.clone(),).try_into_val(&env).unwrap(),
        );

        // Calculate payout based on parametric triggers
        let payout_amount = calculate_parametric_payout(&policy, &parametric_data);

        // Update claim with parametric data
        claim.parametric_data = OptionalParametricData::Some(parametric_data.clone());
        claim.status = ClaimStatus::UnderReview;

        if payout_amount > 0 {
            // Check if confidence score meets auto-payout threshold
            if parametric_data.confidence_score >= config.auto_payout_threshold {
                // Automatically approve and process payout
                claim.amount_approved = payout_amount;
                claim.status = ClaimStatus::ProcessingPayment; // CHECKS-EFFECTS-INTERACTIONS
                claim.processed_at = Some(env.ledger().timestamp());

                // Store updated claim before interaction
                storage::set_claim(&env, &claim);

                // Move to approved claims
                storage::remove_from_pending_claims(&env, &claim_id);
                storage::add_to_approved_claims(&env, &claim_id);

                // Emit parametric trigger event
                for measurement in &parametric_data.measurements {
                    for trigger in &policy.parametric_triggers {
                        if measurement.measurement_type == trigger.trigger_type {
                            env.events().publish((
                                String::from_str(&env, "parametric_trigger_activated"),
                                &claim.claimant,
                            ), InsuranceEvent::ParametricTriggerActivated(
                                claim.policy_id.clone(),
                                trigger.trigger_type.clone(),
                                measurement.value,
                                trigger.threshold_value,
                            ));
                        }
                    }
                }

                // Emit claim approved event
                env.events().publish((
                    String::from_str(&env, "claim_approved"),
                    &claim.claimant,
                ), InsuranceEvent::ClaimApproved(claim_id.clone(), payout_amount));

                // Process payment through payment contract (INTERACTION)
                let payment_contract = storage::get_payment_contract(&env);
                let _payment_result: String = env.invoke_contract(
                    &payment_contract,
                    &Symbol::new(&env, "process_claim_payout"),
                    (claim_id.clone(), claim.claimant.clone(), payout_amount, policy.asset.clone())
                        .try_into_val(&env)
                        .unwrap(),
                );

                log!(&env, "Parametric claim auto-approved: {} for amount: {}", claim_id, payout_amount);
            } else {
                log!(&env, "Parametric claim requires manual review: {}", claim_id);
            }
        } else {
            // No payout triggered, reject claim
            claim.status = ClaimStatus::Rejected;
            claim.processed_at = Some(env.ledger().timestamp());

            storage::remove_from_pending_claims(&env, &claim_id);
            storage::add_to_rejected_claims(&env, &claim_id);

            env.events().publish((
                String::from_str(&env, "claim_rejected"),
                &claim.claimant,
            ), InsuranceEvent::ClaimRejected(
                claim_id.clone(),
                String::from_str(&env, "Parametric triggers not met"),
            ));

            log!(&env, "Parametric claim rejected: {}", claim_id);
        }

        // Store updated claim
        storage::set_claim(&env, &claim);

        Ok(())
    }

    /// Manually approve a claim (admin only)
    pub fn approve_claim(
        env: Env,
        claim_id: String,
        amount_approved: i128,
        caller: Address,
    ) -> Result<(), InsuranceError> {
        // Only admin can manually approve claims
        let config = storage::get_config(&env);
        if caller != config.admin {
            return Err(InsuranceError::Unauthorized);
        }
        caller.require_auth();

        // Get claim
        let mut claim = storage::get_claim(&env, &claim_id)
            .ok_or(InsuranceError::ClaimNotFound)?;

        // Check claim status
        if claim.status != ClaimStatus::Submitted && claim.status != ClaimStatus::UnderReview {
            return Err(InsuranceError::InvalidStatus);
        }

        // Validate amount
        validate_positive_amount(amount_approved)?;
        
        // Get policy to check coverage and get asset
        let policy_contract = storage::get_policy_contract(&env);
        let policy: Policy = env.invoke_contract(
            &policy_contract,
            &Symbol::new(&env, "get_policy"),
            (claim.policy_id.clone(),).try_into_val(&env).unwrap(),
        );
        
        if amount_approved > policy.coverage_amount {
            return Err(InsuranceError::InvalidAmount);
        }

        // Approve claim
        claim.amount_approved = amount_approved;
        claim.status = ClaimStatus::ProcessingPayment; // CHECKS-EFFECTS-INTERACTIONS
        claim.processed_at = Some(env.ledger().timestamp());

        // Update storage before interaction
        storage::set_claim(&env, &claim);
        storage::remove_from_pending_claims(&env, &claim_id);
        storage::add_to_approved_claims(&env, &claim_id);

        // Emit event
        env.events().publish((
            String::from_str(&env, "claim_approved"),
            &claim.claimant,
        ), InsuranceEvent::ClaimApproved(claim_id.clone(), amount_approved));

        // Process payment through payment contract (INTERACTION)
        let payment_contract = storage::get_payment_contract(&env);
        let _payment_result: String = env.invoke_contract(
            &payment_contract,
            &Symbol::new(&env, "process_claim_payout"),
            (claim_id.clone(), claim.claimant.clone(), amount_approved, policy.asset.clone())
                .try_into_val(&env)
                .unwrap(),
        );

        log!(&env, "Claim manually approved: {} for amount: {}", claim_id, amount_approved);
        Ok(())
    }

    /// Reject a claim (admin only)
    pub fn reject_claim(
        env: Env,
        claim_id: String,
        reason: String,
        caller: Address,
    ) -> Result<(), InsuranceError> {
        // Only admin can reject claims
        let config = storage::get_config(&env);
        if caller != config.admin {
            return Err(InsuranceError::Unauthorized);
        }
        caller.require_auth();

        // Get claim
        let mut claim = storage::get_claim(&env, &claim_id)
            .ok_or(InsuranceError::ClaimNotFound)?;

        // Check claim status
        if claim.status != ClaimStatus::Submitted && claim.status != ClaimStatus::UnderReview {
            return Err(InsuranceError::InvalidStatus);
        }

        // Reject claim
        claim.status = ClaimStatus::Rejected;
        claim.processed_at = Some(env.ledger().timestamp());

        // Update storage
        storage::set_claim(&env, &claim);
        storage::remove_from_pending_claims(&env, &claim_id);
        storage::add_to_rejected_claims(&env, &claim_id);

        // Emit event
        env.events().publish((
            String::from_str(&env, "claim_rejected"),
            &claim.claimant,
        ), InsuranceEvent::ClaimRejected(claim_id.clone(), reason.clone()));

        log!(&env, "Claim rejected: {} - {}", claim_id, reason);
        Ok(())
    }

    /// Mark claim as paid (called by payment contract)
    pub fn mark_claim_paid(
        env: Env,
        claim_id: String,
        caller: Address,
    ) -> Result<(), InsuranceError> {
        // Only payment contract can mark claims as paid
        let payment_contract = storage::get_payment_contract(&env);
        if caller != payment_contract {
            return Err(InsuranceError::Unauthorized);
        }
        caller.require_auth();

        // Get claim
        let mut claim = storage::get_claim(&env, &claim_id)
            .ok_or(InsuranceError::ClaimNotFound)?;

        // Check claim status
        if claim.status != ClaimStatus::ProcessingPayment {
            return Err(InsuranceError::InvalidStatus);
        }

        // Mark as paid
        claim.status = ClaimStatus::Paid;

        // Update storage
        storage::set_claim(&env, &claim);
        storage::remove_from_approved_claims(&env, &claim_id);
        storage::add_to_paid_claims(&env, &claim_id);

        log!(&env, "Claim marked as paid: {}", claim_id);
        Ok(())
    }

    /// Get claim details
    pub fn get_claim(env: Env, claim_id: String) -> Result<Claim, InsuranceError> {
        storage::get_claim(&env, &claim_id)
            .ok_or(InsuranceError::ClaimNotFound)
    }

    /// Get claim status
    pub fn get_claim_status(env: Env, claim_id: String) -> Result<ClaimStatus, InsuranceError> {
        let claim = storage::get_claim(&env, &claim_id)
            .ok_or(InsuranceError::ClaimNotFound)?;
        Ok(claim.status)
    }

    /// Get claims by claimant
    pub fn get_claims_by_claimant(env: Env, claimant: Address) -> Vec<String> {
        storage::get_claims_by_claimant(&env, &claimant)
    }

    /// Get claims by policy
    pub fn get_claims_by_policy(env: Env, policy_id: String) -> Vec<String> {
        storage::get_claims_by_policy(&env, &policy_id)
    }

    /// Get pending claims
    pub fn get_pending_claims(env: Env) -> Vec<String> {
        storage::get_pending_claims(&env)
    }

    /// Get approved claims
    pub fn get_approved_claims(env: Env) -> Vec<String> {
        storage::get_approved_claims(&env)
    }

    /// Get rejected claims
    pub fn get_rejected_claims(env: Env) -> Vec<String> {
        storage::get_rejected_claims(&env)
    }

    /// Get paid claims
    pub fn get_paid_claims(env: Env) -> Vec<String> {
        storage::get_paid_claims(&env)
    }

    /// Get total claim count
    pub fn get_claim_count(env: Env) -> u64 {
        storage::get_claim_count(&env)
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
        log!(&env, "Claim contract configuration updated");
        Ok(())
    }

    /// Get contract configuration
    pub fn get_config(env: Env) -> ContractConfig {
        storage::get_config(&env)
    }
}
