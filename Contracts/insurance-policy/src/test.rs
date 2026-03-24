#![cfg(test)]

use super::*;
use insurance_shared::{
    ComparisonOperator, ContractConfig, Location, ParametricTrigger, PolicyStatus, TriggerType,
};
use soroban_sdk::{
    testutils::{Address as _, Ledger},
    Address, Env, String, Vec,
};

fn create_test_env() -> (Env, Address, Address) {
    let env = Env::default();
    env.ledger().with_mut(|li| {
        li.timestamp = 1_700_000_000;
    });
    let admin = Address::generate(&env);
    let user = Address::generate(&env);
    (env, admin, user)
}

fn create_test_config(env: &Env, admin: &Address) -> ContractConfig {
    ContractConfig {
        admin: admin.clone(),
        oracles: Vec::new(env),
        minimum_confidence_score: 70,
        auto_payout_threshold: 80,
        fee_percentage: 100,
        fee_recipient: admin.clone(),
    }
}

fn create_test_location(env: &Env) -> Location {
    Location {
        latitude: 40_000_000,
        longitude: -74_000_000,
        region: String::from_str(env, "Test Farm"),
    }
}

fn create_test_asset(env: &Env) -> Address {
    Address::generate(env)
}

fn create_test_triggers(env: &Env) -> Vec<ParametricTrigger> {
    let mut triggers = Vec::new(env);

    triggers.push_back(ParametricTrigger {
        trigger_type: TriggerType::Rainfall,
        threshold_value: 50_000,
        comparison: ComparisonOperator::LessThan,
        payout_percentage: 50,
    });

    triggers.push_back(ParametricTrigger {
        trigger_type: TriggerType::Temperature,
        threshold_value: 35_000,
        comparison: ComparisonOperator::GreaterThan,
        payout_percentage: 30,
    });

    triggers
}

#[test]
fn test_initialize_contract() {
    let (env, admin, _user) = create_test_env();
    let contract_id = env.register_contract(None, InsurancePolicyContract);
    let client = InsurancePolicyContractClient::new(&env, &contract_id);
    let config = create_test_config(&env, &admin);

    client.initialize(
        &config.admin,
        &config.oracles,
        &config.minimum_confidence_score,
        &config.auto_payout_threshold,
        &config.fee_percentage,
        &config.fee_recipient,
    );

    let stored_config = client.get_config();
    assert_eq!(stored_config.admin, admin);
    assert_eq!(stored_config.minimum_confidence_score, 70);
}

#[test]
fn test_create_policy() {
    let (env, admin, user) = create_test_env();
    let contract_id = env.register_contract(None, InsurancePolicyContract);
    let client = InsurancePolicyContractClient::new(&env, &contract_id);
    let config = create_test_config(&env, &admin);

    client.initialize(
        &config.admin,
        &config.oracles,
        &config.minimum_confidence_score,
        &config.auto_payout_threshold,
        &config.fee_percentage,
        &config.fee_recipient,
    );

    let location = create_test_location(&env);
    let asset = create_test_asset(&env);
    let triggers = create_test_triggers(&env);
    let current_time = env.ledger().timestamp();

    env.mock_all_auths();

    let policy_id = client.create_policy(
        &user,
        &location,
        &asset,
        &1_000_000_000i128,
        &10_000_000_000i128,
        &(current_time + 86_400),
        &(current_time + 31_536_000),
        &triggers,
    );

    let policy = client.get_policy(&policy_id);
    assert_eq!(policy.policyholder, user);
    assert_eq!(policy.asset, asset);
    assert_eq!(policy.premium_amount, 1_000_000_000i128);
    assert_eq!(policy.coverage_amount, 10_000_000_000i128);
    assert_eq!(policy.status, PolicyStatus::Draft);
    assert_eq!(client.get_policy_count(), 1);

    let user_policies = client.get_policies_by_holder(&user);
    assert_eq!(user_policies.len(), 1);
    assert_eq!(user_policies.get(0).unwrap(), policy_id);
}

