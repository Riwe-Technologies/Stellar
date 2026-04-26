use soroban_sdk::{
    contract, contractimpl, contractmeta, log, token::Client as TokenClient, xdr::ToXdr, Address, Env, String, Symbol,
    TryIntoVal, Vec,
};
use insurance_shared::{
    Payment, PaymentType, PaymentStatus, Policy, PolicyStatus, ContractConfig, InsuranceError, 
    InsuranceEvent, Oracle, generate_id, validate_positive_amount, calculate_fee
};
use crate::storage;

// Contract metadata
contractmeta!(
    key = "Description",
    val = "Insurance Payment Processing Contract"
);

/// Insurance Payment Contract
/// 
/// This contract manages payment processing including:
/// - Premium collection
/// - Claim payouts
/// - Fee processing
/// - Asset management
#[contract]
pub struct InsurancePaymentContract;

#[contractimpl]
impl InsurancePaymentContract {
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
        claim_contract: Address,
        supported_tokens: Vec<Address>,
    ) -> Result<(), InsuranceError> {
        let config = ContractConfig {
            admin,
            oracles,
            minimum_confidence_score,
            auto_payout_threshold,
            fee_percentage,
            fee_recipient,
        };

        storage::initialize_contract(
            &env, 
            &config, 
            &policy_contract, 
            &claim_contract, 
            &supported_tokens
        );
        
        log!(&env, "Insurance Payment Contract initialized");
        Ok(())
    }

    /// Process premium payment
    pub fn process_premium(
        env: Env,
        policy_id: String,
        payer: Address,
        amount: i128,
        asset: Address,
    ) -> Result<String, InsuranceError> {
        // Validate caller authorization
        payer.require_auth();

        // Validate inputs
        validate_positive_amount(amount)?;

        // Check if asset is supported
        let supported_tokens = storage::get_supported_tokens(&env);
        if !supported_tokens.contains(&asset) {
            return Err(InsuranceError::InvalidAmount); // A more specific error would be better
        }

        // Load the policy so premium settlement becomes the single activation gate.
        let policy_contract = storage::get_policy_contract(&env);
        let policy: Policy = env.invoke_contract(
            &policy_contract,
            &Symbol::new(&env, "get_policy"),
            (policy_id.clone(),).try_into_val(&env).unwrap(),
        );

        if payer != policy.policyholder {
            return Err(InsuranceError::Unauthorized);
        }

        if policy.status != PolicyStatus::Draft {
            return Err(InsuranceError::InvalidStatus);
        }

        if amount != policy.premium_amount {
            return Err(InsuranceError::InvalidAmount);
        }

        if asset != policy.asset {
            return Err(InsuranceError::InvalidAmount);
        }

        // Transfer premium from payer to contract
        let token_client = TokenClient::new(&env, &asset);
        token_client.transfer(&payer, &env.current_contract_address(), &amount);

        // Generate unique payment ID
        let premium_seed = (
            payer.clone(),
            policy_id.clone(),
            amount,
            asset.clone(),
            String::from_str(&env, "premium"),
        )
            .to_xdr(&env);
        let payment_id = generate_id(&env, "PAY", &premium_seed);

        // Calculate fees
        let config = storage::get_config(&env);
        let fee_amount = calculate_fee(amount, config.fee_percentage);
        let net_amount = amount - fee_amount;

        // Create payment record
        let current_time = env.ledger().timestamp();
        let payment = Payment {
            id: payment_id.clone(),
            policy_id: Some(policy_id.clone()),
            claim_id: None,
            payer: payer.clone(),
            recipient: env.current_contract_address(), // Pool is the recipient
            amount,
            asset: asset.clone(),
            payment_type: PaymentType::Premium,
            status: PaymentStatus::Completed, // Fixed: Status is completed after transfer
            transaction_hash: String::from_str(&env, "completed"), // Fixed
            created_at: current_time,
        };

        // Store payment
        storage::set_payment(&env, &payment);
        storage::add_payment_to_payer(&env, &payer, &payment_id);
        storage::add_payment_to_policy(&env, &policy_id, &payment_id);
        storage::add_to_completed_payments(&env, &payment_id);

        // Add to insurance pool
        storage::add_to_insurance_pool(&env, net_amount);

        // Activate the policy only after the premium has been collected successfully.
        env.invoke_contract::<()>(
            &policy_contract,
            &Symbol::new(&env, "activate_policy"),
            (policy_id.clone(), env.current_contract_address())
                .try_into_val(&env)
                .unwrap(),
        );

        // Emit event
        env.events().publish((
            String::from_str(&env, "premium_processed"),
            &payer,
        ), InsuranceEvent::PaymentProcessed(payment_id.clone(), amount, PaymentType::Premium));

        log!(&env, "Premium payment processed: {} for policy: {}", payment_id, policy_id);
        Ok(payment_id)
    }

    /// Process claim payout
    pub fn process_claim_payout(
        env: Env,
        claim_id: String,
        recipient: Address,
        amount: i128,
        asset: Address,
    ) -> Result<String, InsuranceError> {
        // Only claim contract can call this
        let claim_contract = storage::get_claim_contract(&env);
        claim_contract.require_auth();

        // Validate inputs
        validate_positive_amount(amount)?;

        // Check if asset is supported
        let supported_tokens = storage::get_supported_tokens(&env);
        if !supported_tokens.contains(&asset) {
            return Err(InsuranceError::InvalidAmount); // A more specific error would be better
        }

        // Check if sufficient funds in insurance pool
        storage::subtract_from_insurance_pool(&env, amount)?;

        // Transfer payout from contract to recipient
        let token_client = TokenClient::new(&env, &asset);
        token_client.transfer(&env.current_contract_address(), &recipient, &amount);

        // Generate unique payment ID
        let payout_seed = (
            recipient.clone(),
            claim_id.clone(),
            amount,
            asset.clone(),
            String::from_str(&env, "payout"),
        )
            .to_xdr(&env);
        let payment_id = generate_id(&env, "PAYOUT", &payout_seed);

        // Create payment record
        let current_time = env.ledger().timestamp();
        let payment = Payment {
            id: payment_id.clone(),
            policy_id: None,
            claim_id: Some(claim_id.clone()),
            payer: env.current_contract_address(),
            recipient: recipient.clone(),
            amount,
            asset: asset.clone(),
            payment_type: PaymentType::Payout,
            status: PaymentStatus::Completed,
            transaction_hash: String::from_str(&env, "completed"),
            created_at: current_time,
        };

        // Store payment
        storage::set_payment(&env, &payment);
        storage::add_payment_to_recipient(&env, &recipient, &payment_id);
        storage::add_payment_to_claim(&env, &claim_id, &payment_id);
        storage::add_to_completed_payments(&env, &payment_id);

        // Emit event
        env.events().publish((
            String::from_str(&env, "payout_processed"),
            &recipient,
        ), InsuranceEvent::PaymentProcessed(payment_id.clone(), amount, PaymentType::Payout));

        log!(&env, "Claim payout processed: {} for claim: {}", payment_id, claim_id);
        Ok(payment_id)
    }

    /// Get payment details
    pub fn get_payment(env: Env, payment_id: String) -> Result<Payment, InsuranceError> {
        storage::get_payment(&env, &payment_id)
            .ok_or(InsuranceError::PaymentNotFound)
    }

    /// Get payments by payer
    pub fn get_payments_by_payer(env: Env, payer: Address) -> Vec<String> {
        storage::get_payments_by_payer(&env, &payer)
    }

    /// Get payments by policy
    pub fn get_payments_by_policy(env: Env, policy_id: String) -> Vec<String> {
        storage::get_payments_by_policy(&env, &policy_id)
    }

    /// Get payments by recipient
    pub fn get_payments_by_recipient(env: Env, recipient: Address) -> Vec<String> {
        storage::get_payments_by_recipient(&env, &recipient)
    }

    /// Get payments by claim
    pub fn get_payments_by_claim(env: Env, claim_id: String) -> Vec<String> {
        storage::get_payments_by_claim(&env, &claim_id)
    }

    /// Get insurance pool balance
    pub fn get_pool_balance(env: Env) -> i128 {
        storage::get_insurance_pool_balance(&env)
    }

    /// Get supported tokens
    pub fn get_supported_tokens(env: Env) -> Vec<Address> {
        storage::get_supported_tokens(&env)
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
        log!(&env, "Payment contract configuration updated");
        Ok(())
    }

    /// Get contract configuration
    pub fn get_config(env: Env) -> ContractConfig {
        storage::get_config(&env)
    }
}
