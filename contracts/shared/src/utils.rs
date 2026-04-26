use soroban_sdk::{xdr::ToXdr, Address, Bytes, BytesN, Env, String, Vec};
use crate::types::*;

const HEX_CHARS: &[u8; 16] = b"0123456789abcdef";
const ID_HASH_BYTES: usize = 12;

fn write_ascii(buffer: &mut [u8], offset: &mut usize, value: &[u8]) {
    buffer[*offset..*offset + value.len()].copy_from_slice(value);
    *offset += value.len();
}

fn write_hex(buffer: &mut [u8], offset: &mut usize, value: &[u8]) {
    for byte in value {
        buffer[*offset] = HEX_CHARS[(byte >> 4) as usize];
        *offset += 1;
        buffer[*offset] = HEX_CHARS[(byte & 0x0f) as usize];
        *offset += 1;
    }
}

fn hashed_identifier(env: &Env, prefix: &str, hash: &BytesN<32>) -> String {
    let hash_bytes = hash.to_array();
    let mut buffer = [0u8; 80];
    let mut length = 0usize;

    write_ascii(&mut buffer, &mut length, prefix.as_bytes());
    write_ascii(&mut buffer, &mut length, b"-");
    write_hex(&mut buffer, &mut length, &hash_bytes[..ID_HASH_BYTES]);

    String::from_bytes(env, &buffer[..length])
}

/// Utility functions for insurance contracts

/// Generate a deterministic ID from a stable payload.
pub fn generate_id(env: &Env, prefix: &str, payload: &Bytes) -> String {
    let hash = env.crypto().sha256(payload);
    let hash_bytes = hash.to_bytes();

    hashed_identifier(env, prefix, &hash_bytes)
}

/// Validate that a date is in the future
pub fn validate_future_date(env: &Env, date: u64) -> Result<(), InsuranceError> {
    let current_time = env.ledger().timestamp();
    if date <= current_time {
        return Err(InsuranceError::InvalidDate);
    }
    Ok(())
}

/// Validate that start date is before end date
pub fn validate_date_range(start_date: u64, end_date: u64) -> Result<(), InsuranceError> {
    if start_date >= end_date {
        return Err(InsuranceError::InvalidDate);
    }
    Ok(())
}

/// Validate amount is positive
pub fn validate_positive_amount(amount: i128) -> Result<(), InsuranceError> {
    if amount <= 0 {
        return Err(InsuranceError::InvalidAmount);
    }
    Ok(())
}

/// Check if a policy is currently active
pub fn is_policy_active(env: &Env, policy: &Policy) -> bool {
    let current_time = env.ledger().timestamp();
    policy.status == PolicyStatus::Active 
        && current_time >= policy.start_date 
        && current_time <= policy.end_date
}

/// Check if a policy has expired
pub fn is_policy_expired(env: &Env, policy: &Policy) -> bool {
    let current_time = env.ledger().timestamp();
    current_time > policy.end_date
}

/// Calculate payout amount based on parametric triggers
pub fn calculate_parametric_payout(
    policy: &Policy,
    parametric_data: &ParametricData,
) -> i128 {
    let mut max_payout_percentage = 0u32;
    
    for trigger in &policy.parametric_triggers {
        for measurement in &parametric_data.measurements {
            if measurement.measurement_type == trigger.trigger_type {
                let trigger_met = match trigger.comparison {
                    ComparisonOperator::LessThan => measurement.value < trigger.threshold_value,
                    ComparisonOperator::LessThanOrEqual => measurement.value <= trigger.threshold_value,
                    ComparisonOperator::GreaterThan => measurement.value > trigger.threshold_value,
                    ComparisonOperator::GreaterThanOrEqual => measurement.value >= trigger.threshold_value,
                    ComparisonOperator::Equal => measurement.value == trigger.threshold_value,
                };
                
                if trigger_met && trigger.payout_percentage > max_payout_percentage {
                    max_payout_percentage = trigger.payout_percentage;
                }
            }
        }
    }
    
    if max_payout_percentage > 0 {
        (policy.coverage_amount * max_payout_percentage as i128) / 100
    } else {
        0
    }
}

