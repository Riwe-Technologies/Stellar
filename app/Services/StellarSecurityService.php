<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Exception;

class StellarSecurityService
{
    protected $encryptionMethod;
    protected $keyStorageMethod;

    public function __construct()
    {
        $this->encryptionMethod = Config::get('stellar.security.encryption_method', 'laravel');
        $this->keyStorageMethod = Config::get('stellar.security.key_storage_method', 'database');
    }

    /**
     * Encrypt private key using configured method
     */
    public function encryptPrivateKey(string $privateKey): array
    {
        switch ($this->encryptionMethod) {
            case 'libsodium':
                return $this->encryptWithLibsodium($privateKey);
            
            case 'aws_kms':
                return $this->encryptWithAwsKms($privateKey);
            
            case 'laravel':
            default:
                return $this->encryptWithLaravel($privateKey);
        }
    }

    /**
     * Decrypt private key using configured method
     */
    public function decryptPrivateKey(string $encryptedKey, array $metadata = []): string
    {
        $method = $metadata['encryption_method'] ?? $this->encryptionMethod;
        
        switch ($method) {
            case 'libsodium':
                return $this->decryptWithLibsodium($encryptedKey, $metadata);
            
            case 'aws_kms':
                return $this->decryptWithAwsKms($encryptedKey, $metadata);
            
            case 'laravel':
            default:
                return $this->decryptWithLaravel($encryptedKey);
        }
    }

    /**
     * Encrypt with Laravel's built-in encryption (AES-256-CBC)
     */
    protected function encryptWithLaravel(string $privateKey): array
    {
        try {
            $encrypted = Crypt::encryptString($privateKey);
            
            return [
                'encrypted_key' => $encrypted,
                'encryption_method' => 'laravel',
                'metadata' => [
                    'algorithm' => 'AES-256-CBC',
                    'encrypted_at' => now()->toISOString(),
                ]
            ];
        } catch (Exception $e) {
            throw new Exception('Failed to encrypt with Laravel: ' . $e->getMessage());
        }
    }

    /**
     * Decrypt with Laravel's built-in encryption
     */
    protected function decryptWithLaravel(string $encryptedKey): string
    {
        try {
            return Crypt::decryptString($encryptedKey);
        } catch (Exception $e) {
            throw new Exception('Failed to decrypt with Laravel: ' . $e->getMessage());
        }
    }

    /**
     * Encrypt with libsodium (XChaCha20-Poly1305)
     */
    protected function encryptWithLibsodium(string $privateKey): array
    {
        if (!extension_loaded('sodium')) {
            throw new Exception('Sodium extension not available');
        }

        try {
            // Generate a random key for this specific encryption
            $key = sodium_crypto_secretbox_keygen();
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            
            // Encrypt the private key
            $encrypted = sodium_crypto_secretbox($privateKey, $nonce, $key);
            
            // Encrypt the key itself with master key
            $masterKey = $this->getMasterKey();
            $encryptedKey = Crypt::encryptString(base64_encode($key));
            
            return [
                'encrypted_key' => base64_encode($encrypted),
                'encryption_method' => 'libsodium',
                'metadata' => [
                    'algorithm' => 'XChaCha20-Poly1305',
                    'nonce' => base64_encode($nonce),
                    'encrypted_key' => $encryptedKey,
                    'encrypted_at' => now()->toISOString(),
                ]
            ];
        } catch (Exception $e) {
            throw new Exception('Failed to encrypt with libsodium: ' . $e->getMessage());
        }
    }

    /**
     * Decrypt with libsodium
     */
    protected function decryptWithLibsodium(string $encryptedKey, array $metadata): string
    {
        if (!extension_loaded('sodium')) {
            throw new Exception('Sodium extension not available');
        }

        try {
            // Decrypt the encryption key
            $key = base64_decode(Crypt::decryptString($metadata['encrypted_key']));
            $nonce = base64_decode($metadata['nonce']);
            $encrypted = base64_decode($encryptedKey);
            
            // Decrypt the private key
            $decrypted = sodium_crypto_secretbox_open($encrypted, $nonce, $key);
            
            if ($decrypted === false) {
                throw new Exception('Failed to decrypt data');
            }
            
            return $decrypted;
        } catch (Exception $e) {
            throw new Exception('Failed to decrypt with libsodium: ' . $e->getMessage());
        }
    }