#[test]
fn test_activate_policy() {
    let (env, admin, user) = create_test_env();
    let contract_id = env.register_contract(None, InsurancePolicyContract);
    let client = InsurancePolicyContractClient::new(&env, &contract_id);
    let config = create_test_config(&env, &admin);

    client.initialize(
        &config.admin,
        &config.oracles,
        &config.minimum_confidence_score,
        &config.auto_payout_threshold,
        &config.fee_percentage,
        &config.fee_recipient,
    );

    let location = create_test_location(&env);
    let asset = create_test_asset(&env);
    let triggers = create_test_triggers(&env);
    let current_time = env.ledger().timestamp();

    env.mock_all_auths();

    let policy_id = client.create_policy(
        &user,
        &location,
        &asset,
        &1_000_000_000i128,
        &10_000_000_000i128,
        &(current_time + 86_400),
        &(current_time + 31_536_000),
        &triggers,
    );

    client.activate_policy(&policy_id, &user);

    let policy = client.get_policy(&policy_id);
    assert_eq!(policy.status, PolicyStatus::Active);

    let active_policies = client.get_active_policies();
    assert_eq!(active_policies.len(), 1);
    assert_eq!(active_policies.get(0).unwrap(), policy_id);
}

#[test]
fn test_suspend_policy() {
    let (env, admin, user) = create_test_env();
    let contract_id = env.register_contract(None, InsurancePolicyContract);
    let client = InsurancePolicyContractClient::new(&env, &contract_id);
    let config = create_test_config(&env, &admin);

    client.initialize(
        &config.admin,
        &config.oracles,
        &config.minimum_confidence_score,
        &config.auto_payout_threshold,
        &config.fee_percentage,
        &config.fee_recipient,
    );

    let location = create_test_location(&env);
    let asset = create_test_asset(&env);
    let triggers = create_test_triggers(&env);
    let current_time = env.ledger().timestamp();

    env.mock_all_auths();

    let policy_id = client.create_policy(
        &user,
        &location,
        &asset,
        &1_000_000_000i128,
        &10_000_000_000i128,
        &(current_time + 86_400),
        &(current_time + 31_536_000),
        &triggers,
    );

    client.activate_policy(&policy_id, &user);
    client.suspend_policy(&policy_id, &admin);

    let policy = client.get_policy(&policy_id);
    assert_eq!(policy.status, PolicyStatus::Suspended);
    assert_eq!(client.get_active_policies().len(), 0);
}

#[test]
fn test_cancel_policy() {
    let (env, admin, user) = create_test_env();
    let contract_id = env.register_contract(None, InsurancePolicyContract);
    let client = InsurancePolicyContractClient::new(&env, &contract_id);
    let config = create_test_config(&env, &admin);

    client.initialize(
        &config.admin,
        &config.oracles,
        &config.minimum_confidence_score,
        &config.auto_payout_threshold,
        &config.fee_percentage,
        &config.fee_recipient,
    );

    let location = create_test_location(&env);
    let asset = create_test_asset(&env);
    let triggers = create_test_triggers(&env);
    let current_time = env.ledger().timestamp();

    env.mock_all_auths();

    let policy_id = client.create_policy(
        &user,
        &location,
        &asset,
        &1_000_000_000i128,
        &10_000_000_000i128,
        &(current_time + 86_400),
        &(current_time + 31_536_000),
        &triggers,
    );

    client.cancel_policy(&policy_id, &user);

    let policy = client.get_policy(&policy_id);
    assert_eq!(policy.status, PolicyStatus::Cancelled);
}

