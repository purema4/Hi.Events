<?php

namespace Tests\Feature\CheckInLists;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Http\ResponseCodes;
use HiEvents\Models\User;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\BaseDomainEvent;
use HiEvents\Services\Infrastructure\DomainEvents\Events\CheckinEvent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Feature\ApiKeys\InteractsWithApiKeys;
use Tests\TestCase;

class AuthenticatedCheckInTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithApiKeys;

    private const SCAN_ABILITIES = ['attendees:read', 'check-in-lists:read', 'check-ins:write'];

    private User $admin;

    private int $accountId;

    private int $eventId;

    private int $productId;

    private int $productPriceId;

    private int $checkInListId;

    /** @var BaseDomainEvent[] */
    private array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccountConfiguration();

        [$this->admin, $this->accountId] = $this->makeAccountWithAdmin();
        $this->eventId = $this->makeEvent($this->accountId, $this->admin->id, $this->makeOrganizer($this->accountId));
        $this->makeEventSettings($this->eventId);
        [$this->productId, $this->productPriceId] = $this->makeProductAndPrice($this->eventId);
        $this->checkInListId = $this->makeCheckInList($this->eventId);

        $this->recordDomainEvents();
    }

    public function test_an_organizer_session_checks_an_attendee_in(): void
    {
        $organizer = $this->addUserToAccount($this->accountId, Role::ORGANIZER);
        $attendee = $this->makeAttendee();

        $response = $this->checkIn($this->jwtFor($organizer, $this->accountId), $attendee['public_id']);

        $response->assertStatus(ResponseCodes::HTTP_OK);
        $checkInId = $this->assertCheckedIn($response, $attendee['id']);
        $this->assertCheckInEvents([[DomainEventType::CHECKIN_CREATED, $checkInId]]);
    }

    public function test_a_key_with_check_ins_write_checks_an_attendee_in(): void
    {
        $attendee = $this->makeAttendee();

        $response = $this->checkIn($this->scanKey(), $attendee['public_id']);

        $response->assertStatus(ResponseCodes::HTTP_OK);
        $response->assertJsonStructure(['data' => [['id', 'short_id', 'check_in_list_id', 'attendee_id', 'checked_in_at']]]);
        $this->assertStringStartsWith('ci_', $response->json('data.0.short_id'));
        $checkInId = $this->assertCheckedIn($response, $attendee['id']);
        $this->assertCheckInEvents([[DomainEventType::CHECKIN_CREATED, $checkInId]]);
    }

    public function test_a_key_without_check_ins_write_is_refused(): void
    {
        $attendee = $this->makeAttendee();
        $key = $this->makeApiKey($this->admin, $this->accountId, ['attendees:read', 'check-in-lists:read']);

        $this->checkIn($key, $attendee['public_id'])
            ->assertStatus(ResponseCodes::HTTP_FORBIDDEN)
            ->assertJsonPath('error_code', 'API_KEY_SCOPE_DENIED')
            ->assertJsonPath('ability', 'check-ins:write');

        $this->assertSame(0, DB::table('attendee_check_ins')->count());
    }

    public function test_a_key_can_never_mark_an_order_as_paid(): void
    {
        DB::table('event_settings')->where('event_id', $this->eventId)
            ->update(['allow_orders_awaiting_offline_payment_to_check_in' => true]);
        $orderId = $this->makeOrder(OrderStatus::AWAITING_OFFLINE_PAYMENT);
        $attendee = $this->makeAttendee(orderId: $orderId, status: AttendeeStatus::AWAITING_PAYMENT);

        $this->checkIn($this->scanKey(), $attendee['public_id'], 'check-in-and-mark-order-as-paid')
            ->assertStatus(ResponseCodes::HTTP_FORBIDDEN)
            ->assertJsonPath('error_code', 'API_KEY_SCOPE_DENIED');

        $this->assertSame(OrderStatus::AWAITING_OFFLINE_PAYMENT->name, DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame(AttendeeStatus::AWAITING_PAYMENT->name, DB::table('attendees')->where('id', $attendee['id'])->value('status'));
        $this->assertSame(0, DB::table('attendee_check_ins')->count());
        $this->assertCheckInEvents([]);
    }

    public function test_a_list_of_another_account_is_forbidden(): void
    {
        [$otherAdmin, $otherAccountId] = $this->makeAccountWithAdmin();
        $otherEventId = $this->makeEvent($otherAccountId, $otherAdmin->id, $this->makeOrganizer($otherAccountId));
        $otherListId = $this->makeCheckInList($otherEventId);

        $response = $this->postJson(
            "/events/{$otherEventId}/check-in-lists/{$otherListId}/check-ins",
            ['attendees' => [['public_id' => 'A-ANYCODE', 'action' => 'check-in']]],
            $this->bearer($this->scanKey()),
        );

        $response->assertStatus(ResponseCodes::HTTP_FORBIDDEN);
        $this->assertNull($response->json('error_code'));
    }

    public function test_a_list_of_another_event_is_not_found(): void
    {
        $otherEventId = $this->makeEvent($this->accountId, $this->admin->id, $this->makeOrganizer($this->accountId));
        $otherListId = $this->makeCheckInList($otherEventId);
        $attendee = $this->makeAttendee();

        $this->postJson(
            "/events/{$this->eventId}/check-in-lists/{$otherListId}/check-ins",
            ['attendees' => [['public_id' => $attendee['public_id'], 'action' => 'check-in']]],
            $this->bearer($this->scanKey()),
        )->assertStatus(ResponseCodes::HTTP_NOT_FOUND);

        $this->assertSame(0, DB::table('attendee_check_ins')->count());
    }

    public function test_an_attendee_already_checked_in_returns_the_existing_check_in_without_a_new_webhook(): void
    {
        $attendee = $this->makeAttendee();
        $existingId = $this->makeCheckIn($attendee['id'], $this->checkInListId);

        $response = $this->checkIn($this->scanKey(), $attendee['public_id']);

        $response->assertStatus(ResponseCodes::HTTP_OK);
        $response->assertJsonPath('data.0.id', $existingId);
        $this->assertNotEmpty($response->json('errors.'.$attendee['public_id']));
        $this->assertCheckInEvents([]);
    }

    public function test_the_public_route_no_longer_resends_a_webhook_for_an_attendee_already_checked_in(): void
    {
        $attendee = $this->makeAttendee();
        $existingId = $this->makeCheckIn($attendee['id'], $this->checkInListId);

        $response = $this->postJson("/public/check-in-lists/{$this->listShortId()}/check-ins", [
            'attendees' => [['public_id' => $attendee['public_id'], 'action' => 'check-in']],
        ]);

        $response->assertStatus(ResponseCodes::HTTP_OK);
        $response->assertJsonPath('data.0.id', $existingId);
        $this->assertNotEmpty($response->json('errors.'.$attendee['public_id']));
        $this->assertCheckInEvents([]);
    }

    public function test_a_list_that_is_not_active_yet_is_refused_with_its_code(): void
    {
        DB::table('check_in_lists')->where('id', $this->checkInListId)->update(['activates_at' => now()->addDay()]);

        $this->assertRefusedWithCode($this->makeAttendee()['public_id'], 'CHECK_IN_LIST_NOT_ACTIVE');
    }

    public function test_an_expired_list_is_refused_with_its_code(): void
    {
        DB::table('check_in_lists')->where('id', $this->checkInListId)->update(['expires_at' => now()->subDay()]);

        $this->assertRefusedWithCode($this->makeAttendee()['public_id'], 'CHECK_IN_LIST_EXPIRED');
    }

    public function test_a_product_that_is_not_on_the_list_is_refused_with_its_code(): void
    {
        [$otherProductId] = $this->makeProductAndPrice($this->eventId);
        DB::table('product_check_in_lists')->insert([
            'product_id' => $otherProductId,
            'check_in_list_id' => $this->checkInListId,
        ]);

        $this->assertRefusedWithCode($this->makeAttendee()['public_id'], 'ATTENDEE_PRODUCT_NOT_ON_LIST');
    }

    public function test_a_batch_with_an_attendee_not_on_the_list_checks_nobody_in(): void
    {
        $valid = $this->makeAttendee();
        [$otherProductId, $otherPriceId] = $this->makeProductAndPrice($this->eventId);
        DB::table('product_check_in_lists')->insert([
            'product_id' => $this->productId,
            'check_in_list_id' => $this->checkInListId,
        ]);
        $notOnList = $this->makeAttendee(productId: $otherProductId, productPriceId: $otherPriceId);

        $response = $this->postJson(
            "/events/{$this->eventId}/check-in-lists/{$this->checkInListId}/check-ins",
            ['attendees' => [
                ['public_id' => $valid['public_id'], 'action' => 'check-in'],
                ['public_id' => $notOnList['public_id'], 'action' => 'check-in'],
            ]],
            $this->bearer($this->scanKey()),
        );

        $response->assertStatus(ResponseCodes::HTTP_CONFLICT);
        $response->assertJsonPath('error_code', 'ATTENDEE_PRODUCT_NOT_ON_LIST');
        $this->assertSame(0, DB::table('attendee_check_ins')->count());
        $this->assertCheckInEvents([]);
    }

    public function test_a_batch_of_more_than_100_attendees_is_refused(): void
    {
        $batch = static fn (int $size) => ['attendees' => array_map(
            static fn (int $i) => ['public_id' => sprintf('A-%07d', $i), 'action' => 'check-in'],
            range(1, $size),
        )];
        $url = "/events/{$this->eventId}/check-in-lists/{$this->checkInListId}/check-ins";
        $key = $this->scanKey();

        $this->postJson($url, $batch(101), $this->bearer($key))
            ->assertStatus(ResponseCodes::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('attendees');

        $this->postJson($url, $batch(100), $this->bearer($key))
            ->assertStatus(ResponseCodes::HTTP_CONFLICT)
            ->assertJsonPath('error_code', 'ATTENDEE_CODE_UNKNOWN');
    }

    public function test_an_attendee_of_another_occurrence_is_refused_with_its_code(): void
    {
        $listOccurrenceId = $this->makeOccurrence($this->eventId);
        $attendeeOccurrenceId = $this->makeOccurrence($this->eventId);
        DB::table('check_in_lists')->where('id', $this->checkInListId)
            ->update(['event_occurrence_id' => $listOccurrenceId]);

        $attendee = $this->makeAttendee(occurrenceId: $attendeeOccurrenceId);

        $this->assertRefusedWithCode($attendee['public_id'], 'ATTENDEE_OCCURRENCE_MISMATCH');
    }

    public function test_an_unknown_code_is_refused_with_its_code(): void
    {
        $this->assertRefusedWithCode('A-UNKNOWN1', 'ATTENDEE_CODE_UNKNOWN');
    }

    public function test_a_cancelled_attendee_is_not_checked_in(): void
    {
        $attendee = $this->makeAttendee(status: AttendeeStatus::CANCELLED);

        $response = $this->checkIn($this->scanKey(), $attendee['public_id']);

        $response->assertStatus(ResponseCodes::HTTP_OK);
        $this->assertSame([], $response->json('data'));
        $this->assertNotEmpty($response->json('errors.'.$attendee['public_id']));
        $this->assertSame(0, DB::table('attendee_check_ins')->count());
        $this->assertCheckInEvents([]);
    }

    public function test_a_code_shared_with_another_event_checks_in_on_the_event_of_the_list(): void
    {
        [$otherAdmin, $otherAccountId] = $this->makeAccountWithAdmin();
        $otherEventId = $this->makeEvent($otherAccountId, $otherAdmin->id, $this->makeOrganizer($otherAccountId));
        [$otherProductId, $otherPriceId] = $this->makeProductAndPrice($otherEventId);

        $attendee = $this->makeAttendee();
        $this->makeAttendee(
            publicId: $attendee['public_id'],
            eventId: $otherEventId,
            productId: $otherProductId,
            productPriceId: $otherPriceId,
        );

        $response = $this->checkIn($this->scanKey(), $attendee['public_id']);

        $response->assertStatus(ResponseCodes::HTTP_OK);
        $this->assertCheckedIn($response, $attendee['id']);
    }

    public function test_cancelling_a_check_in_returns_no_content_and_sends_checkin_deleted(): void
    {
        $attendee = $this->makeAttendee();
        $checkInId = $this->makeCheckIn($attendee['id'], $this->checkInListId);
        $checkInShortId = DB::table('attendee_check_ins')->where('id', $checkInId)->value('short_id');

        $response = $this->deleteJson(
            "/events/{$this->eventId}/check-in-lists/{$this->checkInListId}/check-ins/{$checkInShortId}",
            [],
            $this->bearer($this->scanKey()),
        );

        $response->assertStatus(ResponseCodes::HTTP_NO_CONTENT);
        $this->assertSame('', $response->getContent());
        $this->assertNull(DB::table('attendee_check_ins')->where('id', $checkInId)->whereNull('deleted_at')->first());
        $this->assertCheckInEvents([[DomainEventType::CHECKIN_DELETED, $checkInId]]);
    }

    public function test_cancelling_a_check_in_of_another_list_is_refused_with_its_code(): void
    {
        $attendee = $this->makeAttendee();
        $otherListId = $this->makeCheckInList($this->eventId);
        $checkInId = $this->makeCheckIn($attendee['id'], $otherListId);
        $checkInShortId = DB::table('attendee_check_ins')->where('id', $checkInId)->value('short_id');

        $this->deleteJson(
            "/events/{$this->eventId}/check-in-lists/{$this->checkInListId}/check-ins/{$checkInShortId}",
            [],
            $this->bearer($this->scanKey()),
        )
            ->assertStatus(ResponseCodes::HTTP_CONFLICT)
            ->assertJsonPath('error_code', 'ATTENDEE_NOT_CHECKED_IN');

        $this->assertNotNull(DB::table('attendee_check_ins')->where('id', $checkInId)->whereNull('deleted_at')->first());
        $this->assertCheckInEvents([]);
    }

    public function test_the_public_route_keeps_its_responses(): void
    {
        $attendee = $this->makeAttendee();

        $created = $this->postJson("/public/check-in-lists/{$this->listShortId()}/check-ins", [
            'attendees' => [['public_id' => $attendee['public_id'], 'action' => 'check-in']],
        ]);
        $created->assertStatus(ResponseCodes::HTTP_OK);
        $checkInId = $this->assertCheckedIn($created, $attendee['id']);
        $this->assertCheckInEvents([[DomainEventType::CHECKIN_CREATED, $checkInId]]);

        $refused = $this->postJson("/public/check-in-lists/{$this->listShortId()}/check-ins", [
            'attendees' => [['public_id' => 'A-UNKNOWN1', 'action' => 'check-in']],
        ]);
        $refused->assertStatus(ResponseCodes::HTTP_CONFLICT);
        $this->assertSame(['message', 'errors'], array_keys($refused->json()));
        $this->assertSame([], $refused->json('errors'));

        $this->dispatched = [];
        $checkInShortId = DB::table('attendee_check_ins')->where('id', $checkInId)->value('short_id');
        $this->deleteJson("/public/check-in-lists/{$this->listShortId()}/check-ins/{$checkInShortId}")
            ->assertStatus(ResponseCodes::HTTP_NO_CONTENT);
        $this->assertCheckInEvents([[DomainEventType::CHECKIN_DELETED, $checkInId]]);
    }

    public function test_check_ins_are_rate_limited_per_key(): void
    {
        config(['app.api_rate_limit_per_minute' => 2]);
        $key = $this->scanKey();
        $jwt = $this->jwtFor($this->admin, $this->accountId);

        $this->checkIn($key, 'A-UNKNOWN1')->assertStatus(ResponseCodes::HTTP_CONFLICT);
        $this->checkIn($key, 'A-UNKNOWN1')->assertStatus(ResponseCodes::HTTP_CONFLICT);
        $this->checkIn($key, 'A-UNKNOWN1')->assertStatus(ResponseCodes::HTTP_TOO_MANY_REQUESTS);

        $this->checkIn($jwt, 'A-UNKNOWN1')->assertStatus(ResponseCodes::HTTP_CONFLICT);
    }

    public function test_the_instance_declares_authenticated_check_in(): void
    {
        $this->getJson('/api-key', $this->bearer($this->scanKey()))
            ->assertStatus(ResponseCodes::HTTP_OK)
            ->assertJsonPath('data.features', fn (array $features) => in_array('authenticated_check_in', $features, true));
    }

    private function assertRefusedWithCode(string $publicId, string $errorCode): void
    {
        $response = $this->checkIn($this->scanKey(), $publicId);

        $response->assertStatus(ResponseCodes::HTTP_CONFLICT);
        $response->assertJsonPath('error_code', $errorCode);
        $response->assertJsonPath('errors', []);
        $this->assertNotEmpty($response->json('message'));
        $this->assertSame(0, DB::table('attendee_check_ins')->count());
        $this->assertCheckInEvents([]);
    }

    private function assertCheckedIn(TestResponse $response, int $attendeeId): int
    {
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($attendeeId, $response->json('data.0.attendee_id'));
        $this->assertSame($this->checkInListId, $response->json('data.0.check_in_list_id'));

        $checkInId = $response->json('data.0.id');
        $this->assertTrue(DB::table('attendee_check_ins')->where([
            'id' => $checkInId,
            'attendee_id' => $attendeeId,
            'check_in_list_id' => $this->checkInListId,
        ])->exists());

        return $checkInId;
    }

    /**
     * @param  array<array{0: DomainEventType, 1: int}>  $expected
     */
    private function assertCheckInEvents(array $expected): void
    {
        $actual = array_map(
            static fn (CheckinEvent $event) => [$event->type, $event->attendeeCheckinId],
            array_values(array_filter($this->dispatched, static fn ($event) => $event instanceof CheckinEvent)),
        );

        $this->assertSame($expected, $actual);
    }

    private function recordDomainEvents(): void
    {
        $dispatcher = Mockery::mock(DomainEventDispatcherService::class);
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function (BaseDomainEvent $event): void {
            $this->dispatched[] = $event;
        });

        $this->app->instance(DomainEventDispatcherService::class, $dispatcher);
    }

    private function checkIn(string $token, string $publicId, string $action = 'check-in'): TestResponse
    {
        return $this->postJson(
            "/events/{$this->eventId}/check-in-lists/{$this->checkInListId}/check-ins",
            ['attendees' => [['public_id' => $publicId, 'action' => $action]]],
            $this->bearer($token),
        );
    }

    private function scanKey(): string
    {
        return $this->makeApiKey($this->admin, $this->accountId, self::SCAN_ABILITIES);
    }

    private function listShortId(): string
    {
        return DB::table('check_in_lists')->where('id', $this->checkInListId)->value('short_id');
    }

    private function makeEventSettings(int $eventId): void
    {
        DB::table('event_settings')->insert([
            'event_id' => $eventId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeProductAndPrice(int $eventId): array
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

        return [$productId, $productPriceId];
    }

    private function makeCheckInList(int $eventId): int
    {
        return DB::table('check_in_lists')->insertGetId([
            'short_id' => 'cil_'.uniqid(),
            'name' => 'Door',
            'event_id' => $eventId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeOccurrence(int $eventId): int
    {
        return DB::table('event_occurrences')->insertGetId([
            'short_id' => 'oc_'.uniqid(),
            'event_id' => $eventId,
            'start_date' => now()->addWeek(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeOrder(OrderStatus $status = OrderStatus::COMPLETED, ?int $eventId = null): int
    {
        return DB::table('orders')->insertGetId([
            'short_id' => 'o_'.uniqid(),
            'event_id' => $eventId ?? $this->eventId,
            'currency' => 'USD',
            'status' => $status->name,
            'payment_status' => $status === OrderStatus::COMPLETED ? 'PAYMENT_RECEIVED' : 'AWAITING_OFFLINE_PAYMENT',
            'first_name' => 'Test',
            'last_name' => 'Buyer',
            'email' => 'buyer-'.uniqid().'@example.test',
            'total_before_additions' => 20.00,
            'total_gross' => 20.00,
            'public_id' => 'O-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{id: int, public_id: string}
     */
    private function makeAttendee(
        ?int $orderId = null,
        AttendeeStatus $status = AttendeeStatus::ACTIVE,
        ?int $occurrenceId = null,
        ?string $publicId = null,
        ?int $eventId = null,
        ?int $productId = null,
        ?int $productPriceId = null,
    ): array {
        $eventId ??= $this->eventId;
        $publicId ??= 'A-'.strtoupper(substr(uniqid(), -7));

        $id = DB::table('attendees')->insertGetId([
            'short_id' => 'a_'.uniqid(),
            'first_name' => 'Test',
            'last_name' => 'Attendee',
            'email' => 'attendee-'.uniqid().'@example.test',
            'order_id' => $orderId ?? $this->makeOrder(eventId: $eventId),
            'product_id' => $productId ?? $this->productId,
            'product_price_id' => $productPriceId ?? $this->productPriceId,
            'event_id' => $eventId,
            'event_occurrence_id' => $occurrenceId,
            'public_id' => $publicId,
            'status' => $status->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    private function makeCheckIn(int $attendeeId, int $checkInListId): int
    {
        $attendee = DB::table('attendees')->where('id', $attendeeId)->first();

        return DB::table('attendee_check_ins')->insertGetId([
            'short_id' => 'ci_'.uniqid(),
            'check_in_list_id' => $checkInListId,
            'product_id' => $attendee->product_id,
            'attendee_id' => $attendeeId,
            'order_id' => $attendee->order_id,
            'event_id' => $attendee->event_id,
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
