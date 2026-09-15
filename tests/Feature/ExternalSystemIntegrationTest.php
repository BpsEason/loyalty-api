<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ExternalSystemIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // 建立測試用 Tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'domain' => 'test.test',
        ]);

        // 建立測試用 User
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenant->id,
            'role' => 'user',
        ]);

        // 建立測試用 Customer
        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
    }

    #[Test]
    public function external_system_can_use_x_tenant_id_header_to_specify_tenant(): void
    {
        $token = JWTAuth::fromUser($this->user);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson('/api/v1/customers');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    #[Test]
    public function external_system_cannot_use_invalid_tenant_id(): void
    {
        $token = JWTAuth::fromUser($this->user);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => '99999', // 不存在的租戶
        ])->getJson('/api/v1/customers');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid tenant identifier.',
            ]);
    }

    #[Test]
    public function can_create_point_transaction_with_reference(): void
    {
        $token = JWTAuth::fromUser($this->user);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson("/api/v1/customers/{$this->customer->id}/point-transactions", [
            'type' => 'earn',
            'amount' => 100,
            'description' => 'Purchase reward',
            'reference' => 'ORDER-12345', // 外部訂單編號
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Point transaction created successfully',
            ]);
    }

    #[Test]
    public function idempotency_key_prevents_duplicate_transactions(): void
    {
        $token = JWTAuth::fromUser($this->user);
        $idempotencyKey = 'unique-key-12345';

        // 第一次請求應該成功建立交易
        $firstResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customer->id}/point-transactions", [
            'type' => 'earn',
            'amount' => 100,
            'description' => 'Purchase reward',
            'reference' => 'ORDER-12345',
        ]);

        $firstResponse->assertStatus(201);
        $transactionId = $firstResponse->json('data.id');

        // 第二次使用相同冪等性金鑰的請求應該返回相同的交易
        $secondResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customer->id}/point-transactions", [
            'type' => 'earn',
            'amount' => 100,
            'description' => 'Purchase reward',
            'reference' => 'ORDER-12345',
        ]);

        $secondResponse->assertStatus(200)
            ->assertJson([
                'message' => 'Point transaction retrieved (idempotent)',
            ]);
        $this->assertEquals($transactionId, $secondResponse->json('data.id'));
    }
}
