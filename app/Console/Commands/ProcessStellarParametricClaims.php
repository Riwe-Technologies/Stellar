<?php

namespace App\Console\Commands;

use App\Services\StellarClaimService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessStellarParametricClaims extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stellar:process-parametric-claims 
                            {--dry-run : Run without making actual changes}
                            {--policy-id= : Process claims for specific policy ID only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process parametric insurance claims automatically using Stellar smart contracts';

    /**
     * The Stellar claim service instance.
     *
     * @var StellarClaimService
     */
    protected $stellarClaimService;

    /**
     * Create a new command instance.
     */
    public function __construct(StellarClaimService $stellarClaimService)
    {
        parent::__construct();
        $this->stellarClaimService = $stellarClaimService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting Stellar parametric claims processing...');

        try {
            $isDryRun = $this->option('dry-run');
            $policyId = $this->option('policy-id');

            if ($isDryRun) {
                $this->warn('Running in DRY RUN mode - no actual changes will be made');
            }

            if ($policyId) {
                $this->info("Processing claims for policy ID: {$policyId}");
                $stats = $this->processSpecificPolicy($policyId, $isDryRun);
            } else {
                $this->info('Processing all eligible parametric policies...');
                $stats = $this->processAllPolicies($isDryRun);
            }

            $this->displayResults($stats);

            Log::info('Stellar parametric claims processing completed', [
                'stats' => $stats,
                'dry_run' => $isDryRun,
                'policy_id' => $policyId,
            ]);

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('Failed to process parametric claims: ' . $e->getMessage());
            
            Log::error('Stellar parametric claims processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Process all eligible policies
     *
     * @param bool $isDryRun
     * @return array
     */
    protected function processAllPolicies(bool $isDryRun): array
    {
        if ($isDryRun) {
            $this->warn('DRY RUN: Would process parametric claims for all policies');
            return [
                'policies_checked' => 0,
                'claims_created' => 0,
                'payouts_processed' => 0,
                'errors' => 0,
                'dry_run' => true,
            ];
        }

        return $this->stellarClaimService->processParametricClaims();
    }

    /**
     * Process claims for a specific policy
     *
     * @param string $policyId
     * @param bool $isDryRun
     * @return array
     */
    protected function processSpecificPolicy(string $policyId, bool $isDryRun): array
    {
        $stats = [
            'policies_checked' => 1,
            'claims_created' => 0,
            'payouts_processed' => 0,
            'errors' => 0,
            'dry_run' => $isDryRun,
        ];

        try {
            $policy = \App\Models\InsurancePolicy::findOrFail($policyId);

            if (!$policy->isOnStellar()) {
                $this->error("Policy {$policyId} is not on Stellar blockchain");
                $stats['errors'] = 1;
                return $stats;
            }

            if (!$policy->product->is_parametric) {
                $this->error("Policy {$policyId} is not a parametric policy");
                $stats['errors'] = 1;
                return $stats;
            }

            if ($policy->status !== 'active') {
                $this->error("Policy {$policyId} is not active");
                $stats['errors'] = 1;
                return $stats;
            }

            if ($isDryRun) {
                $this->warn("DRY RUN: Would process parametric claims for policy {$policyId}");
                return $stats;
            }

            $this->info("Processing parametric claims for policy: {$policy->policy_number}");

            // Use reflection to access the protected method
            $reflection = new \ReflectionClass($this->stellarClaimService);
            $method = $reflection->getMethod('processParametricClaimForPolicy');
            $method->setAccessible(true);
            
            $result = $method->invoke($this->stellarClaimService, $policy);

            if ($result['claim_created']) {
                $stats['claims_created'] = 1;
                $this->info('✓ Parametric claim created');
            }

            if ($result['payout_processed']) {
                $stats['payouts_processed'] = 1;
                $this->info('✓ Automatic payout processed');
            }

            if (!empty($result['triggers_exceeded'])) {
                $this->info('Triggers exceeded: ' . count($result['triggers_exceeded']));
                foreach ($result['triggers_exceeded'] as $trigger) {
                    $this->line("  - {$trigger['type']}: {$trigger['actual_value']} (threshold: {$trigger['threshold']})");
                }
            } else {
                $this->info('No triggers exceeded for this policy');
            }

        } catch (Exception $e) {
            $this->error("Error processing policy {$policyId}: " . $e->getMessage());
            $stats['errors'] = 1;
        }

        return $stats;
    }

    /**
     * Display processing results
     *
     * @param array $stats
     */
    protected function displayResults(array $stats): void
    {
        $this->newLine();
        $this->info('=== Processing Results ===');
        
        if ($stats['dry_run'] ?? false) {
            $this->warn('DRY RUN MODE - No actual changes made');
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Policies Checked', $stats['policies_checked']],
                ['Claims Created', $stats['claims_created']],
                ['Payouts Processed', $stats['payouts_processed']],
                ['Errors', $stats['errors']],
            ]
        );

        if ($stats['claims_created'] > 0) {
            $this->info("✓ Successfully created {$stats['claims_created']} parametric claim(s)");
        }

        if ($stats['payouts_processed'] > 0) {
            $this->info("✓ Successfully processed {$stats['payouts_processed']} automatic payout(s)");
        }

        if ($stats['errors'] > 0) {
            $this->error("✗ Encountered {$stats['errors']} error(s) during processing");
        }

        if ($stats['policies_checked'] === 0) {
            $this->warn('No eligible policies found for processing');
        }
    }

    /**
     * Get command help text
     *
     * @return string
     */
    public function getHelp(): string
    {
        return <<<'HELP'
This command processes parametric insurance claims automatically using Stellar smart contracts.

The command will:
1. Check all active parametric policies on Stellar blockchain
2. Retrieve environmental data for each policy's farm location
3. Evaluate parametric triggers against current conditions
4. Create automatic claims for exceeded triggers
5. Process automatic payouts for high-confidence claims

Options:
  --dry-run         Run without making actual changes (useful for testing)
  --policy-id=ID    Process claims for a specific policy ID only

Examples:
  php artisan stellar:process-parametric-claims
  php artisan stellar:process-parametric-claims --dry-run
  php artisan stellar:process-parametric-claims --policy-id=123

This command should be run regularly (e.g., daily) via cron to ensure
timely processing of parametric claims.
HELP;
    }
}
