<?php

namespace App\Services;

use Exception;
use Soneso\StellarSDK\Network;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Soroban\SorobanServer;
use Soneso\StellarSDK\Soroban\Requests\GetTransactionRequest;
use Soneso\StellarSDK\Soroban\Responses\GetTransactionResponse;
use Soneso\StellarSDK\Soroban\Responses\SendTransactionResponse;
use Soneso\StellarSDK\Soroban\Transaction\SendTransactionRequest;
use Soneso\StellarSDK\Soroban\Transaction\TransactionBuilder;
use Soneso\StellarSDK\Soroban\Transaction\SorobanTransactionData;
use Soneso\StellarSDK\AbstractAccount;
use Soneso\StellarSDK\Soroban\Address;
use Soneso\StellarSDK\Xdr\XdrSCVal;
use App\Models\InsurancePolicy;

class StellarSdkContractService
{
    private SorobanServer $sorobanServer;
    private Network $network;

    public function __construct()
    {
        $networkName = config('stellar.default_network', 'testnet');
        $rpcUrl = config("stellar.networks.{$networkName}.soroban_rpc_url");

        if (!$rpcUrl) {
            throw new Exception("Soroban RPC URL is not configured for network [{$networkName}].");
        }

        $this->sorobanServer = new SorobanServer($rpcUrl);
        $this->network = $networkName === 'mainnet' ? Network::public() : Network::testnet();
    }

    /**
     * Generic method to invoke a function on a smart contract.
     *
     * @param string $contractId The ID of the contract to invoke.
     * @param string $functionName The name of the function to invoke.
     * @param array $params The parameters for the function.
     * @param string $sourceAccountSecret The secret key of the invoking account.
     * @return mixed The result of the contract invocation.
     */
    private function invokeContract(string $contractId, string $functionName, array $params, string $sourceAccountSecret)
    {
        $sourceAccount = KeyPair::fromSeed($sourceAccountSecret);

        $contractAddress = new Address($contractId);

        $builder = new TransactionBuilder($sourceAccount);
        $op = $builder->buildInvokeHostFunctionOperation($contractAddress, $functionName, $params);
        $builder->addOperation($op);
        
        $transaction = $builder->build();
        
        $sorobanTxData = new SorobanTransactionData(
            $transaction->getSorobanData()->getResources(),
            $transaction->getSorobanData()->getResourceFee() * 2
        );
        $transaction->setSorobanData($sorobanTxData);

        $simulateResponse = $this->sorobanServer->simulateTransaction($transaction);
        if ($simulateResponse->getError() !== null || $simulateResponse->getResult() === null) {
            throw new Exception("Failed to simulate transaction: " . ($simulateResponse->getError() ?? 'Unknown error'));
        }

        $transaction = $simulateResponse->getTransaction();
        $transaction->sign($sourceAccount, $this->network);

        $sendResponse = $this->sorobanServer->sendTransaction($transaction);
        if ($sendResponse->getError() !== null) {
            throw new Exception("Failed to send transaction: " . $sendResponse->getError());
        }

        $transactionResponse = $this->pollTransactionStatus($sendResponse->getHash());

        if ($transactionResponse->getStatus() === GetTransactionResponse::STATUS_SUCCESS) {
            $resultValueXdr = $transactionResponse->getResultValueXdr();

            return $resultValueXdr
                ? XdrSCVal::fromBase64($resultValueXdr)->toNative()
                : null;
        }

        throw new Exception("Transaction failed with status: " . ($transactionResponse->getStatus() ?? 'Unknown'));
    }

    private function getConfiguredContractId(string $configKey): string
    {
        $contractId = config("stellar.insurance.{$configKey}");

        if (!$contractId) {
            throw new Exception("Missing Stellar contract ID configuration for [stellar.insurance.{$configKey}].");
        }

        return $contractId;
    }

    /**
     * Polls for the status of a submitted Soroban transaction.
     */
    private function pollTransactionStatus(string $txHash): GetTransactionResponse
    {
        $status = GetTransactionResponse::STATUS_PENDING;
        $response = null;
        while ($status === GetTransactionResponse::STATUS_PENDING) {
            sleep(2);
            $response = $this->sorobanServer->getTransaction(new GetTransactionRequest($txHash));
            $status = $response->getStatus();
        }
        return $response;
    }

    /**
     * Creates a policy by invoking the InsurancePolicyContract.
     */
    public function createPolicy(InsurancePolicy $policy, string $sourceAccountSecret): string
    {
        $policyContractId = $this->getConfiguredContractId('policy_contract_id');
        $functionName = 'create_policy';

        // Convert data to Soroban types
        $params = [
            'policyholder' => new Address($policy->user->stellar_account_id),
            'farm_location' => $policy->getSorobanLocation(), // Assumes a method on the model
            'asset' => new Address($policy->asset_address), // Assumes policy has asset address
            'premium_amount' => $policy->premium_amount,
            'coverage_amount' => $policy->coverage_amount,
            'start_date' => $policy->start_date->getTimestamp(),
            'end_date' => $policy->end_date->getTimestamp(),
            'parametric_triggers' => $policy->getSorobanTriggers(), // Assumes a method on the model
        ];

        // The result of create_policy is the policy_id (String)
        return $this->invokeContract($policyContractId, $functionName, $params, $sourceAccountSecret);
    }
    
    /**
     * Activates a policy after payment.
     */
    public function activatePolicy(string $policyId, string $sourceAccountSecret): void
    {
        $policyContractId = $this->getConfiguredContractId('policy_contract_id');
        $functionName = 'activate_policy';
        $params = ['policy_id' => $policyId];

        $this->invokeContract($policyContractId, $functionName, $params, $sourceAccountSecret);
    }

    /**
     * Processes a premium payment by invoking the InsurancePaymentContract.
     */
    public function processPremium(InsurancePolicy $policy, string $sourceAccountSecret): string
    {
        $paymentContractId = $this->getConfiguredContractId('payment_contract_id');
        $functionName = 'process_premium';

        $params = [
            'policy_id' => $policy->stellar_policy_id, // Assumes this ID is stored after createPolicy
            'payer' => new Address($policy->user->stellar_account_id),
            'amount' => $policy->premium_amount,
            'asset' => new Address($policy->asset_address),
        ];

        // The result of process_premium is the payment_id (String)
        return $this->invokeContract($paymentContractId, $functionName, $params, $sourceAccountSecret);
    }

    /**
     * Submits a claim by invoking the InsuranceClaimContract.
     */
    public function submitClaim(array $claimData, string $sourceAccountSecret): string
    {
        $claimContractId = $this->getConfiguredContractId('claim_contract_id');
        $functionName = 'submit_claim';

        $params = [
            'claimant' => new Address($claimData['claimant_address']),
            'policy_id' => $claimData['policy_id'],
            'incident_type' => $claimData['incident_type'],
            'incident_date' => $claimData['incident_date'],
            'amount_claimed' => $claimData['amount_claimed'],
            'evidence_hash' => $claimData['evidence_hash'] ?? null,
        ];

        // The result of submit_claim is the claim_id (String)
        return $this->invokeContract($claimContractId, $functionName, $params, $sourceAccountSecret);
    }
}