#[test]
fn test_unauthorized_access() {
    let (env, admin, user) = create_test_env();
    let unauthorized_user = Address::generate(&env);
    let contract_id = env.register_contract(None, InsurancePolicyContract);
    let client = InsurancePolicyContractClient::new(&env, &contract_id);
    let config = create_test_config(&env, &admin);

    client.initialize(
        &config.admin,
        &config.oracles,
        &config.minimum_confidence_score,
        &config.auto_payout_threshold,
        &config.fee_percentage,
        &config.fee_recipient,
    );

    let location = create_test_location(&env);
    let asset = create_test_asset(&env);
    let triggers = create_test_triggers(&env);
    let current_time = env.ledger().timestamp();

    env.mock_all_auths();

    let policy_id = client.create_policy(
        &user,
        &location,
        &asset,
        &1_000_000_000i128,
        &10_000_000_000i128,
        &(current_time + 86_400),
        &(current_time + 31_536_000),
        &triggers,
    );

    assert!(client.try_activate_policy(&policy_id, &unauthorized_user).is_err());
    assert!(client.try_suspend_policy(&policy_id, &unauthorized_user).is_err());
}

#[test]
fn test_invalid_policy_data() {
    let (env, admin, user) = create_test_env();
    let contract_id = env.register_contract(None, InsurancePolicyContract);
    let client = InsurancePolicyContractClient::new(&env, &contract_id);
    let config = create_test_config(&env, &admin);

    client.initialize(
        &config.admin,
        &config.oracles,
        &config.minimum_confidence_score,
        &config.auto_payout_threshold,
        &config.fee_percentage,
        &config.fee_recipient,
    );

    let location = create_test_location(&env);
    let asset = create_test_asset(&env);
    let triggers = create_test_triggers(&env);
    let current_time = env.ledger().timestamp();

    env.mock_all_auths();

    assert!(client
        .try_create_policy(
            &user,
            &location,
            &asset,
            &-1_000_000_000i128,
            &10_000_000_000i128,
            &(current_time + 86_400),
            &(current_time + 31_536_000),
            &triggers,
        )
        .is_err());

    assert!(client
        .try_create_policy(
            &user,
            &location,
            &asset,
            &1_000_000_000i128,
            &10_000_000_000i128,
            &(current_time + 31_536_000),
            &(current_time + 86_400),
            &triggers,
        )
        .is_err());

    assert!(client
        .try_create_policy(
            &user,
            &location,
            &asset,
            &1_000_000_000i128,
            &10_000_000_000i128,
            &(current_time - 86_400),
            &(current_time + 31_536_000),
            &triggers,
        )
        .is_err());
}

#[test]
fn test_policy_expiration() {
    let (env, admin, user) = create_test_env();
    let contract_id = env.register_contract(None, InsurancePolicyContract);
    let client = InsurancePolicyContractClient::new(&env, &contract_id);
    let config = create_test_config(&env, &admin);

    client.initialize(
        &config.admin,
        &config.oracles,
        &config.minimum_confidence_score,
        &config.auto_payout_threshold,
        &config.fee_percentage,
        &config.fee_recipient,
    );

    let location = create_test_location(&env);
    let asset = create_test_asset(&env);
    let triggers = create_test_triggers(&env);
    let current_time = env.ledger().timestamp();

    env.mock_all_auths();

    let policy_id = client.create_policy(
        &user,
        &location,
        &asset,
        &1_000_000_000i128,
        &10_000_000_000i128,
        &(current_time + 86_400),
        &(current_time + 172_800),
        &triggers,
    );

    client.activate_policy(&policy_id, &user);

    env.ledger().with_mut(|li| {
        li.timestamp = current_time + 259_200;
    });

    let expired_count = client.expire_policies(&admin);
    assert_eq!(expired_count, 1);

    let policy = client.get_policy(&policy_id);
    assert_eq!(policy.status, PolicyStatus::Expired);

    let expired_policies = client.get_expired_policies();
    assert_eq!(expired_policies.len(), 1);
    assert_eq!(expired_policies.get(0).unwrap(), policy_id);
}
