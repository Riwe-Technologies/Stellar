<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\Network;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypeCreditAlphaNum4;
use Soneso\StellarSDK\AssetTypeCreditAlphaNum12;
use Soneso\StellarSDK\PaymentOperationBuilder;
use Soneso\StellarSDK\TransactionBuilder;
use Soneso\StellarSDK\ChangeTrustOperationBuilder;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Exception;

/**
 * Stellar Service for handling blockchain operations
 * 
 * This service provides a unified interface for interacting with the Stellar network,
 * including account management, transactions, and smart contract operations.
 */
class StellarService
{
    protected $sdk;
    protected $network;
    protected $networkPassphrase;
    protected $horizonUrl;
    protected $sorobanRpcUrl;

    public function __construct()
    {
        $this->initializeNetwork();
    }

    /**
     * Initialize the Stellar network configuration
     */
    protected function initializeNetwork()
    {
        $networkName = Config::get('stellar.default_network', 'testnet');
        $this->network = Config::get("stellar.networks.{$networkName}");
        
        if (!$this->network) {
            throw new Exception("Stellar network configuration not found for: {$networkName}");
        }

        $this->networkPassphrase = $this->network['network_passphrase'];
        $this->horizonUrl = $this->network['horizon_url'];
        $this->sorobanRpcUrl = $this->network['soroban_rpc_url'];

        // Initialize Stellar SDK when available
        $this->initializeSdk();
    }

