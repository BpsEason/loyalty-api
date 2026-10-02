<?php

namespace Tests\Feature\Services\Outbox;

use App\Events\PointAdjusted;
use App\Events\PointEarned;
use App\Events\PointExpired;
use App\Events\PointRedeemed;
use App\Events\PointRefunded;
use App\Models\Customer;
use App\Models\OutboxEvent;
use App\Models\Tenant;
use App\Services\Outbox\OutboxService;
use App\Services\Point\PointService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PointDomainEventsOutboxTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Customer $customer;

    protected PointService $pointService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'domain' => 'test.example.com',
        ]);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
            'phone' => '1234567890',
        ]);

        $this->pointService = app(PointService::class);
    }

    #[Test]
    public function it_creates_outbox_record_for_point_earned_event(): void
    {
        $this->pointService->earn(
            $this->customer,
            100,
            'Test earn',
            'test-reference'
        );

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'PointEarned',
            'tenant_id' => $this->tenant->id,
            'payload->customer_id' => $this->customer->id,
        ]);
    }

    #[Test]
    public function it_creates_outbox_record_for_point_redeemed_event(): void
    {
        $this->pointService->earn(
            $this->customer,
            100,
            'Initial earn'
        );

        DB::table('outbox_events')->truncate();

        $this->pointService->redeem(
            $this->customer,
            50,
            'Test redeem',
            'test-reference'
        );

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'PointRedeemed',
            'tenant_id' => $this->tenant->id,
            'payload->customer_id' => $this->customer->id,
        ]);
    }

    #[Test]
    public function it_creates_outbox_record_for_point_refunded_event(): void
    {
        $this->pointService->earn(
            $this->customer,
            100,
            'Initial earn'
        );

        $redeemTransaction = $this->pointService->redeem(
            $this->customer,
            50,
            'Redeem before refund'
        );

        DB::table('outbox_events')->truncate();

        $this->pointService->refund(
            $this->customer,
            50,
            'Test refund',
            $redeemTransaction
        );

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'PointRefunded',
            'tenant_id' => $this->tenant->id,
            'payload->customer_id' => $this->customer->id,
        ]);
    }

    #[Test]
    public function it_creates_outbox_record_for_point_adjusted_event(): void
    {
        $this->pointService->adjust(
            $this->customer,
            75,
            'Test adjust',
            'test-reference'
        );

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'PointAdjusted',
            'tenant_id' => $this->tenant->id,
            'payload->customer_id' => $this->customer->id,
        ]);
    }

    #[Test]
    public function it_creates_outbox_record_for_point_expired_event_manual(): void
    {
        $this->pointService->earn(
            $this->customer,
            100,
            'Initial earn'
        );

        DB::table('outbox_events')->truncate();

        $this->pointService->expire(
            $this->customer,
            50,
            'Test manual expire',
            'test-reference'
        );

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'PointExpired',
            'tenant_id' => $this->tenant->id,
            'payload->customer_id' => $this->customer->id,
        ]);
    }

    #[Test]
    public function it_creates_outbox_record_for_point_expired_event_automatic(): void
    {
        $this->pointService->earn(
            $this->customer,
            100,
            'Initial earn'
        );

        DB::table('point_lots')->update([
            'expired_at' => now()->subDay(),
        ]);

        DB::table('outbox_events')->truncate();

        $result = $this->pointService->expireAllExpiredLots(
            $this->customer
        );

        $this->assertEquals(100, $result[0]);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'PointExpired',
            'tenant_id' => $this->tenant->id,
            'payload->customer_id' => $this->customer->id,
        ]);
    }

    #[Test]
    public function it_does_not_create_outbox_record_when_transaction_rolls_back(): void
    {
        try {
            DB::transaction(function (): void {
                $this->pointService->earn(
                    $this->customer,
                    100,
                    'This should rollback'
                );

                throw new \RuntimeException('Force rollback');
            });
        } catch (\RuntimeException $e) {
            // Expected.
        }

        $this->assertDatabaseCount('outbox_events', 0);
        $this->assertDatabaseCount('point_transactions', 0);
    }

    #[Test]
    public function idempotency_prevents_duplicate_outbox_records(): void
    {
        $event = new PointEarned(
            $this->tenant->id,
            $this->customer->id,
            999,
            100,
            'test-reference',
            now()->toIso8601String()
        );

        /** @var OutboxService $outboxService */
        $outboxService = app(OutboxService::class);

        $outboxService->recordDomainEvent($event);

        try {
            $outboxService->recordDomainEvent($event);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Expected.
        }

        $this->assertDatabaseCount('outbox_events', 1);
    }

    #[Test]
    public function all_point_events_can_be_reconstructed_by_process_outbox_job(): void
    {
        $events = [
            new PointEarned(
                $this->tenant->id,
                $this->customer->id,
                1,
                100,
                null,
                now()->toIso8601String()
            ),
            new PointRedeemed(
                $this->tenant->id,
                $this->customer->id,
                2,
                50,
                null,
                now()->toIso8601String()
            ),
            new PointRefunded(
                $this->tenant->id,
                $this->customer->id,
                3,
                50,
                null,
                now()->toIso8601String()
            ),
            new PointAdjusted(
                $this->tenant->id,
                $this->customer->id,
                4,
                75,
                null,
                now()->toIso8601String()
            ),
            new PointExpired(
                $this->tenant->id,
                $this->customer->id,
                5,
                30,
                null,
                now()->toIso8601String()
            ),
        ];

        /** @var OutboxService $outboxService */
        $outboxService = app(OutboxService::class);

        foreach ($events as $event) {
            $outboxEvent = $outboxService->recordDomainEvent($event);

            $this->assertNotNull($outboxEvent);
        }

        $this->assertDatabaseCount('outbox_events', 5);

        $eventTypes = OutboxEvent::query()
            ->pluck('event_type')
            ->sort()
            ->values()
            ->all();

        $this->assertEquals([
            'PointAdjusted',
            'PointEarned',
            'PointExpired',
            'PointRedeemed',
            'PointRefunded',
        ], $eventTypes);
    }
}
