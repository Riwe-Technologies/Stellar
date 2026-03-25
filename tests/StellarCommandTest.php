<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Farm;
use App\Models\InsuranceProduct;
use App\Models\InsurancePolicy;
use App\Models\StellarWallet;
use App\Models\StellarSmartContract;
use App\Services\StellarClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;

class StellarCommandTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $farm;
    protected $product;
    protected $policy;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->user = User::factory()->create([
            'stellar_enabled' => true,
            'stellar_enabled_at' => now(),
        ]);

        $this->farm = Farm::factory()->create([
            'user_id' => $this->user->id,
            'latitude' => 40.7128,
            'longitude' => -74.0060,
        ]);

        $this->product = InsuranceProduct::factory()->create([
            'is_parametric' => true,
            'parametric_triggers' => [
                'rainfall' => 50,
                'temperature' => 35,
            ],
        ]);

        $wallet = StellarWallet::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        $contract = StellarSmartContract::factory()->create([
            'contract_type' => 'insurance_policy',
            'status' => 'active',
        ]);

        $this->policy = InsurancePolicy::factory()->create([
            'user_id' => $this->user->id,
            'farm_id' => $this->farm->id,
            'product_id' => $this->product->id,
            'status' => 'active',
            'stellar_wallet_id' => $wallet->id,
            'stellar_contract_id' => $contract->id,
            'stellar_policy_id' => 'POL-123-456',
        ]);
    }

    /** @test */
    public function parametric_claims_command_runs_successfully()
    {
        // Mock the StellarClaimService
        $claimServiceMock = Mockery::mock(StellarClaimService::class);
        $claimServiceMock->shouldReceive('processParametricClaims')
            ->once()
            ->andReturn([
                'policies_checked' => 1,
                'claims_created' => 1,
                'payouts_processed' => 1,
                'errors' => 0,
            ]);

        $this->app->instance(StellarClaimService::class, $claimServiceMock);

        $this->artisan('stellar:process-parametric-claims')
            ->expectsOutput('Starting Stellar parametric claims processing...')
            ->expectsOutput('Processing all eligible parametric policies...')
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Policies Checked', 1],
                    ['Claims Created', 1],
                    ['Payouts Processed', 1],
                    ['Errors', 0],
                ]
            )
            ->expectsOutput('✓ Successfully created 1 parametric claim(s)')
            ->expectsOutput('✓ Successfully processed 1 automatic payout(s)')
            ->assertExitCode(0);
    }

    /** @test */
    public function parametric_claims_command_runs_in_dry_run_mode()
    {
        $this->artisan('stellar:process-parametric-claims --dry-run')
            ->expectsOutput('Starting Stellar parametric claims processing...')
            ->expectsOutput('Running in DRY RUN mode - no actual changes will be made')
            ->expectsOutput('DRY RUN: Would process parametric claims for all policies')
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Policies Checked', 0],
                    ['Claims Created', 0],
                    ['Payouts Processed', 0],
                    ['Errors', 0],
                ]
            )
            ->expectsOutput('DRY RUN MODE - No actual changes made')
            ->assertExitCode(0);
    }

    /** @test */
    public function parametric_claims_command_processes_specific_policy()
    {
        // Mock the StellarClaimService for specific policy processing
        $claimServiceMock = Mockery::mock(StellarClaimService::class);
        
        // We need to mock the protected method access
        $this->app->instance(StellarClaimService::class, $claimServiceMock);

        $this->artisan("stellar:process-parametric-claims --policy-id={$this->policy->id}")
            ->expectsOutput('Starting Stellar parametric claims processing...')
            ->expectsOutput("Processing claims for policy ID: {$this->policy->id}")
            ->expectsOutput("Processing parametric claims for policy: {$this->policy->policy_number}")
            ->assertExitCode(0);
    }

    /** @test */
    public function parametric_claims_command_handles_non_existent_policy()
    {
        $this->artisan('stellar:process-parametric-claims --policy-id=999999')
            ->expectsOutput('Starting Stellar parametric claims processing...')
            ->expectsOutput('Processing claims for policy ID: 999999')
            ->assertExitCode(1); // Should fail with non-existent policy
    }

    /** @test */
    public function parametric_claims_command_handles_non_stellar_policy()
    {
        // Create a policy without Stellar integration
        $nonStellarPolicy = InsurancePolicy::factory()->create([
            'user_id' => $this->user->id,
            'farm_id' => $this->farm->id,
            'product_id' => $this->product->id,
            'stellar_contract_id' => null,
            'stellar_policy_id' => null,
        ]);

        $this->artisan("stellar:process-parametric-claims --policy-id={$nonStellarPolicy->id}")
            ->expectsOutput('Starting Stellar parametric claims processing...')
            ->expectsOutput("Processing claims for policy ID: {$nonStellarPolicy->id}")
            ->expectsOutput("Policy {$nonStellarPolicy->id} is not on Stellar blockchain")
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Policies Checked', 1],
                    ['Claims Created', 0],
                    ['Payouts Processed', 0],
                    ['Errors', 1],
                ]
            )
            ->expectsOutput('✗ Encountered 1 error(s) during processing')
            ->assertExitCode(0);
    }

    /** @test */
    public function parametric_claims_command_handles_non_parametric_policy()
    {
        // Create a non-parametric product
        $nonParametricProduct = InsuranceProduct::factory()->create([
            'is_parametric' => false,
        ]);

        // Update policy to use non-parametric product
        $this->policy->update(['product_id' => $nonParametricProduct->id]);

        $this->artisan("stellar:process-parametric-claims --policy-id={$this->policy->id}")
            ->expectsOutput('Starting Stellar parametric claims processing...')
            ->expectsOutput("Processing claims for policy ID: {$this->policy->id}")
            ->expectsOutput("Policy {$this->policy->id} is not a parametric policy")
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Policies Checked', 1],
                    ['Claims Created', 0],
                    ['Payouts Processed', 0],
                    ['Errors', 1],
                ]
            )
            ->expectsOutput('✗ Encountered 1 error(s) during processing')
            ->assertExitCode(0);
    }

    /** @test */
    public function parametric_claims_command_handles_inactive_policy()
    {
        // Update policy to inactive status
        $this->policy->update(['status' => 'expired']);

        $this->artisan("stellar:process-parametric-claims --policy-id={$this->policy->id}")
            ->expectsOutput('Starting Stellar parametric claims processing...')
            ->expectsOutput("Processing claims for policy ID: {$this->policy->id}")
            ->expectsOutput("Policy {$this->policy->id} is not active")
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Policies Checked', 1],
                    ['Claims Created', 0],
                    ['Payouts Processed', 0],
                    ['Errors', 1],
                ]
            )
            ->expectsOutput('✗ Encountered 1 error(s) during processing')
            ->assertExitCode(0);
    }

    /** @test */
    public function parametric_claims_command_shows_help()
    {
        $this->artisan('stellar:process-parametric-claims --help')
            ->expectsOutput('Process parametric insurance claims automatically using Stellar smart contracts')
            ->assertExitCode(0);
    }

    /** @test */
    public function parametric_claims_command_handles_service_exception()
    {
        // Mock the StellarClaimService to throw an exception
        $claimServiceMock = Mockery::mock(StellarClaimService::class);
        $claimServiceMock->shouldReceive('processParametricClaims')
            ->once()
            ->andThrow(new \Exception('Service unavailable'));

        $this->app->instance(StellarClaimService::class, $claimServiceMock);

        $this->artisan('stellar:process-parametric-claims')
            ->expectsOutput('Starting Stellar parametric claims processing...')
            ->expectsOutput('Failed to process parametric claims: Service unavailable')
            ->assertExitCode(1);
    }

    /** @test */
    public function parametric_claims_command_displays_no_policies_message()
    {
        // Remove all policies
        InsurancePolicy::query()->delete();

        // Mock the service to return empty stats
        $claimServiceMock = Mockery::mock(StellarClaimService::class);
        $claimServiceMock->shouldReceive('processParametricClaims')
            ->once()
            ->andReturn([
                'policies_checked' => 0,
                'claims_created' => 0,
                'payouts_processed' => 0,
                'errors' => 0,
            ]);

        $this->app->instance(StellarClaimService::class, $claimServiceMock);

        $this->artisan('stellar:process-parametric-claims')
            ->expectsOutput('Starting Stellar parametric claims processing...')
            ->expectsOutput('No eligible policies found for processing')
            ->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
