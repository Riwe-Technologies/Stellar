# Wallet Plus System
## Self-Custodial Wallet with Device Binding & Advanced Security

---

## 📋 Table of Contents

1. [System Overview](#system-overview)
2. [Architecture & Security](#architecture--security)
3. [Device Binding Technology](#device-binding-technology)
4. [Authentication Methods](#authentication-methods)
5. [Recovery Mechanisms](#recovery-mechanisms)
6. [Cloud Backup System](#cloud-backup-system)
7. [Implementation Details](#implementation-details)

---

## 🌟 System Overview

### Wallet Plus Purpose
Wallet Plus is an advanced self-custodial wallet system that combines the security of non-custodial wallets with the convenience of traditional banking. It uses device binding, biometric authentication, and encrypted cloud backups to provide maximum security while maintaining user accessibility.

### Key Features
- **Self-Custodial**: Users maintain full control of their private keys
- **Device Binding**: Cryptographic binding to specific devices
- **Biometric Authentication**: Fingerprint and face recognition
- **Multi-Factor Authentication**: TOTP and SMS verification
- **Encrypted Cloud Backup**: Secure recovery mechanisms
- **Hardware Security**: TEE and Secure Enclave integration
- **Zero-Knowledge Architecture**: Privacy-preserving design

### Security Architecture
```
┌─────────────────────────────────────────────────────────────────┐
│                    Wallet Plus Security Stack                   │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐             │
│  │  Biometric  │  │   Device    │  │    MFA      │             │
│  │    Auth     │  │  Binding    │  │   System    │             │
│  │             │  │             │  │             │             │
│  │ • Fingerpr  │  │ • TEE/SE    │  │ • TOTP      │             │
│  │ • Face ID   │  │ • Hardware  │  │ • SMS       │             │
│  │ • Voice     │  │ • Crypto    │  │ • Email     │             │
│  └─────────────┘  └─────────────┘  └─────────────┘             │
│         │                 │                 │                  │
│         └─────────────────┼─────────────────┘                  │
│                           │                                    │
│  ┌─────────────────────────────────────────────────────────────┤
│  │              Key Management System                          │
│  │                                                             │
│  │ • Device-Bound Keys      • Encrypted Storage               │
│  │ • Key Derivation         • Secure Key Rotation             │
│  │ • Hardware Isolation     • Recovery Mechanisms             │
│  └─────────────────────────────────────────────────────────────┘
│                           │                                    │
│  ┌─────────────────────────────────────────────────────────────┤
│  │                Cloud Backup System                         │
│  │                                                             │
│  │ • End-to-End Encryption  • Multi-Provider Support          │
│  │ • Zero-Knowledge Design  • Redundant Storage               │
│  │ • Recovery Verification  • Audit Logging                   │
│  └─────────────────────────────────────────────────────────────┘
└─────────────────────────────────────────────────────────────────┘
```

---

## 🏗️ Architecture & Security

### Database Schema
```sql
-- wallet_plus table
CREATE TABLE wallet_plus (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    public_key VARCHAR(56) NOT NULL,
    device_id VARCHAR(255) NOT NULL,
    device_fingerprint TEXT NOT NULL,
    device_name VARCHAR(255) NULL,
    device_type ENUM('mobile', 'desktop', 'tablet') DEFAULT 'mobile',
    
    -- Authentication
    pin_hash VARCHAR(255) NOT NULL,
    pin_attempts INT DEFAULT 0,
    pin_locked_until TIMESTAMP NULL,
    biometric_enabled BOOLEAN DEFAULT FALSE,
    biometric_type ENUM('fingerprint', 'face', 'voice', 'iris') NULL,
    biometric_hash TEXT NULL,
    
    -- Multi-Factor Authentication
    mfa_enabled BOOLEAN DEFAULT TRUE,
    mfa_secret TEXT NOT NULL, -- Encrypted TOTP secret
    mfa_backup_codes JSON NULL, -- Encrypted backup codes
    mfa_last_used TIMESTAMP NULL,
    
    -- Cloud Backup
    cloud_backup_enabled BOOLEAN DEFAULT TRUE,
    encrypted_backup LONGTEXT NULL, -- Encrypted private key backup
    backup_metadata JSON NULL,
    backup_provider ENUM('icloud', 'google_drive', 'onedrive') NULL,
    backup_last_sync TIMESTAMP NULL,
    
    -- Security Settings
    auto_lock_timeout INT DEFAULT 300, -- 5 minutes
    require_biometric_for_transactions BOOLEAN DEFAULT TRUE,
    require_mfa_for_large_amounts BOOLEAN DEFAULT TRUE,
    large_amount_threshold DECIMAL(20,8) DEFAULT 1000.00,
    
    -- Session Management
    session_token VARCHAR(255) NULL,
    session_expires_at TIMESTAMP NULL,
    last_accessed_at TIMESTAMP NULL,
    last_ip_address VARCHAR(45) NULL,
    
    -- Status
    status ENUM('active', 'locked', 'suspended', 'disabled') DEFAULT 'active',
    failed_attempts INT DEFAULT 0,
    locked_until TIMESTAMP NULL,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    
    -- Indexes
    UNIQUE KEY unique_user_device (user_id, device_id),
    INDEX idx_public_key (public_key),
    INDEX idx_status (status),
    INDEX idx_device_id (device_id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- wallet_plus_recovery table
CREATE TABLE wallet_plus_recovery (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    wallet_plus_id BIGINT NOT NULL,
    recovery_email VARCHAR(255) NOT NULL,
    recovery_phone VARCHAR(20) NULL,
    recovery_method ENUM('password_mfa', 'social_recovery', 'hardware_key') DEFAULT 'password_mfa',
    recovery_data TEXT NOT NULL, -- Encrypted recovery information
    recovery_questions JSON NULL, -- Encrypted security questions
    
    -- Recovery Process
    recovery_token VARCHAR(255) NULL,
    recovery_initiated_at TIMESTAMP NULL,
    recovery_expires_at TIMESTAMP NULL,
    recovery_attempts INT DEFAULT 0,
    recovery_completed_at TIMESTAMP NULL,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_wallet_plus_id (wallet_plus_id),
    INDEX idx_recovery_token (recovery_token),
    FOREIGN KEY (wallet_plus_id) REFERENCES wallet_plus(id)
);

-- wallet_plus_transactions table
CREATE TABLE wallet_plus_transactions (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    wallet_plus_id BIGINT NOT NULL,
    transaction_hash VARCHAR(255) NOT NULL,
    transaction_type ENUM('send', 'receive', 'contract_call') NOT NULL,
    amount DECIMAL(20,8) NOT NULL,
    asset_code VARCHAR(12) NOT NULL,
    destination_address VARCHAR(56) NULL,
    source_address VARCHAR(56) NULL,
    memo TEXT NULL,
    
    -- Authentication Used
    auth_method ENUM('pin', 'biometric', 'mfa', 'combined') NOT NULL,
    requires_confirmation BOOLEAN DEFAULT FALSE,
    confirmed_at TIMESTAMP NULL,
    
    -- Status
    status ENUM('pending', 'confirmed', 'failed', 'cancelled') DEFAULT 'pending',
    stellar_status VARCHAR(50) NULL,
    error_message TEXT NULL,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_wallet_plus_id (wallet_plus_id),
    INDEX idx_transaction_hash (transaction_hash),
    INDEX idx_status (status),
    FOREIGN KEY (wallet_plus_id) REFERENCES wallet_plus(id)
);
```

### Configuration
```php
// config/stellar.php - Wallet Plus section
'wallet_plus' => [
    'enabled' => env('STELLAR_WALLET_PLUS_ENABLED', true),
    'mfa_threshold' => env('STELLAR_WALLET_PLUS_MFA_THRESHOLD', 1000), // USD equivalent
    'pin_min_length' => env('STELLAR_WALLET_PLUS_PIN_MIN_LENGTH', 4),
    'pin_max_length' => env('STELLAR_WALLET_PLUS_PIN_MAX_LENGTH', 8),
    'recovery_password_min_length' => env('STELLAR_WALLET_PLUS_RECOVERY_PASSWORD_MIN_LENGTH', 8),
    'session_timeout' => env('STELLAR_WALLET_PLUS_SESSION_TIMEOUT', 3600), // 1 hour
    'max_failed_attempts' => env('STELLAR_WALLET_PLUS_MAX_FAILED_ATTEMPTS', 5),
    'lockout_duration' => env('STELLAR_WALLET_PLUS_LOCKOUT_DURATION', 900), // 15 minutes
    
    'backup_encryption' => [
        'algorithm' => 'aes-256-cbc',
        'key_derivation' => 'pbkdf2',
        'iterations' => 100000,
    ],
    
    'device_binding' => [
        'enabled' => true,
        'fingerprint_required' => true,
        'max_devices_per_user' => 3,
    ],
    
    'biometric' => [
        'enabled' => true,
        'fallback_to_pin' => true,
        'timeout' => 30, // seconds
    ],
    
    'mfa' => [
        'algorithm' => 'sha1',
        'digits' => 6,
        'period' => 30, // seconds
        'window' => 1, // allow 1 period before/after
    ],
]
```

---

## 🔐 Device Binding Technology

### Device Fingerprinting
```php
class DeviceFingerprintService
{
    /**
     * Generate comprehensive device fingerprint
     */
    public function generateDeviceFingerprint(array $deviceInfo): string
    {
        $components = [
            'user_agent' => $deviceInfo['user_agent'] ?? '',
            'screen_resolution' => $deviceInfo['screen_resolution'] ?? '',
            'timezone' => $deviceInfo['timezone'] ?? '',
            'language' => $deviceInfo['language'] ?? '',
            'platform' => $deviceInfo['platform'] ?? '',
            'hardware_concurrency' => $deviceInfo['hardware_concurrency'] ?? '',
            'device_memory' => $deviceInfo['device_memory'] ?? '',
            'color_depth' => $deviceInfo['color_depth'] ?? '',
            'pixel_ratio' => $deviceInfo['pixel_ratio'] ?? '',
            'touch_support' => $deviceInfo['touch_support'] ?? false,
            'webgl_vendor' => $deviceInfo['webgl_vendor'] ?? '',
            'webgl_renderer' => $deviceInfo['webgl_renderer'] ?? '',
            'audio_fingerprint' => $deviceInfo['audio_fingerprint'] ?? '',
            'canvas_fingerprint' => $deviceInfo['canvas_fingerprint'] ?? '',
        ];
        
        // Add mobile-specific identifiers
        if (isset($deviceInfo['device_id'])) {
            $components['device_id'] = $deviceInfo['device_id'];
        }
        
        if (isset($deviceInfo['advertising_id'])) {
            $components['advertising_id'] = $deviceInfo['advertising_id'];
        }
        
        // Create stable fingerprint
        $fingerprint = hash('sha256', json_encode($components));
        
        return $fingerprint;
    }

    /**
     * Verify device fingerprint matches
     */
    public function verifyDeviceFingerprint(
        string $storedFingerprint,
        array $currentDeviceInfo,
        float $threshold = 0.8
    ): bool {
        $currentFingerprint = $this->generateDeviceFingerprint($currentDeviceInfo);
        
        // Exact match
        if ($storedFingerprint === $currentFingerprint) {
            return true;
        }
        
        // Fuzzy matching for minor changes
        $similarity = $this->calculateFingerprintSimilarity(
            $storedFingerprint,
            $currentFingerprint
        );
        
        return $similarity >= $threshold;
    }

    /**
     * Calculate fingerprint similarity
     */
    protected function calculateFingerprintSimilarity(string $fp1, string $fp2): float
    {
        // Use Levenshtein distance for similarity calculation
        $maxLength = max(strlen($fp1), strlen($fp2));
        $distance = levenshtein($fp1, $fp2);
        
        return 1 - ($distance / $maxLength);
    }
}
```

### Hardware Security Integration
```php
class HardwareSecurityService
{
    /**
     * Generate device-bound keypair using hardware security
     */
    public function generateDeviceBoundKeypair(string $deviceId): array
    {
        try {
            // Use hardware security module if available
            if ($this->hasHardwareSecurityModule()) {
                return $this->generateHSMKeypair($deviceId);
            }
            
            // Use Trusted Execution Environment
            if ($this->hasTrustedExecutionEnvironment()) {
                return $this->generateTEEKeypair($deviceId);
            }
            
            // Fallback to software-based secure generation
            return $this->generateSoftwareKeypair($deviceId);
            
        } catch (Exception $e) {
            Log::error('Hardware keypair generation failed', [
                'device_id' => $deviceId,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Failed to generate secure keypair');
        }
    }

    /**
     * Generate keypair using Hardware Security Module
     */
    protected function generateHSMKeypair(string $deviceId): array
    {
        // Integration with HSM (e.g., AWS CloudHSM, Azure Dedicated HSM)
        $keyPair = KeyPair::random();
        
        // Store private key in HSM
        $hsmKeyId = $this->storePrivateKeyInHSM($keyPair->getSecretSeed(), $deviceId);
        
        return [
            'public_key' => $keyPair->getAccountId(),
            'private_key' => $keyPair->getSecretSeed(),
            'encrypted_private_key' => $this->encryptForDevice($keyPair->getSecretSeed(), $deviceId),
            'hsm_key_id' => $hsmKeyId,
            'security_level' => 'hsm'
        ];
    }

    /**
     * Generate keypair using Trusted Execution Environment
     */
    protected function generateTEEKeypair(string $deviceId): array
    {
        $keyPair = KeyPair::random();
        
        // Encrypt private key for TEE storage
        $encryptedPrivateKey = $this->encryptForTEE($keyPair->getSecretSeed(), $deviceId);
        
        return [
            'public_key' => $keyPair->getAccountId(),
            'private_key' => $keyPair->getSecretSeed(),
            'encrypted_private_key' => $encryptedPrivateKey,
            'tee_key_id' => $this->generateTEEKeyId($deviceId),
            'security_level' => 'tee'
        ];
    }

    /**
     * Software-based secure keypair generation
     */
    protected function generateSoftwareKeypair(string $deviceId): array
    {
        $keyPair = KeyPair::random();
        
        // Encrypt private key with device-specific key
        $deviceKey = $this->deriveDeviceKey($deviceId);
        $encryptedPrivateKey = $this->encryptWithDeviceKey($keyPair->getSecretSeed(), $deviceKey);
        
        return [
            'public_key' => $keyPair->getAccountId(),
            'private_key' => $keyPair->getSecretSeed(),
            'encrypted_private_key' => $encryptedPrivateKey,
            'device_key_hash' => hash('sha256', $deviceKey),
            'security_level' => 'software'
        ];
    }
}
```

---

## 🔑 Authentication Methods

### WalletPlusService - Authentication
```php
class WalletPlusService
{
    /**
     * Authenticate with PIN
     */
    public function authenticateWithPin(WalletPlus $wallet, string $pin): array
    {
        try {
            // Check if wallet is locked
            if ($wallet->isLocked()) {
                return [
                    'success' => false,
                    'message' => 'Wallet is temporarily locked due to failed attempts',
                    'locked_until' => $wallet->locked_until
                ];
            }

            // Verify PIN
            if (!Hash::check($pin, $wallet->pin_hash)) {
                $wallet->increment('failed_attempts');
                
                // Lock wallet after max attempts
                if ($wallet->failed_attempts >= config('stellar.wallet_plus.max_failed_attempts')) {
                    $wallet->update([
                        'status' => 'locked',
                        'locked_until' => now()->addMinutes(config('stellar.wallet_plus.lockout_duration') / 60)
                    ]);
                }
                
                return [
                    'success' => false,
                    'message' => 'Invalid PIN',
                    'attempts_remaining' => config('stellar.wallet_plus.max_failed_attempts') - $wallet->failed_attempts
                ];
            }

            // Reset failed attempts on successful authentication
            $wallet->update([
                'failed_attempts' => 0,
                'last_accessed_at' => now(),
                'last_ip_address' => request()->ip()
            ]);

            // Generate session token
            $sessionToken = $this->generateSessionToken($wallet);

            return [
                'success' => true,
                'session_token' => $sessionToken,
                'expires_at' => now()->addSeconds(config('stellar.wallet_plus.session_timeout')),
                'message' => 'Authentication successful'
            ];

        } catch (Exception $e) {
            Log::error('PIN authentication failed', [
                'wallet_id' => $wallet->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Authentication failed'
            ];
        }
    }

    /**
     * Authenticate with biometric data
     */
    public function authenticateWithBiometric(WalletPlus $wallet, array $biometricData): array
    {
        try {
            if (!$wallet->biometric_enabled) {
                return [
                    'success' => false,
                    'message' => 'Biometric authentication not enabled'
                ];
            }

            // Verify biometric data
            $isValid = $this->verifyBiometricData($wallet, $biometricData);
            
            if (!$isValid) {
                $wallet->increment('failed_attempts');
                
                return [
                    'success' => false,
                    'message' => 'Biometric verification failed'
                ];
            }

            // Reset failed attempts and update access time
            $wallet->update([
                'failed_attempts' => 0,
                'last_accessed_at' => now(),
                'last_ip_address' => request()->ip()
            ]);

            // Generate session token
            $sessionToken = $this->generateSessionToken($wallet);

            return [
                'success' => true,
                'session_token' => $sessionToken,
                'expires_at' => now()->addSeconds(config('stellar.wallet_plus.session_timeout')),
                'message' => 'Biometric authentication successful'
            ];

        } catch (Exception $e) {
            Log::error('Biometric authentication failed', [
                'wallet_id' => $wallet->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Authentication failed'
            ];
        }
    }

    /**
     * Verify Multi-Factor Authentication
     */
    public function verifyMFA(WalletPlus $wallet, string $code): array
    {
        try {
            if (!$wallet->mfa_enabled) {
                return [
                    'success' => false,
                    'message' => 'MFA not enabled'
                ];
            }

            // Decrypt MFA secret
            $mfaSecret = Crypt::decryptString($wallet->mfa_secret);
            
            // Verify TOTP code
            $isValid = $this->verifyTOTPCode($mfaSecret, $code);
            
            if (!$isValid) {
                return [
                    'success' => false,
                    'message' => 'Invalid MFA code'
                ];
            }

            // Update last used timestamp
            $wallet->update([
                'mfa_last_used' => now()
            ]);

            return [
                'success' => true,
                'message' => 'MFA verification successful'
            ];

        } catch (Exception $e) {
            Log::error('MFA verification failed', [
                'wallet_id' => $wallet->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'MFA verification failed'
            ];
        }
    }

    /**
     * Combined authentication for high-value transactions
     */
    public function authenticateForTransaction(
        WalletPlus $wallet,
        array $transactionData,
        array $authData
    ): array {
        $amount = $transactionData['amount'] ?? 0;
        $threshold = $wallet->large_amount_threshold;
        
        // Determine required authentication methods
        $requiresBiometric = $wallet->require_biometric_for_transactions;
        $requiresMFA = $wallet->require_mfa_for_large_amounts && $amount > $threshold;
        
        $authResults = [];
        
        // PIN authentication (always required)
        if (isset($authData['pin'])) {
            $pinResult = $this->authenticateWithPin($wallet, $authData['pin']);
            $authResults['pin'] = $pinResult;
            
            if (!$pinResult['success']) {
                return $pinResult;
            }
        } else {
            return [
                'success' => false,
                'message' => 'PIN required for transaction'
            ];
        }
        
        // Biometric authentication (if required)
        if ($requiresBiometric) {
            if (isset($authData['biometric'])) {
                $biometricResult = $this->authenticateWithBiometric($wallet, $authData['biometric']);
                $authResults['biometric'] = $biometricResult;
                
                if (!$biometricResult['success']) {
                    return $biometricResult;
                }
            } else {
                return [
                    'success' => false,
                    'message' => 'Biometric authentication required for transactions'
                ];
            }
        }
        
        // MFA (if required for large amounts)
        if ($requiresMFA) {
            if (isset($authData['mfa_code'])) {
                $mfaResult = $this->verifyMFA($wallet, $authData['mfa_code']);
                $authResults['mfa'] = $mfaResult;
                
                if (!$mfaResult['success']) {
                    return $mfaResult;
                }
            } else {
                return [
                    'success' => false,
                    'message' => 'MFA required for large transactions',
                    'mfa_required' => true
                ];
            }
        }
        
        return [
            'success' => true,
            'message' => 'Transaction authentication successful',
            'auth_methods_used' => array_keys($authResults),
            'session_token' => $this->generateSessionToken($wallet)
        ];
    }
}
```

---

## 🔄 Recovery Mechanisms

### Recovery Process Implementation
```php
/**
 * Initiate wallet recovery process
 */
public function initiateRecovery(string $email, array $recoveryData): array
{
    try {
        // Find user by email
        $user = User::where('email', $email)->first();
        if (!$user) {
            return [
                'success' => false,
                'message' => 'User not found'
            ];
        }

        // Find wallet plus record
        $walletPlus = $user->walletPlus;
        if (!$walletPlus) {
            return [
                'success' => false,
                'message' => 'Wallet Plus not found'
            ];
        }

        // Get recovery record
        $recovery = $walletPlus->recovery;
        if (!$recovery) {
            return [
                'success' => false,
                'message' => 'Recovery not configured'
            ];
        }

        // Verify recovery password
        $recoveryInfo = json_decode(Crypt::decryptString($recovery->recovery_data), true);
        if (!Hash::check($recoveryData['recovery_password'], $recoveryInfo['password_hash'])) {
            return [
                'success' => false,
                'message' => 'Invalid recovery password'
            ];
        }

        // Generate recovery token
        $recoveryToken = Str::random(64);
        
        $recovery->update([
            'recovery_token' => $recoveryToken,
            'recovery_initiated_at' => now(),
            'recovery_expires_at' => now()->addHours(24), // 24-hour expiry
        ]);

        // Send recovery email
        $this->sendRecoveryEmail($user, $recoveryToken);

        return [
            'success' => true,
            'message' => 'Recovery initiated. Check your email for further instructions.',
            'recovery_token' => $recoveryToken, // For testing only
        ];

    } catch (Exception $e) {
        Log::error('Recovery initiation failed', [
            'email' => $email,
            'error' => $e->getMessage()
        ]);

        return [
            'success' => false,
            'message' => 'Recovery initiation failed'
        ];
    }
}

/**
 * Complete wallet recovery with new device
 */
public function completeRecovery(string $recoveryToken, array $newDeviceData): array
{
    try {
        // Find recovery record
        $recovery = WalletPlusRecovery::where('recovery_token', $recoveryToken)
            ->where('recovery_expires_at', '>', now())
            ->first();

        if (!$recovery) {
            return [
                'success' => false,
                'message' => 'Invalid or expired recovery token'
            ];
        }

        $walletPlus = $recovery->walletPlus;
        $user = $walletPlus->user;

        // Verify MFA if provided
        if (isset($newDeviceData['mfa_code'])) {
            $mfaResult = $this->verifyMFA($walletPlus, $newDeviceData['mfa_code']);
            if (!$mfaResult['success']) {
                return $mfaResult;
            }
        }

        // Generate new device fingerprint
        $deviceFingerprint = app(DeviceFingerprintService::class)
            ->generateDeviceFingerprint($newDeviceData['device_info']);

        // Restore private key from cloud backup
        $restoredKey = $this->restoreFromCloudBackup(
            $walletPlus->encrypted_backup,
            $newDeviceData['recovery_password']
        );

        if (!$restoredKey['success']) {
            return [
                'success' => false,
                'message' => 'Failed to restore wallet from backup'
            ];
        }

        // Create new device-bound encryption
        $newEncryptedKey = $this->encryptForDevice(
            $restoredKey['private_key'],
            $newDeviceData['device_id']
        );

        // Update wallet with new device information
        $walletPlus->update([
            'device_id' => $newDeviceData['device_id'],
            'device_fingerprint' => $deviceFingerprint,
            'device_name' => $newDeviceData['device_name'] ?? 'Recovered Device',
            'pin_hash' => Hash::make($newDeviceData['new_pin']),
            'status' => 'active',
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_accessed_at' => now(),
        ]);

        // Mark recovery as completed
        $recovery->update([
            'recovery_completed_at' => now(),
            'recovery_token' => null,
        ]);

        // Generate new session
        $sessionToken = $this->generateSessionToken($walletPlus);

        Log::info('Wallet recovery completed', [
            'user_id' => $user->id,
            'wallet_id' => $walletPlus->id,
            'new_device_id' => $newDeviceData['device_id']
        ]);

        return [
            'success' => true,
            'message' => 'Wallet recovery completed successfully',
            'wallet_plus' => $walletPlus,
            'session_token' => $sessionToken,
            'private_key' => $restoredKey['private_key'], // For client-side storage
        ];

    } catch (Exception $e) {
        Log::error('Recovery completion failed', [
            'recovery_token' => $recoveryToken,
            'error' => $e->getMessage()
        ]);

        return [
            'success' => false,
            'message' => 'Recovery completion failed'
        ];
    }
}
```

---

## ☁️ Cloud Backup System

### Encrypted Cloud Backup
```php
/**
 * Create encrypted cloud backup
 */
public function createEncryptedCloudBackup(
    string $privateKey,
    string $recoveryPassword,
    string $mfaSecret
): array {
    try {
        // Prepare backup data
        $backupData = [
            'private_key' => $privateKey,
            'mfa_secret' => $mfaSecret,
            'created_at' => now()->toISOString(),
            'version' => '1.0'
        ];

        // Derive encryption key from recovery password
        $salt = random_bytes(32);
        $encryptionKey = $this->deriveKeyFromPassword($recoveryPassword, $salt);

        // Encrypt backup data
        $encryptedData = $this->encryptBackupData(json_encode($backupData), $encryptionKey);

        // Create backup metadata
        $metadata = [
            'algorithm' => 'aes-256-cbc',
            'key_derivation' => 'pbkdf2',
            'iterations' => 100000,
            'salt' => base64_encode($salt),
            'created_at' => now()->toISOString(),
            'checksum' => hash('sha256', $encryptedData)
        ];

        return [
            'encrypted_backup' => base64_encode($encryptedData),
            'metadata' => $metadata
        ];

    } catch (Exception $e) {
        Log::error('Cloud backup creation failed', [
            'error' => $e->getMessage()
        ]);

        throw new Exception('Failed to create encrypted backup');
    }
}

/**
 * Restore from encrypted cloud backup
 */
public function restoreFromCloudBackup(string $encryptedBackup, string $recoveryPassword): array
{
    try {
        // Decode backup data
        $encryptedData = base64_decode($encryptedBackup);

        // Extract metadata (assuming it's stored separately)
        $metadata = $this->extractBackupMetadata($encryptedBackup);

        // Derive decryption key
        $salt = base64_decode($metadata['salt']);
        $decryptionKey = $this->deriveKeyFromPassword($recoveryPassword, $salt);

        // Decrypt backup data
        $decryptedData = $this->decryptBackupData($encryptedData, $decryptionKey);
        $backupData = json_decode($decryptedData, true);

        // Verify backup integrity
        if (!$this->verifyBackupIntegrity($backupData)) {
            throw new Exception('Backup integrity verification failed');
        }

        return [
            'success' => true,
            'private_key' => $backupData['private_key'],
            'mfa_secret' => $backupData['mfa_secret'],
            'created_at' => $backupData['created_at']
        ];

    } catch (Exception $e) {
        Log::error('Cloud backup restoration failed', [
            'error' => $e->getMessage()
        ]);

        return [
            'success' => false,
            'message' => 'Failed to restore from backup'
        ];
    }
}

/**
 * Derive encryption key from password using PBKDF2
 */
protected function deriveKeyFromPassword(string $password, string $salt): string
{
    return hash_pbkdf2(
        'sha256',
        $password,
        $salt,
        100000, // iterations
        32, // key length
        true
    );
}
```

---

This Wallet Plus system provides enterprise-grade security for self-custodial wallets while maintaining user convenience through advanced authentication methods and robust recovery mechanisms.
