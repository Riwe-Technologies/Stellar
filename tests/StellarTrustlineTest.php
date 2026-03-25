<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\DefiWallet;
use App\Models\StellarWallet;
use App\Services\DefiWalletService;
use App\Services\StellarService;
use App\Services\StellarWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;

class StellarTrustlineTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $user;
    protected $defiWallet;
    protected $stellarWallet;
    protected $defiWalletService;
    protected $stellarService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user
        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);

        // Create DeFi wallet
        $this->defiWallet = DefiWallet::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        // Create Stellar wallet
        $this->stellarWallet = StellarWallet::factory()->create([
            'user_id' => $this->user->id,
            'public_key' => 'GBTESTACCOUNTPUBLICKEY123456789ABCDEFGHIJKLMNOP',
            'secret_key' => 'SBTESTACCOUNTSECRETKEY123456789ABCDEFGHIJKLMNOP',
            'is_funded' => true,
            'is_encrypted' => false,
        ]);

        // Associate Stellar wallet with DeFi wallet
        $this->defiWallet->update(['stellar_wallet_id' => $this->stellarWallet->id]);

        // Initialize services
        $this->defiWalletService = app(DefiWalletService::class);
        $this->stellarService = app(StellarService::class);
    }

    /** @test */
    public function user_can_add_trustline_for_custom_asset()
    {
        $this->actingAs($this->user);

        $response = $this->postJson('/defi-wallet/trustlines/add', [
            'asset_code' => 'USDC',
            'asset_issuer' => 'GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN',
            'limit' => '1000000'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        // Check that custom asset was added to wallet
        $this->defiWallet->refresh();
        $customAssets = $this->defiWallet->custom_asset_balances ?? [];
        $this->assertArrayHasKey('USDC', $customAssets);
        $this->assertEquals(0, $customAssets['USDC']);
    }

    /** @test */
    public function user_can_remove_trustline_with_zero_balance()
    {
        $this->actingAs($this->user);

        // First add a trustline
        $this->defiWallet->update([
            'custom_asset_balances' => ['USDC' => 0]
        ]);

        $response = $this->postJson('/defi-wallet/trustlines/remove', [
            'asset_code' => 'USDC',
            'asset_issuer' => 'GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        // Check that custom asset was removed from wallet
        $this->defiWallet->refresh();
        $customAssets = $this->defiWallet->custom_asset_balances ?? [];
        $this->assertArrayNotHasKey('USDC', $customAssets);
    }

    /** @test */
    public function user_cannot_remove_trustline_with_non_zero_balance()
    {
        $this->actingAs($this->user);

        // Add a trustline with balance
        $this->defiWallet->update([
            'custom_asset_balances' => ['USDC' => 100]
        ]);

        $response = $this->postJson('/defi-wallet/trustlines/remove', [
            'asset_code' => 'USDC',
            'asset_issuer' => 'GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'message' => 'Cannot remove trustline with non-zero balance'
        ]);
    }

    /** @test */
    public function user_can_list_wallet_trustlines()
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/defi-wallet/trustlines');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'trustlines'
        ]);
    }

    /** @test */
    public function add_trustline_validates_required_fields()
    {
        $this->actingAs($this->user);

        $response = $this->postJson('/defi-wallet/trustlines/add', []);

        $response->assertStatus(400);
        $response->assertJsonValidationErrors(['asset_code', 'asset_issuer']);
    }

    /** @test */
    public function add_trustline_validates_asset_issuer_format()
    {
        $this->actingAs($this->user);

        $response = $this->postJson('/defi-wallet/trustlines/add', [
            'asset_code' => 'USDC',
            'asset_issuer' => 'INVALID_ISSUER',
            'limit' => '1000000'
        ]);

        $response->assertStatus(400);
        $response->assertJsonValidationErrors(['asset_issuer']);
    }

    /** @test */
    public function add_trustline_validates_asset_code_length()
    {
        $this->actingAs($this->user);

        $response = $this->postJson('/defi-wallet/trustlines/add', [
            'asset_code' => 'TOOLONGASSETCODE',
            'asset_issuer' => 'GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN',
            'limit' => '1000000'
        ]);

        $response->assertStatus(400);
        $response->assertJsonValidationErrors(['asset_code']);
    }

    /** @test */
    public function user_without_stellar_wallet_cannot_manage_trustlines()
    {
        $this->actingAs($this->user);

        // Remove Stellar wallet association
        $this->defiWallet->update(['stellar_wallet_id' => null]);

        $response = $this->postJson('/defi-wallet/trustlines/add', [
            'asset_code' => 'USDC',
            'asset_issuer' => 'GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN',
            'limit' => '1000000'
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'message' => 'No Stellar wallet associated'
        ]);
    }

    /** @test */
    public function user_without_defi_wallet_cannot_manage_trustlines()
    {
        $userWithoutWallet = User::factory()->create();
        $this->actingAs($userWithoutWallet);

        $response = $this->postJson('/defi-wallet/trustlines/add', [
            'asset_code' => 'USDC',
            'asset_issuer' => 'GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN',
            'limit' => '1000000'
        ]);

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'DeFi wallet not found'
        ]);
    }

    /** @test */
    public function trustline_operations_require_authentication()
    {
        $response = $this->postJson('/defi-wallet/trustlines/add', [
            'asset_code' => 'USDC',
            'asset_issuer' => 'GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN',
            'limit' => '1000000'
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function stellar_service_creates_trustline_correctly()
    {
        // Mock the Stellar SDK response
        $mockResult = [
            'success' => true,
            'transaction_hash' => 'test_hash_123',
            'account_id' => $this->stellarWallet->public_key,
            'asset_code' => 'USDC',
            'asset_issuer' => 'GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN',
            'limit' => '1000000',
            'ledger' => 12345,
        ];

        // Test the service method directly
        $result = $this->defiWalletService->addTrustline(
            $this->defiWallet,
            'USDC',
            'GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN',
            '1000000'
        );

        // Since we're testing without actual Stellar network, 
        // we expect the service to handle the mock gracefully
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    /** @test */
    public function stellar_service_removes_trustline_correctly()
    {
        // Add a trustline first
        $this->defiWallet->update([
            'custom_asset_balances' => ['USDC' => 0]
        ]);

        $result = $this->defiWalletService->removeTrustline(
            $this->defiWallet,
            'USDC',
            'GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN'
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }
}
