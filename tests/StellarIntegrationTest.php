<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Farm;
use App\Models\InsuranceProduct;
use App\Models\InsurancePolicy;
use App\Models\StellarWallet;
use App\Services\StellarService;
use App\Services\StellarWalletService;
use App\Services\StellarPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Mockery;

class StellarIntegrationTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $user;
    protected $farm;
    protected $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user
        $this->user = User::factory()->create([
            'stellar_enabled' => true,
            'stellar_enabled_at' => now(),
        ]);

        // Create test farm
        $this->farm = Farm::factory()->create([
            'user_id' => $this->user->id,
        ]);

        // Create test insurance product
        $this->product = InsuranceProduct::factory()->create([
            'is_parametric' => true,
            'parametric_triggers' => [
                'rainfall' => 50,
                'temperature' => 35,
            ],
        ]);
    }

    /** @test */
    public function user_can_enable_stellar_features()
    {
        $user = User::factory()->create(['stellar_enabled' => false]);

        $response = $this->actingAs($user)
            ->postJson('/api/stellar/wallet/enable');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Stellar features enabled successfully',
            ]);

        $this->assertTrue($user->fresh()->hasStellarEnabled());
    }

    /** @test */
    public function user_can_disable_stellar_features()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/stellar/wallet/disable');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Stellar features disabled successfully',
            ]);

        $this->assertFalse($this->user->fresh()->hasStellarEnabled());
    }

    /** @test */
    public function user_can_get_payment_quote()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/stellar/payments/quote', [
                'amount' => '100.00',
                'currency' => 'USD',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'quote' => [
                    'usd_amount',
                    'xlm_amount',
                    'exchange_rate',
                    'fee_xlm',
                    'total_xlm',
                    'expires_at',
                ],
            ]);
    }

    /** @test */
    public function user_can_get_supported_assets()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/stellar/payments/assets');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'assets' => [
                    '*' => [
                        'code',
                        'name',
                        'issuer',
                        'type',
                        'decimals',
                    ],
                ],
            ]);
    }

    /** @test */
    public function user_can_create_policy_on_stellar()
    {
        // Mock the Stellar services to avoid actual blockchain calls
        $this->mockStellarServices();

        $response = $this->actingAs($this->user)
            ->postJson('/api/stellar/policies', [
                'farm_id' => $this->farm->id,
                'product_id' => $this->product->id,
                'premium_amount' => '100.00',
                'coverage_amount' => '5000.00',
                'start_date' => now()->addDay()->format('Y-m-d'),
                'end_date' => now()->addMonths(6)->format('Y-m-d'),
                'parametric_triggers' => [
                    'rainfall' => 50,
                    'temperature' => 35,
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'policy_id',
                    'stellar_policy_id',
                    'contract_id',
                    'transaction_hash',
                ],
            ]);
    }

    /** @test */
    public function user_can_submit_claim_to_stellar()
    {
        // Create a policy first
        $policy = InsurancePolicy::factory()->create([
            'user_id' => $this->user->id,
            'farm_id' => $this->farm->id,
            'product_id' => $this->product->id,
            'stellar_contract_id' => 1,
            'stellar_policy_id' => 'POL-123-456',
        ]);

        // Mock the Stellar services
        $this->mockStellarServices();

        $response = $this->actingAs($this->user)
            ->postJson('/api/stellar/claims', [
                'insurance_policy_id' => $policy->id,
                'incident_type' => 'drought',
                'incident_date' => now()->subDays(2)->format('Y-m-d'),
                'amount_claimed' => '2500.00',
                'description' => 'Crop damage due to drought conditions',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'claim_id',
                    'stellar_claim_id',
                    'transaction_hash',
                ],
            ]);
    }

    /** @test */
    public function user_can_get_stellar_policies()
    {
        // Create some policies
        InsurancePolicy::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'stellar_contract_id' => 1,
            'stellar_policy_id' => 'POL-123-456',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/stellar/policies');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'policies' => [
                    'data' => [
                        '*' => [
                            'id',
                            'policy_number',
                            'stellar_policy_id',
                            'stellar_contract_id',
                            'status',
                        ],
                    ],
                ],
            ]);
    }

    /** @test */
    public function user_can_get_stellar_claims()
    {
        // Create a policy and claims
        $policy = InsurancePolicy::factory()->create([
            'user_id' => $this->user->id,
            'stellar_contract_id' => 1,
            'stellar_policy_id' => 'POL-123-456',
        ]);

        \App\Models\Claim::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'insurance_policy_id' => $policy->id,
            'stellar_contract_id' => 1,
            'stellar_claim_id' => 'CLM-123-456',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/stellar/claims');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'claims' => [
                    'data' => [
                        '*' => [
                            'id',
                            'claim_number',
                            'stellar_claim_id',
                            'stellar_contract_id',
                            'status',
                        ],
                    ],
                ],
            ]);
    }

    /** @test */
    public function unauthorized_user_cannot_access_stellar_endpoints()
    {
        $response = $this->getJson('/api/stellar/wallet/balance');
        $response->assertStatus(401);

        $response = $this->getJson('/api/stellar/policies');
        $response->assertStatus(401);

        $response = $this->getJson('/api/stellar/claims');
        $response->assertStatus(401);
    }

    /** @test */
    public function user_without_stellar_enabled_cannot_use_stellar_features()
    {
        $user = User::factory()->create(['stellar_enabled' => false]);

        $response = $this->actingAs($user)
            ->getJson('/api/stellar/wallet/balance');

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Stellar features not enabled for this user',
            ]);
    }

    /** @test */
    public function validation_errors_are_returned_for_invalid_data()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/stellar/policies', [
                'farm_id' => 999999, // Non-existent farm
                'product_id' => $this->product->id,
                'premium_amount' => 'invalid', // Invalid amount
                'coverage_amount' => '5000.00',
                'start_date' => 'invalid-date', // Invalid date
                'end_date' => now()->addMonths(6)->format('Y-m-d'),
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'errors',
            ]);
    }

    /**
     * Mock Stellar services to avoid actual blockchain calls during testing
     */
    protected function mockStellarServices()
    {
        // Mock StellarService
        $stellarServiceMock = Mockery::mock(StellarService::class);
        $stellarServiceMock->shouldReceive('createAccount')
            ->andReturn([
                'public_key' => 'GTEST123456789',
                'secret_key' => 'STEST123456789',
                'created_at' => now(),
            ]);

        $stellarServiceMock->shouldReceive('getNetworkConfig')
            ->andReturn([
                'network' => 'testnet',
                'horizon_url' => 'https://horizon-testnet.stellar.org',
                'soroban_rpc_url' => 'https://soroban-testnet.stellar.org',
                'network_passphrase' => 'Test SDF Network ; September 2015',
            ]);

        // Mock StellarWalletService
        $walletServiceMock = Mockery::mock(StellarWalletService::class);
        $walletServiceMock->shouldReceive('createWallet')
            ->andReturn(StellarWallet::factory()->make([
                'user_id' => $this->user->id,
                'public_key' => 'GTEST123456789',
                'status' => 'active',
                'network' => 'testnet',
            ]));

        // Mock StellarPaymentService
        $paymentServiceMock = Mockery::mock(StellarPaymentService::class);
        $paymentServiceMock->shouldReceive('getPaymentQuote')
            ->andReturn([
                'usd_amount' => '100.00',
                'xlm_amount' => '833.3333333',
                'exchange_rate' => 0.12,
                'fee_xlm' => '0.00001',
                'total_xlm' => '833.3333433',
                'expires_at' => now()->addMinutes(5),
            ]);

        $paymentServiceMock->shouldReceive('getSupportedAssets')
            ->andReturn([
                [
                    'code' => 'XLM',
                    'name' => 'Stellar Lumens',
                    'issuer' => null,
                    'type' => 'native',
                    'decimals' => 7,
                ],
            ]);

        // Bind mocks to the container
        $this->app->instance(StellarService::class, $stellarServiceMock);
        $this->app->instance(StellarWalletService::class, $walletServiceMock);
        $this->app->instance(StellarPaymentService::class, $paymentServiceMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
