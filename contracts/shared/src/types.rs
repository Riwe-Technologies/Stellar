use soroban_sdk::{contracterror, contracttype, Address, BytesN, String, Vec};

/// Status of an insurance policy
#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub enum PolicyStatus {
    Draft,
    Active,
    Suspended,
    Expired,
    Cancelled,
}

/// Status of an insurance claim
#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub enum ClaimStatus {
    Submitted,
    UnderReview,
    Approved,
    ProcessingPayment,
    Rejected,
    Paid,
}

/// Type of parametric trigger
#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub enum TriggerType {
    Rainfall,
    Temperature,
    Humidity,
    WindSpeed,
    SoilMoisture,
    NDVI, // Normalized Difference Vegetation Index
}

/// Insurance policy data structure
#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct Policy {
    pub id: String,
    pub policyholder: Address,
    pub farm_location: Location,
    pub premium_amount: i128,
    pub coverage_amount: i128,
    pub asset: Address,
    pub start_date: u64,
    pub end_date: u64,
    pub status: PolicyStatus,
    pub parametric_triggers: Vec<ParametricTrigger>,
    pub created_at: u64,
    pub updated_at: u64,
}

/// Insurance claim data structure
#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct Claim {
    pub id: String,
    pub policy_id: String,
    pub claimant: Address,
    pub incident_type: String,
    pub incident_date: u64,
    pub amount_claimed: i128,
    pub amount_approved: i128,
    pub status: ClaimStatus,
    pub evidence_hash: Option<String>,
    pub parametric_data: OptionalParametricData,
    pub created_at: u64,
    pub processed_at: Option<u64>,
}

/// Soroban-safe optional wrapper for nested parametric data
#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub enum OptionalParametricData {
    None,
    Some(ParametricData),
}

/// Geographic location
#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct Location {
    pub latitude: i64,  // Multiplied by 1e6 for precision
    pub longitude: i64, // Multiplied by 1e6 for precision
    pub region: String,
}

/// Parametric trigger configuration
#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct ParametricTrigger {
    pub trigger_type: TriggerType,
    pub threshold_value: i64,
    pub comparison: ComparisonOperator,
    pub payout_percentage: u32, // Percentage (0-100)
}

/// Comparison operators for parametric triggers
#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub enum ComparisonOperator {
    LessThan,
    LessThanOrEqual,
    GreaterThan,
    GreaterThanOrEqual,
    Equal,
}

/// Environmental data for parametric evaluation
#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct ParametricData {
    pub location: Location,
    pub measurements: Vec<Measurement>,
    pub confidence_score: u32, // Percentage (0-100)
    pub data_source: String,
    pub timestamp: u64,
}

/// Individual environmental measurement
#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct Measurement {
    pub measurement_type: TriggerType,
    pub value: i64,
    pub unit: String,
}

/// Payment record
#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct Payment {
    pub id: String,
    pub policy_id: Option<String>,
    pub claim_id: Option<String>,
    pub payer: Address,
    pub recipient: Address,
    pub amount: i128,
    pub asset: Address, // Token contract address
    pub payment_type: PaymentType,
    pub status: PaymentStatus,
    pub transaction_hash: String,
    pub created_at: u64,
}

/// Type of payment
#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub enum PaymentType {
    Premium,
    Payout,
    Refund,
    Fee,
}

/// Payment status
#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub enum PaymentStatus {
    Pending,
    Completed,
    Failed,
    Cancelled,
}

/// Oracle data submission
#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct OracleSubmission {
    pub oracle: Address,
    pub data: ParametricData,
    pub signature: BytesN<64>,
    pub submitted_at: u64,
}

/// Oracle information including public key
#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct Oracle {
    pub address: Address,
    pub public_key: BytesN<32>,
}

/// Contract configuration
#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct ContractConfig {
    pub admin: Address,
    pub oracles: Vec<Oracle>,
    pub minimum_confidence_score: u32,
    pub auto_payout_threshold: u32,
    pub fee_percentage: u32,
    pub fee_recipient: Address,
}

/// Error types for insurance contracts
#[contracterror]
#[derive(Copy, Clone, Debug, Eq, PartialEq, PartialOrd, Ord)]
#[repr(u32)]
pub enum InsuranceError {
    Unauthorized = 1,
    PolicyNotFound = 2,
    ClaimNotFound = 3,
    PaymentNotFound = 4,
    InvalidStatus = 5,
    InvalidAmount = 6,
    InvalidDate = 7,
    InsufficientFunds = 8,
    TriggerNotMet = 9,
    OracleNotAuthorized = 10,
    InvalidSignature = 11,
    DataTooOld = 12,
    ConfidenceScoreTooLow = 13,
    AlreadyExists = 14,
    ContractNotInitialized = 15,
}

/// Events emitted by insurance contracts
#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub enum InsuranceEvent {
    PolicyCreated(String, Address, i128),
    PolicyActivated(String),
    PolicyExpired(String),
    ClaimSubmitted(String, String, Address, i128),
    ClaimApproved(String, i128),
    ClaimRejected(String, String),
    PaymentProcessed(String, i128, PaymentType),
    ParametricTriggerActivated(String, TriggerType, i64, i64),
    OracleDataSubmitted(Address, Location, u64),
}
