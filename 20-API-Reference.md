# API Reference
## Complete REST API Documentation for Stellar Integration

---

## 📋 Table of Contents

1. [Authentication](#authentication)
2. [Stellar Wallet APIs](#stellar-wallet-apis)
3. [DeFi Wallet APIs](#defi-wallet-apis)
4. [Wallet Plus APIs](#wallet-plus-apis)
5. [Insurance APIs](#insurance-apis)
6. [Smart Contract APIs](#smart-contract-apis)
7. [Oracle APIs](#oracle-apis)

---

## 🔐 Authentication

### API Authentication Methods
All API endpoints require authentication using one of the following methods:

#### Bearer Token Authentication
```http
Authorization: Bearer {access_token}
```

#### API Key Authentication
```http
X-API-Key: {api_key}
X-API-Secret: {api_secret}
```

### Authentication Endpoints

#### POST /api/auth/login
Authenticate user and receive access token.

**Request:**
```json
{
  "email": "user@example.com",
  "password": "password123",
  "device_info": {
    "device_id": "device_123",
    "device_name": "iPhone 13",
    "platform": "iOS"
  }
}
```

**Response:**
```json
{
  "success": true,
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "user": {
    "id": 123,
    "email": "user@example.com",
    "name": "John Doe"
  }
}
```

#### POST /api/auth/refresh
Refresh access token.

**Request:**
```json
{
  "refresh_token": "refresh_token_here"
}
```

**Response:**
```json
{
  "success": true,
  "access_token": "new_access_token",
  "expires_in": 3600
}
```

---

## 🌟 Stellar Wallet APIs

### GET /api/stellar/wallet
Get user's Stellar wallet information.

**Headers:**
```http
Authorization: Bearer {access_token}
```

**Response:**
```json
{
  "success": true,
  "wallet": {
    "id": 456,
    "public_key": "GDQNY3PBOOC637UEUUV7JBQQ5KGWT2IEQYDNYZXJJBVUV6VQZF6NNQZR",
    "balance": {
      "xlm": 1000.5000000,
      "usdc": 500.00,
      "custom_assets": []
    },
    "status": "active",
    "created_at": "2025-01-01T00:00:00Z"
  }
}
```

### POST /api/stellar/wallet/create
Create new Stellar wallet for user.

**Request:**
```json
{
  "pin": "123456",
  "backup_enabled": true
}
```

**Response:**
```json
{
  "success": true,
  "wallet": {
    "id": 456,
    "public_key": "GDQNY3PBOOC637UEUUV7JBQQ5KGWT2IEQYDNYZXJJBVUV6VQZF6NNQZR",
    "encrypted_private_key": "encrypted_key_data",
    "backup_phrase": ["word1", "word2", "..."]
  },
  "message": "Wallet created successfully"
}
```

### POST /api/stellar/transaction/send
Send Stellar payment.

**Request:**
```json
{
  "destination": "GDQNY3PBOOC637UEUUV7JBQQ5KGWT2IEQYDNYZXJJBVUV6VQZF6NNQZR",
  "amount": "100.50",
  "asset_code": "XLM",
  "memo": "Payment for services",
  "pin": "123456"
}
```

**Response:**
```json
{
  "success": true,
  "transaction": {
    "id": 789,
    "hash": "a1b2c3d4e5f6...",
    "amount": "100.50",
    "asset_code": "XLM",
    "destination": "GDQNY3PBOOC637UEUUV7JBQQ5KGWT2IEQYDNYZXJJBVUV6VQZF6NNQZR",
    "status": "confirmed",
    "fee": "0.00001",
    "created_at": "2025-01-01T12:00:00Z"
  }
}
```

### GET /api/stellar/transactions
Get transaction history.

**Query Parameters:**
- `page` (optional): Page number (default: 1)
- `limit` (optional): Items per page (default: 20, max: 100)
- `type` (optional): Transaction type filter
- `status` (optional): Status filter

**Response:**
```json
{
  "success": true,
  "transactions": [
    {
      "id": 789,
      "hash": "a1b2c3d4e5f6...",
      "type": "send",
      "amount": "100.50",
      "asset_code": "XLM",
      "destination": "GDQNY3PBOOC637UEUUV7JBQQ5KGWT2IEQYDNYZXJJBVUV6VQZF6NNQZR",
      "status": "confirmed",
      "created_at": "2025-01-01T12:00:00Z"
    }
  ],
  "pagination": {
    "current_page": 1,
    "total_pages": 5,
    "total_items": 100,
    "per_page": 20
  }
}
```

---

## 💰 DeFi Wallet APIs

### GET /api/defi-wallet
Get DeFi wallet information.

**Response:**
```json
{
  "success": true,
  "wallet": {
    "id": 123,
    "status": "active",
    "is_enabled": true,
    "balances": {
      "xlm": 100.0000000,
      "usd": 50.00000000,
      "ngn": 40000.00
    },
    "addresses": {
      "stellar": "G955E0C35EB6D013D35B4E704E8395FF9B94417D8E443E2AF5BF39F5",
      "bitcoin": "bc1q1a9a7a3149ea8b91c2059ca6ffe2995d80452ca7",
      "ethereum": "0x1a9a7a3149ea8b91c2059ca6ffe2995d80452ca7",
      "polygon": "0x1a9a7a3149ea8b91c2059ca6ffe2995d80452ca7",
      "bsc": "0x1a9a7a3149ea8b91c2059ca6ffe2995d80452ca7",
      "tron": "T1a9a7a3149ea8b91c2059ca6ffe2995d80452ca7"
    },
    "limits": {
      "daily_limit": 1000000,
      "monthly_limit": 10000000,
      "kyc_threshold": 50000
    }
  }
}
```

### POST /api/defi-wallet/create
Create DeFi wallet.

**Request:**
```json
{
  "enable_fiat": true,
  "preferred_currency": "NGN",
  "generate_all_addresses": true
}
```

**Response:**
```json
{
  "success": true,
  "wallet": {
    "id": 123,
    "addresses": {
      "stellar": "G955E0C35EB6D013D35B4E704E8395FF9B94417D8E443E2AF5BF39F5",
      "bitcoin": "bc1q1a9a7a3149ea8b91c2059ca6ffe2995d80452ca7",
      "ethereum": "0x1a9a7a3149ea8b91c2059ca6ffe2995d80452ca7"
    },
    "status": "active"
  }
}
```

### POST /api/defi-wallet/deposit
Initiate fiat deposit (on-ramp).

**Request:**
```json
{
  "amount": 50000,
  "currency": "NGN",
  "crypto_currency": "XLM",
  "payment_method": "bank_transfer"
}
```

**Response:**
```json
{
  "success": true,
  "transaction_id": 456,
  "payment_url": "https://checkout.paystack.com/abc123",
  "amount": 50000,
  "crypto_amount": 62.5,
  "exchange_rate": 800,
  "fees": {
    "provider_fee": 500,
    "platform_fee": 250,
    "total_fees": 750
  },
  "expires_at": "2025-08-07T11:30:00Z"
}
```

### POST /api/defi-wallet/withdraw
Initiate fiat withdrawal (off-ramp).

**Request:**
```json
{
  "amount": 25000,
  "currency": "NGN",
  "bank_code": "044",
  "account_number": "1234567890",
  "crypto_currency": "XLM"
}
```

**Response:**
```json
{
  "success": true,
  "transaction_id": 789,
  "amount": 25000,
  "crypto_amount": 31.25,
  "account_name": "John Doe",
  "processing_time": "1-3 business days",
  "reference": "WTH-1234567890"
}
```

### GET /api/defi-wallet/balance
Get real-time wallet balances.

**Response:**
```json
{
  "success": true,
  "balances": {
    "stellar": {
      "xlm": 100.0000000,
      "usdc": 50.000000
    },
    "bitcoin": {
      "btc": 0.00123456
    },
    "ethereum": {
      "eth": 0.05,
      "usdt": 100.000000
    }
  },
  "total_value": {
    "usd": 1250.50,
    "ngn": 1000400.00
  },
  "last_updated": "2025-08-07T10:30:00Z"
}
```

---

## 🔐 Wallet Plus APIs

### POST /api/wallet-plus/initialize
Initialize Wallet Plus (self-custodial).

**Request:**
```json
{
  "device_info": {
    "device_id": "device_123",
    "device_name": "iPhone 13",
    "platform": "iOS",
    "fingerprint": "device_fingerprint_hash"
  },
  "pin": "123456",
  "recovery_password": "strong_recovery_password",
  "biometric_enabled": true,
  "mfa_enabled": true
}
```

**Response:**
```json
{
  "success": true,
  "wallet_plus": {
    "id": 456,
    "public_key": "GDQNY3PBOOC637UEUUV7JBQQ5KGWT2IEQYDNYZXJJBVUV6VQZF6NNQZR",
    "device_bound": true,
    "biometric_enabled": true,
    "mfa_enabled": true,
    "backup_enabled": true
  },
  "mfa_secret": "JBSWY3DPEHPK3PXP",
  "backup_codes": ["123456", "789012", "345678"],
  "private_key": "SXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX"
}
```

### POST /api/wallet-plus/authenticate
Authenticate with Wallet Plus.

**Request:**
```json
{
  "device_id": "device_123",
  "authentication": {
    "pin": "123456",
    "biometric": {
      "type": "fingerprint",
      "data": "biometric_hash"
    },
    "mfa_code": "123456"
  }
}
```

**Response:**
```json
{
  "success": true,
  "session_token": "session_token_here",
  "expires_at": "2025-08-07T11:30:00Z",
  "auth_methods_used": ["pin", "biometric"],
  "wallet_unlocked": true
}
```

### POST /api/wallet-plus/transaction
Execute transaction with Wallet Plus.

**Request:**
```json
{
  "transaction": {
    "type": "send",
    "destination": "GDQNY3PBOOC637UEUUV7JBQQ5KGWT2IEQYDNYZXJJBVUV6VQZF6NNQZR",
    "amount": "100.50",
    "asset_code": "XLM",
    "memo": "Payment"
  },
  "authentication": {
    "pin": "123456",
    "biometric": {
      "type": "fingerprint",
      "data": "biometric_hash"
    },
    "mfa_code": "123456"
  }
}
```

**Response:**
```json
{
  "success": true,
  "transaction": {
    "id": 789,
    "hash": "a1b2c3d4e5f6...",
    "status": "confirmed",
    "auth_methods_used": ["pin", "biometric", "mfa"]
  }
}
```

---

## 🛡️ Insurance APIs

### GET /api/insurance/policies
Get user's insurance policies.

**Query Parameters:**
- `status` (optional): Filter by policy status
- `type` (optional): Filter by policy type

**Response:**
```json
{
  "success": true,
  "policies": [
    {
      "id": "POL-1234567890",
      "type": "crop",
      "status": "active",
      "coverage_amount": 500000,
      "premium": 25000,
      "start_date": "2025-01-01T00:00:00Z",
      "end_date": "2025-12-31T23:59:59Z",
      "farm_data": {
        "location": "Lagos",
        "crop_type": "rice",
        "farm_size": 5.0
      }
    }
  ]
}
```

### POST /api/insurance/policies
Create new insurance policy.

**Request:**
```json
{
  "policy_type": "crop",
  "coverage_amount": 500000,
  "premium": 25000,
  "duration_days": 365,
  "policy_data": {
    "farm_location": "Lagos",
    "crop_type": "rice",
    "farm_size": 5.0,
    "weather_triggers": [
      {
        "trigger_type": "rainfall",
        "threshold": 100,
        "operator": "less_than",
        "payout_percentage": 50
      }
    ]
  }
}
```

**Response:**
```json
{
  "success": true,
  "policy": {
    "id": "POL-1234567890",
    "status": "active",
    "smart_contract_address": "CDLZFC3SYJYDZT7K67VZ75HPJVIEUVNIXF47ZG2FB2RMQQAHHAGK3HNX",
    "transaction_hash": "a1b2c3d4e5f6..."
  }
}
```

### POST /api/insurance/claims
Submit insurance claim.

**Request:**
```json
{
  "policy_id": "POL-1234567890",
  "claim_amount": 250000,
  "incident_date": "2025-06-15",
  "incident_type": "drought",
  "description": "Crop damage due to prolonged drought",
  "evidence": [
    {
      "type": "photo",
      "url": "https://example.com/evidence1.jpg"
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "claim": {
    "id": "CLM-1234567890",
    "status": "pending",
    "estimated_processing_time": "5-7 business days"
  }
}
```

---

## 📊 Smart Contract APIs

### POST /api/contracts/call
Call smart contract method.

**Request:**
```json
{
  "contract_name": "insurance_core",
  "method": "get_policy",
  "parameters": ["POL-1234567890"],
  "source_account": "GDQNY3PBOOC637UEUUV7JBQQ5KGWT2IEQYDNYZXJJBVUV6VQZF6NNQZR"
}
```

**Response:**
```json
{
  "success": true,
  "result": {
    "policy_id": "POL-1234567890",
    "policyholder": "GDQNY3PBOOC637UEUUV7JBQQ5KGWT2IEQYDNYZXJJBVUV6VQZF6NNQZR",
    "status": "active",
    "coverage_amount": 500000
  },
  "contract_address": "CDLZFC3SYJYDZT7K67VZ75HPJVIEUVNIXF47ZG2FB2RMQQAHHAGK3HNX",
  "transaction_hash": "a1b2c3d4e5f6..."
}
```

### GET /api/contracts/addresses
Get all contract addresses for current network.

**Response:**
```json
{
  "success": true,
  "network": "mainnet",
  "contracts": {
    "insurance_core": "CDLZFC3SYJYDZT7K67VZ75HPJVIEUVNIXF47ZG2FB2RMQQAHHAGK3HNX",
    "crop_insurance": "CBLITZKRIT5GMUJ7IMBD5BG65PVDGKPQHKZPARMKP7THXNWTNZP6ANBE",
    "weather_oracle": "CCJZ5DGAKVWZI5DTQPWLMUQGCQHH46KQJPFZQDYDAPSQIBD6QPJBYXZX",
    "defi_lending": "CAIXKFNXLFOMSYQCLFWQMFIIGQMVCPJBFX6RQIOKRXJF5QJWLXP6ANBE",
    "governance": "CBMWGKREPQVUZENKD2TTVT7W7RDCXFKPJHGBQCQHH46KQJPFZQDYDAPS"
  }
}
```

---

## 🌤️ Oracle APIs

### GET /api/oracle/weather
Get weather data for location.

**Query Parameters:**
- `location` (required): Location name
- `date` (optional): Specific date (YYYY-MM-DD)
- `start_date` (optional): Start date for range
- `end_date` (optional): End date for range

**Response:**
```json
{
  "success": true,
  "weather_data": {
    "location": "Lagos",
    "date": "2025-08-07",
    "temperature_max": 32.5,
    "temperature_min": 24.8,
    "rainfall": 15.2,
    "humidity": 78.5,
    "wind_speed": 12.3,
    "data_source": "weather_oracle_contract"
  }
}
```

### POST /api/oracle/weather/verify-triggers
Verify weather triggers for insurance.

**Request:**
```json
{
  "location": "Lagos",
  "start_date": "2025-06-01",
  "end_date": "2025-06-30",
  "triggers": [
    {
      "trigger_type": "rainfall",
      "threshold": 100,
      "operator": "less_than",
      "payout_percentage": 50
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "triggered_events": [
    {
      "trigger_type": "rainfall",
      "date": "2025-06-15",
      "value": 85.2,
      "threshold": 100,
      "payout_percentage": 50,
      "triggered": true
    }
  ],
  "total_payout_percentage": 50
}
```

---

## 📝 Error Responses

### Standard Error Format
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The given data was invalid.",
    "details": {
      "amount": ["The amount field is required."],
      "currency": ["The selected currency is invalid."]
    }
  },
  "timestamp": "2025-08-07T10:30:00Z"
}
```

### Common Error Codes
- `AUTHENTICATION_FAILED`: Invalid credentials
- `AUTHORIZATION_FAILED`: Insufficient permissions
- `VALIDATION_ERROR`: Request validation failed
- `RESOURCE_NOT_FOUND`: Requested resource not found
- `RATE_LIMIT_EXCEEDED`: Too many requests
- `NETWORK_ERROR`: Stellar network error
- `CONTRACT_ERROR`: Smart contract execution error
- `INSUFFICIENT_BALANCE`: Insufficient wallet balance
- `TRANSACTION_FAILED`: Transaction execution failed

---

This comprehensive API reference provides complete documentation for all Stellar integration endpoints, enabling developers to build robust applications on top of the platform.