    /**
     * Initialize the Stellar SDK
     */
    protected function initializeSdk()
    {
        try {
            $networkName = Config::get('stellar.default_network', 'testnet');

            // Initialize Stellar SDK based on network
            $this->sdk = $networkName === 'testnet'
                ? StellarSDK::getTestNetInstance()
                : StellarSDK::getPublicNetInstance();

            Log::channel('stellar')->info('Stellar SDK initialized', [
                'network' => $networkName,
                'horizon_url' => $this->horizonUrl
            ]);
        } catch (Exception $e) {
            Log::channel('stellar')->error('Failed to initialize Stellar SDK', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create a new Stellar account
     *
     * @return array Account details including public and secret keys
     */
    public function createAccount(): array
    {
        try {
            // Generate a new random keypair
            $keyPair = KeyPair::random();

            $accountData = [
                'public_key' => $keyPair->getAccountId(),
                'secret_key' => $keyPair->getSecretSeed(),
                'created_at' => now(),
            ];

            Log::channel('stellar')->info('New Stellar account created', [
                'public_key' => $accountData['public_key']
            ]);

            return $accountData;
        } catch (Exception $e) {
            Log::channel('stellar')->error('Failed to create Stellar account', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Fund a testnet account using Friendbot
     *
     * @param string $accountId The account ID to fund
     * @return bool Success status
     */
    public function fundTestnetAccount(string $accountId): bool
    {
        try {
            if (Config::get('stellar.default_network') !== 'testnet') {
                throw new Exception('Friendbot funding is only available on testnet');
            }

            // Fund the account using Friendbot
            $networkName = Config::get('stellar.default_network');
            $networkConfig = Config::get("stellar.networks.{$networkName}");
            $friendbotUrl = $networkConfig['friendbot_url'];

            if (!$friendbotUrl) {
                throw new Exception('Friendbot URL not configured for testnet');
            }

            $response = file_get_contents($friendbotUrl . '?addr=' . $accountId);
            $funded = $response !== false;

            Log::channel('stellar')->info('Testnet account funded', [
                'account_id' => $accountId,
                'funded' => $funded
            ]);

            return $funded;
        } catch (Exception $e) {
            Log::channel('stellar')->error('Failed to fund testnet account', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get account information from the Stellar network
     *
     * @param string $accountId The account ID to query
     * @return array Account information
     */
    public function getAccountInfo(string $accountId): array
    {
        try {
            // Get account information from Stellar network
            $account = $this->sdk->requestAccount($accountId);

            $accountInfo = [
                'account_id' => $account->getAccountId(),
                'sequence' => $account->getSequenceNumber(),
                'balances' => [],
                'signers' => [],
                'data' => [],
            ];

            // Convert balances
            foreach ($account->getBalances() as $balance) {
                $accountInfo['balances'][] = [
                    'asset_type' => $balance->getAssetType(),
                    'asset_code' => $balance->getAssetCode(),
                    'asset_issuer' => $balance->getAssetIssuer(),
                    'balance' => $balance->getBalance(),
                ];
            }

            // Convert signers
            foreach ($account->getSigners() as $signer) {
                $accountInfo['signers'][] = [
                    'key' => $signer->getKey(),
                    'weight' => $signer->getWeight(),
                    'type' => $signer->getType(),
                ];
            }

            Log::channel('stellar')->debug('Account info retrieved', [
                'account_id' => $accountId,
                'balance_count' => count($accountInfo['balances'])
            ]);

            return $accountInfo;
        } catch (Exception $e) {
            Log::channel('stellar')->error('Failed to get account info', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Send a payment transaction
     * 
     * @param string $sourceSecret Source account secret key
     * @param string $destinationId Destination account ID
     * @param string $amount Amount to send
     * @param string $assetCode Asset code (default: XLM)
     * @param string|null $assetIssuer Asset issuer (for non-native assets)
     * @param string|null $memo Transaction memo
     * @return array Transaction result
     */
    public function sendPayment(
        string $sourceSecret,
        string $destinationId,
        string $amount,
        string $assetCode = 'XLM',
        ?string $assetIssuer = null,
        ?string $memo = null
    ): array {
        try {
            $sourceKeyPair = KeyPair::fromSeed($sourceSecret);
            $sourceAccount = $this->sdk->requestAccount($sourceKeyPair->getAccountId());

            // Create asset
            $asset = $assetCode === 'XLM'
                ? Asset::native()
                : Asset::createNonNativeAsset($assetCode, $assetIssuer);

            // Build payment operation
            $paymentOperation = (new PaymentOperationBuilder($destinationId, $asset, $amount))->build();

            // Build transaction
            $transactionBuilder = new TransactionBuilder($sourceAccount);

            if ($memo) {
                $transactionBuilder->addMemo(Memo::text($memo));
            }

            $transaction = $transactionBuilder->addOperation($paymentOperation)->build();

            // Sign transaction
            $transaction->sign($sourceKeyPair, $this->getNetwork());

            // Submit transaction
            $response = $this->sdk->submitTransaction($transaction);

            if ($response->isSuccessful()) {
                $result = [
                    'success' => true,
                    'transaction_hash' => $response->getHash(),
                    'source_account' => $sourceKeyPair->getAccountId(),
                    'destination_account' => $destinationId,
                    'amount' => $amount,
                    'asset_code' => $assetCode,
                    'asset_issuer' => $assetIssuer,
                    'memo' => $memo,
                    'ledger' => $response->getLedger(),
                    'fee_charged' => $response->getFeeCharged(),
                ];

                Log::channel('stellar-transactions')->info('Payment sent successfully', $result);
                return $result;
            } else {
                throw new Exception('Transaction failed: ' . $response->getExtras()['result_codes']['transaction'] ?? 'Unknown error');
            }
        } catch (Exception $e) {
            Log::channel('stellar-transactions')->error('Failed to send payment', [
                'destination' => $destinationId,
                'amount' => $amount,
                'asset_code' => $assetCode,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create a trustline for a custom asset
     * 
     * @param string $accountSecret Account secret key
     * @param string $assetCode Asset code
     * @param string $assetIssuer Asset issuer account ID
     * @param string|null $limit Trust limit (optional)
     * @return array Transaction result
     */
    public function createTrustline(
        string $accountSecret,
        string $assetCode,
        string $assetIssuer,
        ?string $limit = null
    ): array {
        try {
            // Create keypair from secret
            $keyPair = KeyPair::fromSeed($accountSecret);
            $accountId = $keyPair->getAccountId();

            // Load account from network
            $account = $this->sdk->requestAccount($accountId);

            // Create the asset
            if (strlen($assetCode) <= 4) {
                $asset = new AssetTypeCreditAlphaNum4($assetCode, $assetIssuer);
            } else {
                $asset = new AssetTypeCreditAlphaNum12($assetCode, $assetIssuer);
            }

            // Create change trust operation
            $changeTrustOp = (new ChangeTrustOperationBuilder($asset, $limit))->build();

            // Build transaction
            $transaction = (new TransactionBuilder($account))
                ->addOperation($changeTrustOp)
                ->addTimeBounds(0, time() + 30)
                ->build();

            // Sign transaction
            $transaction->sign($keyPair, $this->getNetwork());

            // Submit transaction
            $response = $this->sdk->submitTransaction($transaction);

            if ($response->isSuccessful()) {
                $result = [
                    'success' => true,
                    'transaction_hash' => $response->getHash(),
                    'account_id' => $accountId,
                    'asset_code' => $assetCode,
                    'asset_issuer' => $assetIssuer,
                    'limit' => $limit,
                    'ledger' => $response->getLedger(),
                ];

                Log::channel('stellar-transactions')->info('Trustline created successfully', $result);
                return $result;
            } else {
                throw new Exception('Transaction failed: ' . $response->getExtras()['result_codes']['transaction'] ?? 'Unknown error');
            }

        } catch (Exception $e) {
            Log::channel('stellar-transactions')->error('Failed to create trustline', [
                'asset_code' => $assetCode,
                'asset_issuer' => $assetIssuer,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'asset_code' => $assetCode,
                'asset_issuer' => $assetIssuer,
            ];
        }
    }

    /**
     * Remove a trustline (set limit to 0)
     *
     * @param string $accountSecret Account secret key
     * @param string $assetCode Asset code
     * @param string $assetIssuer Asset issuer account ID
     * @return array Transaction result
     */
    public function removeTrustline(
        string $accountSecret,
        string $assetCode,
        string $assetIssuer
    ): array {
        return $this->createTrustline($accountSecret, $assetCode, $assetIssuer, "0");
    }

    /**
     * Get account trustlines
     *
     * @param string $accountId Account ID
     * @return array List of trustlines
     */
    public function getAccountTrustlines(string $accountId): array
    {
        try {
            $account = $this->sdk->requestAccount($accountId);
            $trustlines = [];

            foreach ($account->getBalances() as $balance) {
                if ($balance->getAssetType() !== 'native') {
                    $trustlines[] = [
                        'asset_code' => $balance->getAssetCode(),
                        'asset_issuer' => $balance->getAssetIssuer(),
                        'balance' => $balance->getBalance(),
                        'limit' => $balance->getLimit(),
                        'buying_liabilities' => $balance->getBuyingLiabilities(),
                        'selling_liabilities' => $balance->getSellingLiabilities(),
                    ];
                }
            }

            return [
                'success' => true,
                'trustlines' => $trustlines
            ];

        } catch (Exception $e) {
            Log::channel('stellar')->error('Failed to get account trustlines', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'trustlines' => []
            ];
        }
    }

    /**
     * Get the current network configuration
     *
     * @return array Network configuration
     */
    public function getNetworkConfig(): array
    {
        return [
            'network' => Config::get('stellar.default_network'),
            'horizon_url' => $this->horizonUrl,
            'soroban_rpc_url' => $this->sorobanRpcUrl,
            'network_passphrase' => $this->networkPassphrase,
        ];
    }

    /**
     * Get Stellar network instance
     */
    protected function getNetwork(): Network
    {
        $networkName = Config::get('stellar.default_network', 'testnet');
        return $networkName === 'mainnet' ? Network::public() : Network::testnet();
    }

    /**
     * Create and fund a new account on mainnet
     *
     * @param string $sourceSecret Secret key of funding account
     * @param string $destinationId Public key of account to create/fund
     * @param string $amount Amount in XLM to fund
     * @return bool Success status
     */
    public function createAndFundAccount(string $sourceSecret, string $destinationId, string $amount): bool
    {
        try {
            if (Config::get('stellar.default_network') !== 'mainnet') {
                throw new Exception('This method is only for mainnet');
            }

            // Load source account
            $sourceKeyPair = KeyPair::fromSeed($sourceSecret);
            $sourceAccountId = $sourceKeyPair->getAccountId();

            // Get source account info
            $sourceAccount = $this->sdk->requestAccount($sourceAccountId);

            // Create transaction to fund new account
            $transaction = (new TransactionBuilder($sourceAccount))
                ->addOperation(
                    (new CreateAccountOperationBuilder($destinationId, $amount))
                        ->build()
                )
                ->addMemo(Memo::text('Riwe wallet activation'))
                ->setTimeout(300)
                ->build();

            // Sign transaction
            $transaction->sign($sourceKeyPair, $this->getNetwork());

            // Submit transaction
            $response = $this->sdk->submitTransaction($transaction);

            if ($response->isSuccessful()) {
                Log::channel('stellar')->info('Account created and funded', [
                    'source_account' => $sourceAccountId,
                    'destination_account' => $destinationId,
                    'amount' => $amount,
                    'transaction_hash' => $response->getHash()
                ]);
                return true;
            } else {
                Log::channel('stellar')->error('Failed to create and fund account', [
                    'source_account' => $sourceAccountId,
                    'destination_account' => $destinationId,
                    'amount' => $amount,
                    'error' => $response->getExtras()
                ]);
                return false;
            }
        } catch (Exception $e) {
            Log::channel('stellar')->error('Exception creating and funding account', [
                'destination_account' => $destinationId,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get account balance in XLM
     *
     * @param string $accountId Account public key
     * @return float Balance in XLM
     */
    public function getAccountBalance(string $accountId): float
    {
        try {
            $accountInfo = $this->getAccountInfo($accountId);

            if (!$accountInfo || !isset($accountInfo['balances'])) {
                return 0.0;
            }

            foreach ($accountInfo['balances'] as $balance) {
                if ($balance['asset_type'] === 'native') {
                    return floatval($balance['balance']);
                }
            }

            return 0.0;
        } catch (Exception $e) {
            Log::channel('stellar')->error('Failed to get account balance', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }

    /**
     * Transfer crypto to Riwe treasury
     */
    public function transferToTreasury(string $userSecret, float $amount, string $currency): array
    {
        $treasuryConfig = Config::get('stellar.treasury');
        $treasuryPublicKey = $treasuryConfig['hot_wallet_public'] ?? $treasuryConfig['master_account_public'];

        if (!$treasuryPublicKey) {
            throw new Exception('Treasury wallet not configured');
        }

        // Determine asset issuer for USDC
        $assetIssuer = null;
        if ($currency === 'USDC') {
            $assetIssuer = Config::get('stellar.assets.usdc.issuer');
        }

        return $this->sendPayment(
            $userSecret,
            $treasuryPublicKey,
            (string) $amount,
            $currency,
            $assetIssuer,
            'Conversion to fiat'
        );
    }
}
