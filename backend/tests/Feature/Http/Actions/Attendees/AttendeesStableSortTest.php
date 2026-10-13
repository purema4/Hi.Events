<?php

namespace Tests\Feature\Http\Actions\Attendees;

use HiEvents\Http\ResponseCodes;
use HiEvents\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class AttendeesStableSortTest extends TestCase
{
    use DatabaseTransactions;

    private const ORDER_COUNT = 250;

    private const ATTENDEES_PER_ORDER = 4;

    private const PER_PAGE = 50;

    private const SAME_SECOND = '2026-01-01 00:00:00';

    private string $authToken;

    private int $accountId;

    private int $userId;

    private int $eventId;

    private int $productId;

    private int $productPriceId;

    private string $runId;

    private int $rowNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runId = bin2hex(random_bytes(4));

        $user = User::factory()->withAccount()->create();
        $this->userId = $user->id;
        $this->accountId = $user->accounts()->first()->id;
        $this->authToken = JWTAuth::claims(['account_id' => $this->accountId])->fromUser($user);

        $this->eventId = $this->makeEvent($this->makeOrganizer());
        [$this->productId, $this->productPriceId] = $this->makeProductAndPrice();
    }

    public function test_attendee_pages_return_each_attendee_once_when_created_at_ties(): void
    {
        $attendeeIds = $this->insertAttendeesInTheSameSecond();

        $seen = $this->readAllPages("/events/{$this->eventId}/attendees?per_page=".self::PER_PAGE);

        $this->assertEachAttendeeSeenOnce($attendeeIds, $seen);
    }

    public function test_attendee_pages_return_each_attendee_once_when_the_ticket_name_ties(): void
    {
        $attendeeIds = $this->insertAttendeesInTheSameSecond();

        $seen = $this->readAllPages(
            "/events/{$this->eventId}/attendees?sort_by=ticket_name&sort_direction=asc&per_page=".self::PER_PAGE,
        );

        $this->assertEachAttendeeSeenOnce($attendeeIds, $seen);
    }

    public function test_ties_are_broken_by_id_in_the_requested_direction(): void
    {
        $orderId = $this->insertOrder();
        $attendeeIds = [];
        for ($i = 0; $i < 5; $i++) {
            $attendeeIds[] = DB::table('attendees')->insertGetId($this->attendeeRow($orderId));
        }
        $ascending = $attendeeIds;
        sort($ascending);
        $descending = array_reverse($ascending);

        $this->assertSame($descending, $this->ids("/events/{$this->eventId}/attendees"));
        $this->assertSame($ascending, $this->ids("/events/{$this->eventId}/attendees?sort_direction=asc"));
        $this->assertSame(
            $ascending,
            $this->ids("/events/{$this->eventId}/attendees?sort_by=ticket_name&sort_direction=asc"),
        );
    }

    /**
     * @return int[]
     */
    private function readAllPages(string $url): array
    {
        $seen = [];
        $page = 1;

        do {
            $response = $this->getJson("{$url}&page={$page}", $this->authHeaders());
            $response->assertStatus(ResponseCodes::HTTP_OK);

            array_push($seen, ...collect($response->json('data'))->pluck('id')->all());
            $lastPage = (int) $response->json('meta.last_page');
            $page++;
        } while ($page <= $lastPage);

        return $seen;
    }

    private function assertEachAttendeeSeenOnce(array $attendeeIds, array $seen): void
    {
        $counts = array_count_values($seen);
        $duplicated = array_keys(array_filter($counts, static fn (int $count) => $count > 1));
        $missing = array_values(array_diff($attendeeIds, $seen));

        $this->assertSame(
            [[], []],
            [$duplicated, $missing],
            sprintf(
                '%d attendees listed more than once and %d attendees never listed across the pages.',
                count($duplicated),
                count($missing),
            ),
        );
        $this->assertCount(count($attendeeIds), $seen);
    }

    private function ids(string $url): array
    {
        $response = $this->getJson($url, $this->authHeaders());
        $response->assertStatus(ResponseCodes::HTTP_OK);

        return collect($response->json('data'))->pluck('id')->all();
    }

    /**
     * @return int[]
     */
    private function insertAttendeesInTheSameSecond(): array
    {
        $rows = [];
        for ($i = 0; $i < self::ORDER_COUNT; $i++) {
            $orderId = $this->insertOrder();
            for ($j = 0; $j < self::ATTENDEES_PER_ORDER; $j++) {
                $rows[] = $this->attendeeRow($orderId);
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('attendees')->insert($chunk);
        }

        return DB::table('attendees')
            ->where('event_id', $this->eventId)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    private function insertOrder(): int
    {
        return DB::table('orders')->insertGetId([
            'short_id' => sprintf('o_%s%05d', $this->runId, $this->rowNumber),
            'event_id' => $this->eventId,
            'currency' => 'USD',
            'status' => 'COMPLETED',
            'payment_status' => 'PAYMENT_RECEIVED',
            'total_before_additions' => 20.00,
            'total_gross' => 20.00,
            'public_id' => sprintf('O-%s%05d', $this->runId, $this->rowNumber++),
            'created_at' => self::SAME_SECOND,
            'updated_at' => self::SAME_SECOND,
        ]);
    }

    private function attendeeRow(int $orderId): array
    {
        return [
            'short_id' => sprintf('a_%s%05d', $this->runId, $this->rowNumber),
            'first_name' => 'Test',
            'last_name' => 'Attendee',
            'email' => sprintf('attendee-%s%05d@example.test', $this->runId, $this->rowNumber),
            'order_id' => $orderId,
            'product_id' => $this->productId,
            'product_price_id' => $this->productPriceId,
            'event_id' => $this->eventId,
            'public_id' => sprintf('A-%s%05d', $this->runId, $this->rowNumber++),
            'status' => 'ACTIVE',
            'created_at' => self::SAME_SECOND,
            'updated_at' => self::SAME_SECOND,
        ];
    }

    private function makeOrganizer(): int
    {
        return DB::table('organizers')->insertGetId([
            'account_id' => $this->accountId,
            'name' => 'Stable Sort Test Organizer',
            'email' => 'organizer-'.uniqid().'@test.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeEvent(int $organizerId): int
    {
        return DB::table('events')->insertGetId([
            'title' => 'Attendees Stable Sort Test Event',
            'account_id' => $this->accountId,
            'user_id' => $this->userId,
            'organizer_id' => $organizerId,
            'currency' => 'USD',
            'timezone' => 'UTC',
            'short_id' => 'ev_'.uniqid(),
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

    private function authHeaders(): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$this->authToken];
    }
}
