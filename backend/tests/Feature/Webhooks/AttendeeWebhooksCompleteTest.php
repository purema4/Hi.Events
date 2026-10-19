<?php

namespace Tests\Feature\Webhooks;

use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Http\ResponseCodes;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\User;
use HiEvents\Services\Application\Handlers\Attendee\DTO\PartialEditAttendeeDTO;
use HiEvents\Services\Application\Handlers\Attendee\PartialEditAttendeeHandler;
use HiEvents\Services\Domain\Attendee\SendAttendeeTicketService;
use HiEvents\Services\Domain\EventStatistics\EventStatisticsCancellationService;
use HiEvents\Services\Domain\EventStatistics\EventStatisticsReactivationService;
use HiEvents\Services\Domain\Mail\SendOrderDetailsService;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Domain\Wallet\WalletPassSyncDispatcher;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\AttendeeEvent;
use HiEvents\Services\Infrastructure\DomainEvents\Events\BaseDomainEvent;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class AttendeeWebhooksCompleteTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private string $authToken;

    private int $accountId;

    private int $organizerId;

    private int $eventId;

    private int $productId;

    private int $productPriceId;

    /** @var BaseDomainEvent[] */
    private array $dispatched = [];

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
        $this->organizerId = $this->makeOrganizer();
        $this->eventId = $this->makeEvent();
        $this->makeEventSettings();
        [$this->productId, $this->productPriceId] = $this->makeProductAndPrice();

        $this->mock(ProductQuantityUpdateService::class)->shouldIgnoreMissing();
        $this->mock(EventStatisticsCancellationService::class)->shouldIgnoreMissing();
        $this->mock(EventStatisticsReactivationService::class)->shouldIgnoreMissing();
        $this->mock(WalletPassSyncDispatcher::class)->shouldIgnoreMissing();
    }

    public function test_reactivating_a_cancelled_attendee_emits_attendee_updated(): void
    {
        $this->recordDomainEvents();
        $attendeeId = $this->insertAttendee($this->insertOrder(), AttendeeStatus::CANCELLED);

        $this->patchAttendee($attendeeId, ['status' => AttendeeStatus::ACTIVE->name])
            ->assertStatus(ResponseCodes::HTTP_OK);

        $this->assertDispatchedOnly(AttendeeEvent::class, DomainEventType::ATTENDEE_UPDATED, $attendeeId);
    }

    public function test_renaming_an_attendee_emits_attendee_updated(): void
    {
        $this->recordDomainEvents();
        $attendeeId = $this->insertAttendee($this->insertOrder());

        $this->patchAttendee($attendeeId, ['first_name' => 'Renamed'])
            ->assertStatus(ResponseCodes::HTTP_OK);

        $this->assertDispatchedOnly(AttendeeEvent::class, DomainEventType::ATTENDEE_UPDATED, $attendeeId);
    }

    public function test_changing_an_attendee_email_emits_attendee_updated(): void
    {
        $this->recordDomainEvents();
        $attendeeId = $this->insertAttendee($this->insertOrder());

        $this->patchAttendee($attendeeId, ['email' => 'new-address@example.test'])
            ->assertStatus(ResponseCodes::HTTP_OK);

        $this->assertDispatchedOnly(AttendeeEvent::class, DomainEventType::ATTENDEE_UPDATED, $attendeeId);
    }

    public function test_saving_an_attendee_without_any_change_emits_nothing(): void
    {
        $this->recordDomainEvents();
        $attendeeId = $this->insertAttendee($this->insertOrder());

        $this->patchAttendee($attendeeId, [
            'first_name' => 'Original',
            'status' => AttendeeStatus::ACTIVE->name,
        ])->assertStatus(ResponseCodes::HTTP_OK);

        $this->assertSame([], $this->dispatched);
    }

    public function test_cancelling_an_attendee_emits_attendee_cancelled_after_the_transaction_is_committed(): void
    {
        $attendeeId = $this->insertAttendee($this->insertOrder());
        $outsideTransactionLevel = DB::transactionLevel();
        $seenByListener = [];

        Event::listen(AttendeeEvent::class, static function (AttendeeEvent $event) use (&$seenByListener): void {
            $seenByListener[] = [
                'type' => $event->type,
                'status' => DB::table('attendees')->where('id', $event->attendeeId)->value('status'),
                'transaction_level' => DB::transactionLevel(),
            ];
        });

        $this->patchAttendee($attendeeId, ['status' => AttendeeStatus::CANCELLED->name])
            ->assertStatus(ResponseCodes::HTTP_OK);

        $this->assertSame([[
            'type' => DomainEventType::ATTENDEE_CANCELLED,
            'status' => AttendeeStatus::CANCELLED->name,
            'transaction_level' => $outsideTransactionLevel,
        ]], $seenByListener);
    }

    public function test_an_edit_whose_transaction_fails_emits_nothing(): void
    {
        $this->recordDomainEvents();
        $attendeeId = $this->insertAttendee($this->insertOrder());

        try {
            $this->app->make(PartialEditAttendeeHandler::class)->handle(new PartialEditAttendeeDTO(
                attendee_id: $attendeeId,
                event_id: $this->eventId,
                first_name: null,
                last_name: str_repeat('x', 300),
                email: null,
                status: AttendeeStatus::CANCELLED->name,
            ));
            $this->fail('The update was expected to fail.');
        } catch (QueryException) {
        }

        $this->assertSame([], $this->dispatched);
        $this->assertSame(
            AttendeeStatus::ACTIVE->name,
            DB::table('attendees')->where('id', $attendeeId)->value('status'),
        );
    }

    public function test_self_service_attendee_edit_emits_attendee_updated(): void
    {
        $this->recordDomainEvents();
        $this->fakeSelfServiceMails();
        $orderId = $this->insertOrder();
        $attendeeId = $this->insertAttendee($orderId);

        $this->patchJson($this->selfServiceAttendeeUrl($orderId, $attendeeId), ['first_name' => 'Renamed'])
            ->assertStatus(ResponseCodes::HTTP_OK);

        $this->assertSame('Renamed', DB::table('attendees')->where('id', $attendeeId)->value('first_name'));
        $this->assertDispatchedOnly(AttendeeEvent::class, DomainEventType::ATTENDEE_UPDATED, $attendeeId);
    }

    public function test_self_service_attendee_email_change_emits_attendee_updated(): void
    {
        $this->recordDomainEvents();
        $this->fakeSelfServiceMails();
        $orderId = $this->insertOrder();
        $attendeeId = $this->insertAttendee($orderId);

        $this->patchJson($this->selfServiceAttendeeUrl($orderId, $attendeeId), ['email' => 'new-address@example.test'])
            ->assertStatus(ResponseCodes::HTTP_OK)
            ->assertJsonStructure(['new_short_id']);

        $this->assertDispatchedOnly(AttendeeEvent::class, DomainEventType::ATTENDEE_UPDATED, $attendeeId);
    }

    public function test_self_service_attendee_edit_without_any_change_emits_nothing(): void
    {
        $this->recordDomainEvents();
        $this->fakeSelfServiceMails();
        $orderId = $this->insertOrder();
        $attendeeId = $this->insertAttendee($orderId);

        $this->patchJson($this->selfServiceAttendeeUrl($orderId, $attendeeId), ['first_name' => 'Original'])
            ->assertStatus(ResponseCodes::HTTP_OK);

        $this->assertSame([], $this->dispatched);
    }

    public function test_self_service_order_edit_emits_order_updated(): void
    {
        $this->recordDomainEvents();
        $this->fakeSelfServiceMails();
        $orderId = $this->insertOrder();

        $this->patchJson($this->selfServiceOrderUrl($orderId), ['last_name' => 'Renamed'])
            ->assertStatus(ResponseCodes::HTTP_OK);

        $this->assertSame('Renamed', DB::table('orders')->where('id', $orderId)->value('last_name'));
        $this->assertDispatchedOnly(OrderEvent::class, DomainEventType::ORDER_UPDATED, $orderId);
    }

    public function test_self_service_order_email_change_emits_order_updated(): void
    {
        $this->recordDomainEvents();
        $this->fakeSelfServiceMails();
        $orderId = $this->insertOrder();

        $this->patchJson($this->selfServiceOrderUrl($orderId), ['email' => 'new-buyer@example.test'])
            ->assertStatus(ResponseCodes::HTTP_OK)
            ->assertJsonStructure(['new_short_id']);

        $this->assertDispatchedOnly(OrderEvent::class, DomainEventType::ORDER_UPDATED, $orderId);
    }

    public function test_self_service_order_edit_without_any_change_emits_nothing(): void
    {
        $this->recordDomainEvents();
        $this->fakeSelfServiceMails();
        $orderId = $this->insertOrder();

        $this->patchJson($this->selfServiceOrderUrl($orderId), ['last_name' => 'Buyer'])
            ->assertStatus(ResponseCodes::HTTP_OK);

        $this->assertSame([], $this->dispatched);
    }

    public function test_editing_an_attendee_answer_emits_attendee_updated(): void
    {
        $this->recordDomainEvents();
        $orderId = $this->insertOrder();
        $attendeeId = $this->insertAttendee($orderId);
        [$questionId, $answerId] = $this->insertAnswer('PRODUCT', $orderId, $attendeeId, 'M');

        $this->putAnswer($questionId, $answerId, 'L')->assertStatus(ResponseCodes::HTTP_NO_CONTENT);

        $this->assertDispatchedOnly(AttendeeEvent::class, DomainEventType::ATTENDEE_UPDATED, $attendeeId);
    }

    public function test_editing_an_order_answer_emits_order_updated(): void
    {
        $this->recordDomainEvents();
        $orderId = $this->insertOrder();
        [$questionId, $answerId] = $this->insertAnswer('ORDER', $orderId, null, 'Acme');

        $this->putAnswer($questionId, $answerId, 'Acme Inc.')->assertStatus(ResponseCodes::HTTP_NO_CONTENT);

        $this->assertDispatchedOnly(OrderEvent::class, DomainEventType::ORDER_UPDATED, $orderId);
    }

    public function test_saving_an_answer_without_any_change_emits_nothing(): void
    {
        $this->recordDomainEvents();
        $orderId = $this->insertOrder();
        [$questionId, $answerId] = $this->insertAnswer('PRODUCT', $orderId, $this->insertAttendee($orderId), 'M');

        $this->putAnswer($questionId, $answerId, 'M')->assertStatus(ResponseCodes::HTTP_NO_CONTENT);

        $this->assertSame([], $this->dispatched);
    }

    private function putAnswer(int $questionId, int $answerId, string $answer): TestResponse
    {
        return $this->putJson(
            "/events/{$this->eventId}/questions/{$questionId}/answers/{$answerId}",
            ['answer' => $answer],
            $this->authHeaders(),
        );
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function insertAnswer(string $belongsTo, int $orderId, ?int $attendeeId, string $answer): array
    {
        $questionId = DB::table('questions')->insertGetId([
            'event_id' => $this->eventId,
            'title' => 'T-shirt size',
            'type' => 'SINGLE_LINE_TEXT',
            'belongs_to' => $belongsTo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $answerId = DB::table('question_answers')->insertGetId([
            'question_id' => $questionId,
            'order_id' => $orderId,
            'attendee_id' => $attendeeId,
            'product_id' => $attendeeId === null ? null : $this->productId,
            'answer' => json_encode($answer),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$questionId, $answerId];
    }

    private function recordDomainEvents(): void
    {
        $dispatcher = Mockery::mock(DomainEventDispatcherService::class);
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function (BaseDomainEvent $event): void {
            $this->dispatched[] = $event;
        });

        $this->app->instance(DomainEventDispatcherService::class, $dispatcher);
    }

    private function fakeSelfServiceMails(): void
    {
        $this->mock(SendAttendeeTicketService::class)->shouldIgnoreMissing();
        $this->mock(SendOrderDetailsService::class)->shouldIgnoreMissing();
        Mail::fake();
    }

    private function assertDispatchedOnly(string $eventClass, DomainEventType $type, int $id): void
    {
        $this->assertCount(1, $this->dispatched, 'Expected exactly one domain event.');
        $event = $this->dispatched[0];
        $this->assertInstanceOf($eventClass, $event);
        $this->assertSame($type, $event->type);
        $this->assertSame($id, $event instanceof AttendeeEvent ? $event->attendeeId : $event->orderId);
    }

    private function patchAttendee(int $attendeeId, array $data): TestResponse
    {
        return $this->patchJson("/events/{$this->eventId}/attendees/{$attendeeId}", $data, $this->authHeaders());
    }

    private function selfServiceAttendeeUrl(int $orderId, int $attendeeId): string
    {
        $orderShortId = DB::table('orders')->where('id', $orderId)->value('short_id');
        $attendeeShortId = DB::table('attendees')->where('id', $attendeeId)->value('short_id');

        return "/public/events/{$this->eventId}/order/{$orderShortId}/attendees/{$attendeeShortId}";
    }

    private function selfServiceOrderUrl(int $orderId): string
    {
        $orderShortId = DB::table('orders')->where('id', $orderId)->value('short_id');

        return "/public/events/{$this->eventId}/order/{$orderShortId}";
    }

    private function insertOrder(): int
    {
        return DB::table('orders')->insertGetId([
            'short_id' => 'o_'.uniqid(),
            'event_id' => $this->eventId,
            'currency' => 'USD',
            'status' => 'COMPLETED',
            'payment_status' => 'PAYMENT_RECEIVED',
            'first_name' => 'Original',
            'last_name' => 'Buyer',
            'email' => 'buyer-'.uniqid().'@example.test',
            'total_before_additions' => 20.00,
            'total_gross' => 20.00,
            'public_id' => 'O-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAttendee(int $orderId, AttendeeStatus $status = AttendeeStatus::ACTIVE): int
    {
        return DB::table('attendees')->insertGetId([
            'short_id' => 'a_'.uniqid(),
            'first_name' => 'Original',
            'last_name' => 'Attendee',
            'email' => 'attendee-'.uniqid().'@example.test',
            'order_id' => $orderId,
            'product_id' => $this->productId,
            'product_price_id' => $this->productPriceId,
            'event_id' => $this->eventId,
            'public_id' => 'A-'.uniqid(),
            'status' => $status->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeProductAndPrice(): array
    {
        $productId = DB::table('products')->insertGetId([
            'title' => 'General admission',
            'event_id' => $this->eventId,
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

        return [$productId, $productPriceId];
    }

    private function makeAuthenticatedUser(): array
    {
        $user = User::factory()->withAccount()->create();
        $accountId = $user->accounts()->first()->id;

        $token = JWTAuth::claims(['account_id' => $accountId])->fromUser($user);

        return [$user, $token, $accountId];
    }

    private function makeOrganizer(): int
    {
        return DB::table('organizers')->insertGetId([
            'account_id' => $this->accountId,
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
            'title' => 'Attendee Webhooks Test Event',
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