/// Validate oracle data freshness
pub fn validate_data_freshness(
    env: &Env,
    data_timestamp: u64,
    max_age_seconds: u64,
) -> Result<(), InsuranceError> {
    let current_time = env.ledger().timestamp();
    if data_timestamp > current_time || current_time.saturating_sub(data_timestamp) > max_age_seconds {
        return Err(InsuranceError::DataTooOld);
    }
    Ok(())
}

/// Validate confidence score meets minimum threshold
pub fn validate_confidence_score(
    confidence_score: u32,
    minimum_score: u32,
) -> Result<(), InsuranceError> {
    if confidence_score < minimum_score {
        return Err(InsuranceError::ConfidenceScoreTooLow);
    }
    Ok(())
}

/// Check if an address is authorized as an oracle
pub fn is_authorized_oracle(oracle_address: &Address, authorized_oracles: &Vec<Oracle>) -> bool {
    authorized_oracles.iter().any(|o| o.address == *oracle_address)
}

/// Get an oracle's public key
pub fn get_oracle_public_key(oracle_address: &Address, authorized_oracles: &Vec<Oracle>) -> Option<BytesN<32>> {
    authorized_oracles.iter()
        .find(|o| o.address == *oracle_address)
        .map(|o| o.public_key.clone())
}


/// Calculate fee amount based on percentage
pub fn calculate_fee(amount: i128, fee_percentage: u32) -> i128 {
    (amount * fee_percentage as i128) / 10000 // Fee percentage in basis points
}

/// Validate location coordinates
pub fn validate_location(location: &Location) -> Result<(), InsuranceError> {
    // Latitude should be between -90 and 90 degrees (multiplied by 1e6)
    if location.latitude < -90_000_000 || location.latitude > 90_000_000 {
        return Err(InsuranceError::InvalidAmount);
    }
    
    // Longitude should be between -180 and 180 degrees (multiplied by 1e6)
    if location.longitude < -180_000_000 || location.longitude > 180_000_000 {
        return Err(InsuranceError::InvalidAmount);
    }
    
    Ok(())
}

/// Check if two locations are within a certain distance (simplified)
pub fn locations_within_distance(
    loc1: &Location,
    loc2: &Location,
    max_distance_meters: i64,
) -> bool {
    if max_distance_meters <= 0 {
        return false;
    }

    let lat_diff = (i128::from(loc1.latitude) - i128::from(loc2.latitude)).abs();
    let lon_diff = (i128::from(loc1.longitude) - i128::from(loc2.longitude)).abs();
    let distance_squared = lat_diff * lat_diff + lon_diff * lon_diff;

    // Coordinates are stored in microdegrees and 1 degree ≈ 111,000 meters.
    // Compare squared values to avoid floating point usage in no_std contracts.
    let lhs = distance_squared * 111_i128 * 111_i128;
    let rhs = i128::from(max_distance_meters)
        * i128::from(max_distance_meters)
        * 1_000_i128
        * 1_000_i128;

    lhs <= rhs
}

/// Format timestamp for logging
pub fn format_timestamp(env: &Env, timestamp: u64) -> String {
    let hash = env.crypto().sha256(&timestamp.to_xdr(env));
    let hash_bytes = hash.to_bytes();
    hashed_identifier(env, "timestamp", &hash_bytes)
}

/// Validate parametric trigger configuration
pub fn validate_parametric_trigger(trigger: &ParametricTrigger) -> Result<(), InsuranceError> {
    if trigger.payout_percentage > 100 {
        return Err(InsuranceError::InvalidAmount);
    }
    Ok(())
}

/// Check if claim is within policy coverage period
pub fn is_claim_within_coverage(
    policy: &Policy,
    incident_date: u64,
) -> bool {
    incident_date >= policy.start_date && incident_date <= policy.end_date
}
