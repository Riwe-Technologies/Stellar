#![cfg(test)]

use crate::{InsuranceClaimContract, InsuranceClaimContractClient};
use insurance_payment::{InsurancePaymentContract, InsurancePaymentContractClient};
use insurance_policy::{InsurancePolicyContract, InsurancePolicyContractClient};
use insurance_shared::{
    ComparisonOperator, Location, Measurement, Oracle, ParametricData, ParametricTrigger, PolicyStatus,
    TriggerType,
};
use soroban_sdk::{
    testutils::{Address as _, Ledger},
    token::{Client as TokenClient, StellarAssetClient},
    Address, BytesN, Env, String, Vec,
};

fn oracle(env: &Env, address: &Address) -> Oracle {
    Oracle {
        address: address.clone(),
        public_key: BytesN::from_array(env, &[7; 32]),
    }
}

fn location(env: &Env) -> Location {
    Location {
        latitude: 40_000_000,
        longitude: -74_000_000,
        region: String::from_str(env, "Test Farm"),
    }
}

fn triggers(env: &Env) -> Vec<ParametricTrigger> {
    Vec::from_array(
        env,
        [
            ParametricTrigger {
                trigger_type: TriggerType::Rainfall,
                threshold_value: 50_000,
                comparison: ComparisonOperator::LessThan,
                payout_percentage: 50,
            },
            ParametricTrigger {
                trigger_type: TriggerType::Temperature,
                threshold_value: 35_000,
                comparison: ComparisonOperator::GreaterThan,
                payout_percentage: 30,
            },
        ],
    )
}

#[test]
fn parametric_claim_payout_marks_claim_paid_and_records_payment() {
    let env = Env::default();
    env.ledger().with_mut(|li| {
        li.timestamp = 1_700_000_000;
    });

    let admin = Address::generate(&env);
    let user = Address::generate(&env);
    let fee_recipient = Address::generate(&env);
    let oracle_address = Address::generate(&env);

    let policy_contract_id = env.register_contract(None, InsurancePolicyContract);
    let payment_contract_id = env.register_contract(None, InsurancePaymentContract);
    let claim_contract_id = env.register_contract(None, InsuranceClaimContract);

    let policy_client = InsurancePolicyContractClient::new(&env, &policy_contract_id);
    let payment_client = InsurancePaymentContractClient::new(&env, &payment_contract_id);
    let claim_client = InsuranceClaimContractClient::new(&env, &claim_contract_id);

    let token = env.register_stellar_asset_contract_v2(admin.clone());
    let asset = token.address();
    let token_admin = StellarAssetClient::new(&env, &asset);
    token_admin.mock_all_auths().mint(&user, &1_000_000i128);

    let oracles = Vec::from_array(&env, [oracle(&env, &oracle_address)]);
    let supported_tokens = Vec::from_array(&env, [asset.clone()]);

    policy_client.initialize(
        &admin,
        &oracles,
        &70u32,
        &80u32,
        &100u32,
        &fee_recipient,
        &payment_contract_id,
    );

    claim_client.initialize(
        &admin,
        &oracles,
        &70u32,
        &80u32,
        &100u32,
        &fee_recipient,
        &policy_contract_id,
        &payment_contract_id,
    );

    payment_client.initialize(
        &admin,
        &oracles,
        &70u32,
        &80u32,
        &100u32,
        &fee_recipient,
        &policy_contract_id,
        &claim_contract_id,
        &supported_tokens,
    );

    let current_time = env.ledger().timestamp();
    let policy_id = policy_client.mock_all_auths().create_policy(
        &user,
        &location(&env),
        &asset,
        &100_000i128,
        &100_000i128,
        &(current_time + 86_400),
        &(current_time + 31_536_000),
        &triggers(&env),
    );

    payment_client
        .mock_all_auths_allowing_non_root_auth()
        .process_premium(&policy_id, &user, &100_000i128, &asset);

    let policy = policy_client.get_policy(&policy_id);
    assert_eq!(policy.status, PolicyStatus::Active);

    let claim_id = claim_client.mock_all_auths().submit_claim(
        &user,
        &policy_id,
        &String::from_str(&env, "drought"),
        &(current_time + 100_000),
        &100_000i128,
        &None,
    );

    let parametric_data = ParametricData {
        location: location(&env),
        measurements: Vec::from_array(
            &env,
            [Measurement {
                measurement_type: TriggerType::Rainfall,
                value: 40_000,
                unit: String::from_str(&env, "mm"),
            }],
        ),
        confidence_score: 90,
        data_source: String::from_str(&env, "weather_oracle"),
        timestamp: current_time + 100_000,
    };

    env.ledger().with_mut(|li| {
        li.timestamp = current_time + 100_000;
    });

    claim_client
        .mock_all_auths_allowing_non_root_auth()
        .process_parametric_claim(&claim_id, &parametric_data, &oracle_address);

    let claim = claim_client.get_claim(&claim_id);
    assert_eq!(claim.status, insurance_shared::ClaimStatus::Paid);
    assert_eq!(claim.amount_approved, 50_000i128);

    let payment_ids = payment_client.get_payments_by_claim(&claim_id);
    assert_eq!(payment_ids.len(), 1);

    let payout = payment_client.get_payment(&payment_ids.get(0).unwrap());
    assert_eq!(payout.amount, 50_000i128);

    let token_client = TokenClient::new(&env, &asset);
    assert_eq!(token_client.balance(&user), 950_000i128);
    assert_eq!(payment_client.get_pool_balance(), 49_000i128);
}
