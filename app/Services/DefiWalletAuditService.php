<?php

namespace App\Services;

use App\Models\User;
use App\Models\DefiWallet;
use App\Models\DefiTransaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class DefiWalletAuditService
{
    /**
     * Log wallet creation
     */
    public function logWalletCreation(User $user, DefiWallet $wallet, array $context = []): void
    {
        $this->logEvent('wallet_created', $user, [
            'wallet_id' => $wallet->id,
            'initial_status' => $wallet->status,
            'kyc_level' => $wallet->kyc_level,
            'context' => $context
        ]);
    }

    /**
     * Log wallet status change
     */
    public function logWalletStatusChange(User $user, DefiWallet $wallet, string $oldStatus, string $newStatus, array $context = []): void
    {
        $this->logEvent('wallet_status_changed', $user, [
            'wallet_id' => $wallet->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'context' => $context
        ]);
    }

    /**
     * Log transaction creation
     */
    public function logTransactionCreated(User $user, DefiTransaction $transaction, array $context = []): void
    {
        $this->logEvent('transaction_created', $user, [
            'transaction_id' => $transaction->id,
            'transaction_reference' => $transaction->reference,
            'type' => $transaction->type,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'amount_fiat' => $transaction->amount_fiat,
            'fiat_currency' => $transaction->fiat_currency,
            'status' => $transaction->status,
            'context' => $context
        ]);
    }

    /**
     * Log transaction status change
     */
    public function logTransactionStatusChange(User $user, DefiTransaction $transaction, string $oldStatus, string $newStatus, array $context = []): void
    {
        $this->logEvent('transaction_status_changed', $user, [
            'transaction_id' => $transaction->id,
            'transaction_reference' => $transaction->reference,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'type' => $transaction->type,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'context' => $context
        ]);
    }

    /**
     * Log fiat deposit initiation
     */
    public function logFiatDepositInitiated(User $user, array $depositData, array $context = []): void
    {
        $this->logEvent('fiat_deposit_initiated', $user, [
            'amount' => $depositData['amount'],
            'currency' => $depositData['currency'] ?? 'NGN',
            'payment_method' => 'paystack',
            'context' => $context
        ]);
    }

    /**
     * Log fiat withdrawal initiation
     */
    public function logFiatWithdrawalInitiated(User $user, array $withdrawalData, array $context = []): void
    {
        $this->logEvent('fiat_withdrawal_initiated', $user, [
            'amount' => $withdrawalData['amount'],
            'currency' => $withdrawalData['currency'] ?? 'NGN',
            'bank_code' => $withdrawalData['bank_code'],
            'account_number' => substr($withdrawalData['account_number'], 0, 3) . '****' . substr($withdrawalData['account_number'], -3),
            'context' => $context
        ]);
    }

    /**
     * Log crypto send operation
     */
    public function logCryptoSent(User $user, array $sendData, array $context = []): void
    {
        $this->logEvent('crypto_sent', $user, [
            'amount' => $sendData['amount'],
            'currency' => $sendData['currency'],
            'destination_address' => substr($sendData['destination_address'], 0, 8) . '...' . substr($sendData['destination_address'], -8),
            'memo' => $sendData['memo'] ?? null,
            'context' => $context
        ]);
    }

    /**
     * Log bank account verification
     */
    public function logBankAccountVerification(User $user, array $bankData, bool $success, array $context = []): void
    {
        $this->logEvent('bank_account_verification', $user, [
            'bank_code' => $bankData['bank_code'],
            'account_number' => substr($bankData['account_number'], 0, 3) . '****' . substr($bankData['account_number'], -3),
            'verification_success' => $success,
            'context' => $context
        ]);
    }

    /**
     * Log BVN verification
     */
    public function logBvnVerification(User $user, bool $success, array $context = []): void
    {
        $this->logEvent('bvn_verification', $user, [
            'verification_success' => $success,
            'verification_method' => $context['method'] ?? 'paystack',
            'context' => $context
        ]);
    }

    /**
     * Log security event
     */
    public function logSecurityEvent(User $user, string $eventType, array $details = []): void
    {
        $this->logEvent('security_event', $user, [
            'security_event_type' => $eventType,
            'details' => $details
        ], 'warning');
    }

    /**
     * Log suspicious activity
     */
    public function logSuspiciousActivity(User $user, string $activityType, array $details = []): void
    {
        $this->logEvent('suspicious_activity', $user, [
            'activity_type' => $activityType,
            'details' => $details,
            'requires_review' => true
        ], 'alert');
    }

    /**
     * Log rate limit exceeded
     */
    public function logRateLimitExceeded(User $user, string $operation, array $context = []): void
    {
        $this->logEvent('rate_limit_exceeded', $user, [
            'operation' => $operation,
            'context' => $context
        ], 'warning');
    }

    /**
     * Log wallet settings change
     */
    public function logWalletSettingsChange(User $user, DefiWallet $wallet, array $oldSettings, array $newSettings): void
    {
        $changes = [];
        foreach ($newSettings as $key => $value) {
            if (isset($oldSettings[$key]) && $oldSettings[$key] !== $value) {
                $changes[$key] = [
                    'old' => $oldSettings[$key],
                    'new' => $value
                ];
            }
        }

        if (!empty($changes)) {
            $this->logEvent('wallet_settings_changed', $user, [
                'wallet_id' => $wallet->id,
                'changes' => $changes
            ]);
        }
    }

    /**
     * Log balance update
     */
    public function logBalanceUpdate(User $user, DefiWallet $wallet, string $currency, float $oldBalance, float $newBalance, string $reason): void
    {
        $this->logEvent('balance_updated', $user, [
            'wallet_id' => $wallet->id,
            'currency' => $currency,
            'old_balance' => $oldBalance,
            'new_balance' => $newBalance,
            'change' => $newBalance - $oldBalance,
            'reason' => $reason
        ]);
    }

    /**
     * Log failed operation
     */
    public function logFailedOperation(User $user, string $operation, string $reason, array $context = []): void
    {
        $this->logEvent('operation_failed', $user, [
            'operation' => $operation,
            'failure_reason' => $reason,
            'context' => $context
        ], 'error');
    }

    /**
     * Log API access
     */
    public function logApiAccess(User $user, Request $request, string $endpoint, bool $success = true): void
    {
        $this->logEvent('api_access', $user, [
            'endpoint' => $endpoint,
            'method' => $request->method(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'success' => $success,
            'request_id' => $request->header('X-Request-ID') ?? uniqid()
        ]);
    }

    /**
     * Get audit trail for user
     */
    public function getAuditTrail(User $user, array $filters = []): array
    {
        // This would typically query a dedicated audit log table
        // For now, we'll use Laravel's log files
        
        $logPath = storage_path('logs/defi-wallet-audit.log');
        
        if (!file_exists($logPath)) {
            return [];
        }

        $logs = [];
        $handle = fopen($logPath, 'r');
        
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                if (strpos($line, "user_id:{$user->id}") !== false) {
                    $logs[] = $this->parseLogLine($line);
                }
            }
            fclose($handle);
        }

        // Apply filters
        if (!empty($filters['event_type'])) {
            $logs = array_filter($logs, fn($log) => $log['event_type'] === $filters['event_type']);
        }

        if (!empty($filters['date_from'])) {
            $logs = array_filter($logs, fn($log) => $log['timestamp'] >= $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $logs = array_filter($logs, fn($log) => $log['timestamp'] <= $filters['date_to']);
        }

        return array_reverse(array_slice($logs, 0, $filters['limit'] ?? 100));
    }

    /**
     * Generate security report for user
     */
    public function generateSecurityReport(User $user, int $days = 30): array
    {
        $auditTrail = $this->getAuditTrail($user, [
            'date_from' => now()->subDays($days)->toDateString()
        ]);

        $report = [
            'user_id' => $user->id,
            'period_days' => $days,
            'total_events' => count($auditTrail),
            'event_summary' => [],
            'security_events' => 0,
            'failed_operations' => 0,
            'suspicious_activities' => 0,
            'unique_ips' => [],
            'transaction_summary' => [
                'total_transactions' => 0,
                'successful_transactions' => 0,
                'failed_transactions' => 0,
                'total_volume_usd' => 0
            ]
        ];

        foreach ($auditTrail as $event) {
            $eventType = $event['event_type'] ?? 'unknown';
            $report['event_summary'][$eventType] = ($report['event_summary'][$eventType] ?? 0) + 1;

            if ($eventType === 'security_event') {
                $report['security_events']++;
            }

            if ($eventType === 'operation_failed') {
                $report['failed_operations']++;
            }

            if ($eventType === 'suspicious_activity') {
                $report['suspicious_activities']++;
            }

            if (isset($event['data']['ip_address'])) {
                $report['unique_ips'][] = $event['data']['ip_address'];
            }

            if (strpos($eventType, 'transaction') !== false) {
                $report['transaction_summary']['total_transactions']++;
                
                if (isset($event['data']['status']) && $event['data']['status'] === 'completed') {
                    $report['transaction_summary']['successful_transactions']++;
                }
                
                if (isset($event['data']['status']) && in_array($event['data']['status'], ['failed', 'cancelled'])) {
                    $report['transaction_summary']['failed_transactions']++;
                }
            }
        }

        $report['unique_ips'] = array_unique($report['unique_ips']);
        $report['unique_ip_count'] = count($report['unique_ips']);

        return $report;
    }

    /**
     * Core logging method
     */
    public function logEvent(string $eventType, User $user, array $data = [], string $level = 'info'): void
    {
        $logData = [
            'event_type' => $eventType,
            'user_id' => $user->id,
            'user_email' => $user->email,
            'timestamp' => now()->toISOString(),
            'data' => $data,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ];

        // Log to dedicated audit log channel
        Log::channel('defi-wallet-audit')->{$level}($eventType, $logData);

        // For critical events, also log to main log
        if (in_array($level, ['alert', 'critical', 'emergency'])) {
            Log::{$level}("DeFi Wallet {$eventType}", $logData);
        }
    }

    /**
     * Parse log line (simplified implementation)
     */
    protected function parseLogLine(string $line): array
    {
        // This is a simplified parser - in production you'd want more robust parsing
        $pattern = '/\[(.*?)\] (\w+)\.(\w+): (.*)/';
        
        if (preg_match($pattern, $line, $matches)) {
            return [
                'timestamp' => $matches[1],
                'level' => $matches[2],
                'event_type' => $matches[3],
                'data' => json_decode($matches[4], true) ?: []
            ];
        }

        return [
            'timestamp' => now()->toISOString(),
            'level' => 'unknown',
            'event_type' => 'unknown',
            'data' => ['raw_line' => $line]
        ];
    }
}
