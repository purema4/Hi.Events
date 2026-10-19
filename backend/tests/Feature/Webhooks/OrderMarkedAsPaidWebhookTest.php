<?php

namespace Tests\Feature\Webhooks;

use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\WebhookStatus;
use HiEvents\Jobs\Webhook\SecureCallWebhookJob;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\Webhook\WebhookDispatchService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ApiKeys\InteractsWithApiKeys;
use Tests\TestCase;

class OrderMarkedAsPaidWebhookTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithApiKeys;

    public function test_marking_an_order_as_paid_sends_attendee_updated_for_each_attendee(): void
    {
        config(['queue.default' => 'database', 'queue.webhook_queue_name' => 'webhook-queue']);
        Queue::fake();

        $this->seedAccountConfiguration();
        [$admin, $accountId] = $this->makeAccountWithAdmin();
        $eventId = $this->makeEvent($accountId, $admin->id, $this->makeOrganizer($accountId));

        DB::table('webhooks')->insert([
            'url' => 'https://hooks.example.com/hi-events',
            'event_types' => json_encode([
                DomainEventType::ORDER_MARKED_AS_PAID->value,
                DomainEventType::ATTENDEE_UPDATED->value,
            ]),
            'status' => WebhookStatus::ENABLED->name,
            'secret' => 'test-webhook-secret',
            'user_id' => $admin->id,
            'event_id' => $eventId,
            'account_id' => $accountId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = $this->makeOrder($eventId);
        $firstAttendeeId = $this->makeAttendee($eventId, $orderId);
        $secondAttendeeId = $this->makeAttendee($eventId, $orderId);

        $this->app->make(WebhookDispatchService::class)
            ->dispatchOrderWebhook(DomainEventType::ORDER_MARKED_AS_PAID, $orderId);

        $sent = Queue::pushed(SecureCallWebhookJob::class)
            ->map(fn (SecureCallWebhookJob $job) => [$job->payload['event_type'], $job->payload['payload']['id']])
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing([
            [DomainEventType::ATTENDEE_UPDATED->value, $firstAttendeeId],
            [DomainEventType::ATTENDEE_UPDATED->value, $secondAttendeeId],
            [DomainEventType::ORDER_MARKED_AS_PAID->value, $orderId],
        ], $sent);
    }

    private function makeOrder(int $eventId): int
    {
        return DB::table('orders')->insertGetId([
            'short_id' => 'o_'.uniqid(),
            'event_id' => $eventId,
            'currency' => 'USD',
            'status' => OrderStatus::COMPLETED->name,
            'payment_status' => 'PAYMENT_RECEIVED',
            'first_name' => 'Test',
            'last_name' => 'Buyer',
            'email' => 'buyer-'.uniqid().'@example.test',
            'total_before_additions' => 20.00,
            'total_gross' => 20.00,
            'public_id' => 'O-'.strtoupper(substr(uniqid(), -7)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeAttendee(int $eventId, int $orderId): int
    {
        $productId = DB::table('products')->insertGetId([
            'title' => 'General admission',
            'event_id' => $eventId,
            'product_type' => 'TICKET',
            'type' => 'PAID',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productPriceId = DB::table('product_prices')->insertGetId([
            'product_id' => $productId,
            'price' => 20.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('attendees')->insertGetId([
            'short_id' => 'a_'.uniqid(),
            'first_name' => 'Test',
            'last_name' => 'Attendee',
            'email' => 'attendee-'.uniqid().'@example.test',
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_price_id' => $productPriceId,
            'event_id' => $eventId,
            'public_id' => 'A-'.strtoupper(substr(uniqid(), -7)),
            'status' => AttendeeStatus::ACTIVE->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