    /**
     * Encrypt with AWS KMS (placeholder - requires AWS SDK)
     */
    protected function encryptWithAwsKms(string $privateKey): array
    {
        // This would require AWS SDK implementation
        throw new Exception('AWS KMS encryption not implemented yet. Please install AWS SDK and configure KMS.');
        
        // Example implementation:
        /*
        $kms = new \Aws\Kms\KmsClient([
            'version' => 'latest',
            'region' => Config::get('stellar.security.aws_region'),
        ]);
        
        $result = $kms->encrypt([
            'KeyId' => Config::get('stellar.security.aws_kms_key_id'),
            'Plaintext' => $privateKey,
        ]);
        
        return [
            'encrypted_key' => base64_encode($result['CiphertextBlob']),
            'encryption_method' => 'aws_kms',
            'metadata' => [
                'key_id' => Config::get('stellar.security.aws_kms_key_id'),
                'encrypted_at' => now()->toISOString(),
            ]
        ];
        */
    }

    /**
     * Decrypt with AWS KMS (placeholder)
     */
    protected function decryptWithAwsKms(string $encryptedKey, array $metadata): string
    {
        throw new Exception('AWS KMS decryption not implemented yet. Please install AWS SDK and configure KMS.');
    }

    /**
     * Get master encryption key
     */
    protected function getMasterKey(): string
    {
        $key = Config::get('stellar.security.master_encryption_key') ?? Config::get('app.key');
        
        if (!$key) {
            throw new Exception('Master encryption key not configured');
        }
        
        return hash('sha256', $key, true);
    }

    /**
     * Generate secure random seed for wallet creation
     */
    public function generateSecureRandomSeed(): string
    {
        if (extension_loaded('sodium')) {
            return sodium_bin2hex(random_bytes(32));
        }
        
        return bin2hex(random_bytes(32));
    }

    /**
     * Validate private key format
     */
    public function validatePrivateKey(string $privateKey): bool
    {
        // Stellar private keys are 32 bytes (64 hex characters) starting with 'S'
        return strlen($privateKey) === 56 && str_starts_with($privateKey, 'S');
    }

    /**
     * Secure memory cleanup (best effort)
     */
    public function secureCleanup(string &$sensitiveData): void
    {
        // Overwrite the string with random data
        $length = strlen($sensitiveData);
        $sensitiveData = str_repeat("\0", $length);
        
        // Clear from memory (PHP limitation - not guaranteed)
        unset($sensitiveData);
        
        // Force garbage collection
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }

    /**
     * Get security recommendations for current configuration
     */
    public function getSecurityRecommendations(): array
    {
        $recommendations = [];
        
        // Check encryption method
        if ($this->encryptionMethod === 'laravel') {
            $recommendations[] = [
                'level' => 'medium',
                'message' => 'Consider upgrading to libsodium encryption for enhanced security',
                'action' => 'Set STELLAR_ENCRYPTION_METHOD=libsodium in .env'
            ];
        }
        
        // Check if sodium is available
        if (!extension_loaded('sodium')) {
            $recommendations[] = [
                'level' => 'high',
                'message' => 'Install sodium extension for enhanced cryptographic security',
                'action' => 'Install php-sodium extension'
            ];
        }
        
        // Check master key configuration
        if (!Config::get('stellar.security.master_encryption_key')) {
            $recommendations[] = [
                'level' => 'high',
                'message' => 'Configure dedicated master encryption key',
                'action' => 'Set STELLAR_MASTER_ENCRYPTION_KEY in .env'
            ];
        }
        
        return $recommendations;
    }
}
