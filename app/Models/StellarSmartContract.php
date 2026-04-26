<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StellarSmartContract extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'contract_id',
        'contract_address',
        'contract_type',
        'name',
        'description',
        'deployer_account',
        'deployment_transaction_hash',
        'wasm_hash',
        'network',
        'status',
        'version',
        'abi',
        'source_code_hash',
        'metadata',
        'deployed_at',
        'last_invoked_at',
        'invocation_count',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'abi' => 'array',
        'metadata' => 'array',
        'deployed_at' => 'datetime',
        'last_invoked_at' => 'datetime',
        'invocation_count' => 'integer',
    ];

    /**
     * Get the insurance policies using this contract.
     */
    public function insurancePolicies()
    {
        return $this->hasMany(InsurancePolicy::class, 'stellar_contract_id');
    }

    /**
     * Get the claims using this contract.
     */
    public function claims()
    {
        return $this->hasMany(Claim::class, 'stellar_contract_id');
    }

    /**
     * Get the transactions related to this contract.
     */
    public function stellarTransactions()
    {
        return $this->hasMany(StellarTransaction::class, 'contract_id', 'contract_id');
    }

    /**
     * Check if the contract is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the contract is deployed.
     *
     * @return bool
     */
    public function isDeployed(): bool
    {
        return in_array($this->status, ['active', 'inactive']);
    }

    /**
     * Check if the contract is on testnet.
     *
     * @return bool
     */
    public function isTestnet(): bool
    {
        return $this->network === 'testnet';
    }

    /**
     * Check if the contract is on mainnet.
     *
     * @return bool
     */
    public function isMainnet(): bool
    {
        return $this->network === 'mainnet';
    }

    /**
     * Check if the contract is an insurance policy contract.
     *
     * @return bool
     */
    public function isPolicyContract(): bool
    {
        return $this->contract_type === 'insurance_policy';
    }

    /**
     * Check if the contract is a claim processing contract.
     *
     * @return bool
     */
    public function isClaimContract(): bool
    {
        return $this->contract_type === 'insurance_claim';
    }

    /**
     * Check if the contract is a payment processing contract.
     *
     * @return bool
     */
    public function isPaymentContract(): bool
    {
        return $this->contract_type === 'insurance_payment';
    }



    /**
     * Get the contract type label.
     *
     * @return string
     */
    public function getTypeLabel(): string
    {
        return match($this->contract_type) {
            'insurance_policy' => 'Insurance Policy',
            'insurance_claim' => 'Insurance Claim',
            'insurance_payment' => 'Insurance Payment',
            'parametric_oracle' => 'Parametric Oracle',
            'weather_oracle' => 'Weather Oracle',
            'satellite_oracle' => 'Satellite Oracle',
            default => ucfirst(str_replace('_', ' ', $this->contract_type))
        };
    }

    /**
     * Get the status label.
     *
     * @return string
     */
    public function getStatusLabel(): string
    {
        return match($this->status) {
            'active' => 'Active',
            'inactive' => 'Inactive',
            'deploying' => 'Deploying',
            'deployed' => 'Deployed',
            'failed' => 'Failed',
            'archived' => 'Archived',
            default => ucfirst($this->status)
        };
    }

    /**
     * Get the Stellar explorer URL for this contract.
     *
     * @return string|null
     */
    public function getExplorerUrl(): ?string
    {
        if (!$this->contract_address) {
            return null;
        }

        $network = $this->network ?? 'testnet';
        $baseUrl = $network === 'testnet'
            ? 'https://stellar.expert/explorer/testnet'
            : 'https://stellar.expert/explorer/public';

        return $baseUrl . '/contract/' . $this->contract_address;
    }

    /**
     * Get the deployment transaction explorer URL.
     *
     * @return string|null
     */
    public function getDeploymentTransactionUrl(): ?string
    {
        if (!$this->deployment_transaction_hash) {
            return null;
        }

        $network = $this->network ?? 'testnet';
        $baseUrl = $network === 'testnet'
            ? 'https://stellar.expert/explorer/testnet'
            : 'https://stellar.expert/explorer/public';

        return $baseUrl . '/tx/' . $this->deployment_transaction_hash;
    }

    /**
     * Scope a query to only include active contracts.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to only include deployed contracts.
     */
    public function scopeDeployed($query)
    {
        return $query->whereIn('status', ['active', 'inactive']);
    }

    /**
     * Scope a query to only include testnet contracts.
     */
    public function scopeTestnet($query)
    {
        return $query->where('network', 'testnet');
    }

    /**
     * Scope a query to only include mainnet contracts.
     */
    public function scopeMainnet($query)
    {
        return $query->where('network', 'mainnet');
    }

    /**
     * Scope a query to filter by contract type.
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('contract_type', $type);
    }

    /**
     * Increment the invocation count.
     *
     * @return bool
     */
    public function incrementInvocationCount(): bool
    {
        return $this->update([
            'invocation_count' => $this->invocation_count + 1,
            'last_invoked_at' => now(),
        ]);
    }

    /**
     * Update the contract status.
     *
     * @param string $status
     * @return bool
     */
    public function updateStatus(string $status): bool
    {
        return $this->update(['status' => $status]);
    }

    /**
     * Add metadata to the contract.
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function addMetadata(string $key, $value): bool
    {
        $metadata = $this->metadata ?? [];
        $metadata[$key] = $value;
        
        return $this->update(['metadata' => $metadata]);
    }

    /**
     * Get metadata value by key.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getMetadata(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Remove metadata by key.
     *
     * @param string $key
     * @return bool
     */
    public function removeMetadata(string $key): bool
    {
        $metadata = $this->metadata ?? [];
        unset($metadata[$key]);
        
        return $this->update(['metadata' => $metadata]);
    }

    /**
     * Get the contract's ABI methods.
     *
     * @return array
     */
    public function getAbiMethods(): array
    {
        return $this->abi['methods'] ?? [];
    }

    /**
     * Check if the contract has a specific method.
     *
     * @param string $methodName
     * @return bool
     */
    public function hasMethod(string $methodName): bool
    {
        $methods = $this->getAbiMethods();
        
        foreach ($methods as $method) {
            if ($method['name'] === $methodName) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get method details by name.
     *
     * @param string $methodName
     * @return array|null
     */
    public function getMethod(string $methodName): ?array
    {
        $methods = $this->getAbiMethods();
        
        foreach ($methods as $method) {
            if ($method['name'] === $methodName) {
                return $method;
            }
        }
        
        return null;
    }
}
