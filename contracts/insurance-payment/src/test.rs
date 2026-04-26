#![cfg(test)]

use crate::{InsurancePaymentContract, InsurancePaymentContractClient};
use insurance_policy::{InsurancePolicyContract, InsurancePolicyContractClient};
use insurance_shared::{
    ComparisonOperator, Location, Oracle, ParametricTrigger, PolicyStatus, TriggerType,
};
use soroban_sdk::{
    testutils::{Address as _, Ledger},
    token::{Client as TokenClient, StellarAssetClient},
    Address, Env, String, Vec,
};

fn setup() -> (
    Env,
    Address,
    Address,
    Address,
    InsurancePolicyContractClient<'static>,
    InsurancePaymentContractClient<'static>,
) {
    let env = Env::default();
    env.ledger().with_mut(|li| {
        li.timestamp = 1_700_000_000;
    });

    let admin = Address::generate(&env);
    let user = Address::generate(&env);
    let fee_recipient = Address::generate(&env);

    let policy_id = env.register_contract(None, InsurancePolicyContract);
    let payment_id = env.register_contract(None, InsurancePaymentContract);

    let policy_client = InsurancePolicyContractClient::new(&env, &policy_id);
    let payment_client = InsurancePaymentContractClient::new(&env, &payment_id);

    let oracles = Vec::<Oracle>::new(&env);

    policy_client.initialize(
        &admin,
        &oracles,
        &70u32,
        &80u32,
        &100u32,
        &fee_recipient,
        &payment_id,
    );

    let token = env.register_stellar_asset_contract_v2(admin.clone());
    let supported_tokens = Vec::from_array(&env, [token.address()]);

    payment_client.initialize(
        &admin,
        &oracles,
        &70u32,
        &80u32,
        &100u32,
        &fee_recipient,
        &policy_id,
        &Address::generate(&env),
        &supported_tokens,
    );

    let token_admin = StellarAssetClient::new(&env, &token.address());
    token_admin.mock_all_auths().mint(&user, &1_000_000i128);

    (env, admin, user, token.address(), policy_client, payment_client)
}

fn create_location(env: &Env) -> Location {
    Location {
        latitude: 40_000_000,
        longitude: -74_000_000,
        region: String::from_str(env, "Test Farm"),
    }
}

fn create_triggers(env: &Env) -> Vec<ParametricTrigger> {
    Vec::from_array(
        env,
        [ParametricTrigger {
            trigger_type: TriggerType::Rainfall,
            threshold_value: 50_000,
            comparison: ComparisonOperator::LessThan,
            payout_percentage: 50,
        }],
    )
}

#[test]
fn premium_payment_activates_policy_and_records_on_chain_payment() {
    let (env, _admin, user, asset, policy_client, payment_client) = setup();
    let current_time = env.ledger().timestamp();

    let policy_id = policy_client.mock_all_auths().create_policy(
        &user,
        &create_location(&env),
        &asset,
        &100_000i128,
        &500_000i128,
        &(current_time + 86_400),
        &(current_time + 31_536_000),
        &create_triggers(&env),
    );

    let payment_id = payment_client
        .mock_all_auths_allowing_non_root_auth()
        .process_premium(&policy_id, &user, &100_000i128, &asset);

    let policy = policy_client.get_policy(&policy_id);
    assert_eq!(policy.status, PolicyStatus::Active);

    let payment = payment_client.get_payment(&payment_id);
    assert_eq!(payment.policy_id, Some(policy_id.clone()));
    assert_eq!(payment.amount, 100_000i128);

    let pool_balance = payment_client.get_pool_balance();
    assert_eq!(pool_balance, 99_000i128);

    let by_policy = payment_client.get_payments_by_policy(&policy_id);
    assert_eq!(by_policy.len(), 1);

    let token_client = TokenClient::new(&env, &asset);
    assert_eq!(token_client.balance(&user), 900_000i128);
}
