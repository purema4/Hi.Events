<?php

namespace Tests\Feature\Http\Actions\Orders;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Http\ResponseCodes;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\User;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\EventStatistics\EventStatisticsCancellationService;
use HiEvents\Services\Domain\EventStatistics\EventStatisticsRefundService;
use HiEvents\Services\Domain\Mail\SendOrderDetailsService;
use HiEvents\Services\Domain\Order\MarkOrderAsPaidService;
use HiEvents\Services\Domain\Order\OfflineOrderRefundService;
use HiEvents\Services\Domain\Order\OrderCancelService;
use HiEvents\Services\Domain\Wallet\WalletPassSyncDispatcher;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Values\MoneyValue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class OrdersUpdatedAtTest extends TestCase
{
    use DatabaseTransactions;

    private const OLD_TIMESTAMP = '2026-01-01 00:00:00';

    private User $user;

    private string $authToken;

    private int $accountId;

    private int $organizerId;

    private int $eventId;

    protected function setUp(): void
    {
        parent::setUp();

        AccountConfiguration::firstOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'Default',
            'is_system_default' => true,
            'application_fees' => ['percentage' => 1.5, 'fixed' => 0],
        ]);

        [$this->user, $this->authToken, $this->accountId] = $this->makeAuthenticatedUser();
        $this->organizerId = $this->makeOrganizer($this->accountId);
        $this->eventId = $this->makeEvent();
    }

    public function test_event_orders_can_be_filtered_by_updated_at(): void
    {
        $untouchedOrderId = $this->insertOrder();
        $refundedOrderId = $this->insertOrder();
        $since = now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z');

        $this->refundOffline($refundedOrderId, 5.0);

        $response = $this->getJson(
            "/events/{$this->eventId}/orders?filter_fields[updated_at][gte]={$since}",
            $this->authHeaders(),
        );

        $response->assertStatus(ResponseCodes::HTTP_OK);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$refundedOrderId], $ids);
        $this->assertNotContains($untouchedOrderId, $ids);
    }

    public function test_organizer_orders_can_be_filtered_by_updated_at(): void
    {
        $untouchedOrderId = $this->insertOrder();
        $refundedOrderId = $this->insertOrder();
        $since = now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z');

        $this->refundOffline($refundedOrderId, 5.0);

        $response = $this->getJson(
            "/organizers/{$this->organizerId}/orders?filter_fields[updated_at][gte]={$since}",
            $this->authHeaders(),
        );

        $response->assertStatus(ResponseCodes::HTTP_OK);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$refundedOrderId], $ids);
        $this->assertNotContains($untouchedOrderId, $ids);
    }

    public function test_organizer_orders_filters_are_applied_to_the_orders_table(): void
    {
        $cancelledOrderId = $this->insertOrder(status: OrderStatus::CANCELLED->name);
        $this->insertOrder();

        $response = $this->getJson(
            "/organizers/{$this->organizerId}/orders?filter_fields[status][eq]=CANCELLED",
            $this->authHeaders(),
        );

        $response->assertStatus(ResponseCodes::HTTP_OK);
        $this->assertSame([$cancelledOrderId], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_event_orders_can_be_sorted_by_updated_at(): void
    {
        [$first, $second, $third] = $this->insertOrdersWithUpdatedAtInADifferentOrderThanCreatedAt();

        $response = $this->getJson(
            "/events/{$this->eventId}/orders?sort_by=updated_at&sort_direction=desc",
            $this->authHeaders(),
        );

        $response->assertStatus(ResponseCodes::HTTP_OK);
        $this->assertSame([$first, $second, $third], collect($response->json('data'))->pluck('id')->all());
        $this->assertArrayHasKey('updated_at', $response->json('meta.allowed_sorts'));
        $this->assertSame('2026-01-05T00:00:00.000000Z', $response->json('data.0.updated_at'));
        $this->assertSame('2026-01-02T00:00:00.000000Z', $response->json('data.0.created_at'));
    }

    public function test_organizer_orders_can_be_sorted_by_updated_at(): void
    {
        [$first, $second, $third] = $this->insertOrdersWithUpdatedAtInADifferentOrderThanCreatedAt();

        $response = $this->getJson(
            "/organizers/{$this->organizerId}/orders?sort_by=updated_at&sort_direction=asc",
            $this->authHeaders(),
        );

        $response->assertStatus(ResponseCodes::HTTP_OK);
        $this->assertSame([$third, $second, $first], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_default_sort_is_still_newest_created_first(): void
    {
        [$first, $second, $third] = $this->insertOrdersWithUpdatedAtInADifferentOrderThanCreatedAt();

        $response = $this->getJson("/events/{$this->eventId}/orders", $this->authHeaders());

        $response->assertStatus(ResponseCodes::HTTP_OK);
        $this->assertSame([$third, $first, $second], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_orders_have_an_event_id_updated_at_index(): void
    {
        $definition = DB::table('pg_indexes')
            ->where('tablename', 'orders')
            ->where('indexname', 'orders_event_id_updated_at_index')
            ->value('indexdef');

        $this->assertNotNull($definition);
        $this->assertStringContainsString('(event_id, updated_at)', $definition);
    }

    public function test_offline_refund_moves_updated_at_forward(): void
    {
        $this->freezeSecond();
        $orderId = $this->insertOrder();

        $this->refundOffline($orderId, 5.0);

        $this->assertUpdatedAtIsNow($orderId);
        $this->assertSame(
            OrderRefundStatus::PARTIALLY_REFUNDED->name,
            DB::table('orders')->where('id', $orderId)->value('refund_status'),
        );
    }

    public function test_cancelling_an_order_moves_updated_at_forward(): void
    {
        $this->freezeSecond();
        $orderId = $this->insertOrder();

        $this->mock(EventStatisticsCancellationService::class)->shouldReceive('decrementForCancelledOrder')->once();
        $this->mock(DomainEventDispatcherService::class)->shouldReceive('dispatch')->once();
        $this->mock(WalletPassSyncDispatcher::class)->shouldReceive('queueOrderSync')->once();

        $this->app->make(OrderCancelService::class)->cancelOrder($this->findOrder($orderId));

        $this->assertUpdatedAtIsNow($orderId);
        $this->assertSame(OrderStatus::CANCELLED->name, DB::table('orders')->where('id', $orderId)->value('status'));
    }

    public function test_marking_an_order_as_paid_moves_updated_at_forward(): void
    {
        $this->freezeSecond();
        $this->makeEventSettings();
        $orderId = $this->insertOrder(
            status: OrderStatus::AWAITING_OFFLINE_PAYMENT->name,
            paymentStatus: OrderPaymentStatus::AWAITING_OFFLINE_PAYMENT->name,
        );

        Event::fake([OrderStatusChangedEvent::class]);
        $this->mock(DomainEventDispatcherService::class)->shouldReceive('dispatch')->once();
        $this->mock(SendOrderDetailsService::class)->shouldReceive('sendCustomerOrderSummary')->once();

        $this->app->make(MarkOrderAsPaidService::class)->markOrderAsPaid($orderId, $this->eventId);

        $this->assertUpdatedAtIsNow($orderId);
        $this->assertSame(OrderStatus::COMPLETED->name, DB::table('orders')->where('id', $orderId)->value('status'));
    }

    private function refundOffline(int $orderId, float $amount): void
    {
        $this->mock(EventStatisticsRefundService::class)->shouldReceive('updateForRefund')->once();
        $this->mock(DomainEventDispatcherService::class)->shouldReceive('dispatch')->once();

        $this->app->make(OfflineOrderRefundService::class)->refundOrder(
            $this->findOrder($orderId),
            MoneyValue::fromFloat($amount, 'USD'),
        );
    }

    private function findOrder(int $orderId): OrderDomainObject
    {
        return $this->app->make(OrderRepositoryInterface::class)->findById($orderId);
    }

    private function assertUpdatedAtIsNow(int $orderId): void
    {
        $updatedAt = DB::table('orders')->where('id', $orderId)->value('updated_at');

        $this->assertNotSame(self::OLD_TIMESTAMP, $updatedAt);
        $this->assertTrue(Carbon::parse($updatedAt)->equalTo(now()), "updated_at is {$updatedAt}");
    }

    private function insertOrdersWithUpdatedAtInADifferentOrderThanCreatedAt(): array
    {
        $second = $this->insertOrder(createdAt: '2026-01-01 00:00:00', updatedAt: '2026-01-04 00:00:00');
        $first = $this->insertOrder(createdAt: '2026-01-02 00:00:00', updatedAt: '2026-01-05 00:00:00');
        $third = $this->insertOrder(createdAt: '2026-01-03 00:00:00', updatedAt: '2026-01-03 00:00:00');

        return [$first, $second, $third];
    }

    private function insertOrder(
        string $status = 'COMPLETED',
        string $paymentStatus = 'PAYMENT_RECEIVED',
        string $createdAt = self::OLD_TIMESTAMP,
        string $updatedAt = self::OLD_TIMESTAMP,
    ): int {
        return DB::table('orders')->insertGetId([
            'short_id' => 'o_'.uniqid(),
            'event_id' => $this->eventId,
            'currency' => 'USD',
            'status' => $status,
            'payment_status' => $paymentStatus,
            'total_before_additions' => 20.00,
            'total_gross' => 20.00,
            'public_id' => 'O-'.uniqid(),
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);
    }

    private function makeAuthenticatedUser(): array
    {
        $user = User::factory()->withAccount()->create();
        $accountId = $user->accounts()->first()->id;

        $token = JWTAuth::claims(['account_id' => $accountId])->fromUser($user);

        return [$user, $token, $accountId];
    }

    private function makeOrganizer(int $accountId): int
    {
        return DB::table('organizers')->insertGetId([
            'account_id' => $accountId,
            'name' => 'Test Organizer',
            'email' => 'organizer-'.uniqid().'@test.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeEvent(): int
    {
        return DB::table('events')->insertGetId([
            'title' => 'Orders Updated At Test Event',
            'account_id' => $this->accountId,
            'user_id' => $this->user->id,
            'organizer_id' => $this->organizerId,
            'currency' => 'USD',
            'timezone' => 'UTC',
            'short_id' => 'ev_'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeEventSettings(): void
    {
        DB::table('event_settings')->insert([
            'event_id' => $this->eventId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function authHeaders(): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$this->authToken];
    }
}
