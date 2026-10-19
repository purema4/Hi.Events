<?php

namespace Tests\Feature\Http\Actions\Orders;

use HiEvents\Http\ResponseCodes;
use HiEvents\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class OrdersStableSortTest extends TestCase
{
    use DatabaseTransactions;

    private const ORDER_COUNT = 1000;

    private const PER_PAGE = 50;

    private const SAME_SECOND = '2026-01-01 00:00:00';

    private string $authToken;

    private int $accountId;

    private int $userId;

    private int $organizerId;

    private int $eventId;

    private string $runId;

    private int $rowNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runId = bin2hex(random_bytes(4));

        [$this->userId, $this->authToken, $this->accountId] = $this->makeAuthenticatedUser('ADMIN');
        $this->organizerId = $this->makeOrganizer();
        $this->eventId = $this->makeEvent();
    }

    public function test_event_order_pages_return_each_order_once_when_created_at_ties(): void
    {
        $orderIds = $this->insertOrdersInTheSameSecond();

        $seen = $this->readAllPages("/events/{$this->eventId}/orders?per_page=".self::PER_PAGE);

        $this->assertEachOrderSeenOnce($orderIds, $seen);
    }

    public function test_event_order_pages_return_each_order_once_when_updated_at_ties(): void
    {
        $orderIds = $this->insertOrdersInTheSameSecond();

        $seen = $this->readAllPages(
            "/events/{$this->eventId}/orders?sort_by=updated_at&sort_direction=asc&per_page=".self::PER_PAGE,
        );

        $this->assertEachOrderSeenOnce($orderIds, $seen);
    }

    public function test_organizer_order_pages_return_each_order_once_when_created_at_ties(): void
    {
        $orderIds = $this->insertOrdersInTheSameSecond();

        $seen = $this->readAllPages("/organizers/{$this->organizerId}/orders?per_page=".self::PER_PAGE);

        $this->assertEachOrderSeenOnce($orderIds, $seen);
    }

    public function test_admin_order_pages_return_each_order_once_when_created_at_ties(): void
    {
        [, $superAdminToken] = $this->makeAuthenticatedUser('SUPERADMIN');
        $orderIds = $this->insertOrdersInTheSameSecond();

        $seen = $this->readAllPages('/admin/orders?per_page='.self::PER_PAGE, $superAdminToken);

        $this->assertEachOrderSeenOnce($orderIds, array_values(array_intersect($seen, $orderIds)));
        $this->assertSame(count($seen), count(array_unique($seen)), 'An order was listed on two pages.');
    }

    public function test_ties_are_broken_by_id_in_the_requested_direction(): void
    {
        $orderIds = [];
        for ($i = 0; $i < 5; $i++) {
            $orderIds[] = $this->insertOrder();
        }
        $ascending = $orderIds;
        sort($ascending);
        $descending = array_reverse($ascending);

        $this->assertSame($descending, $this->ids("/events/{$this->eventId}/orders"));
        $this->assertSame($ascending, $this->ids("/events/{$this->eventId}/orders?sort_direction=asc"));
        $this->assertSame(
            $descending,
            $this->ids("/events/{$this->eventId}/orders?sort_by=updated_at&sort_direction=desc"),
        );
        $this->assertSame($descending, $this->ids("/organizers/{$this->organizerId}/orders"));
        $this->assertSame(
            $ascending,
            $this->ids("/organizers/{$this->organizerId}/orders?sort_direction=asc"),
        );
    }

    /**
     * @return int[] every order id read, page after page, duplicates kept
     */
    private function readAllPages(string $url, ?string $token = null): array
    {
        $seen = [];
        $page = 1;

        do {
            $response = $this->getJson("{$url}&page={$page}", $this->authHeaders($token));
            $response->assertStatus(ResponseCodes::HTTP_OK);

            array_push($seen, ...collect($response->json('data'))->pluck('id')->all());
            $lastPage = (int) $response->json('meta.last_page');
            $page++;
        } while ($page <= $lastPage);

        return $seen;
    }

    private function assertEachOrderSeenOnce(array $orderIds, array $seen): void
    {
        $counts = array_count_values($seen);
        $duplicated = array_keys(array_filter($counts, static fn (int $count) => $count > 1));
        $missing = array_values(array_diff($orderIds, $seen));

        $this->assertSame(
            [[], []],
            [$duplicated, $missing],
            sprintf(
                '%d orders listed more than once and %d orders never listed across the pages.',
                count($duplicated),
                count($missing),
            ),
        );
        $this->assertCount(count($orderIds), $seen);
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
    private function insertOrdersInTheSameSecond(): array
    {
        $rows = [];
        for ($i = 0; $i < self::ORDER_COUNT; $i++) {
            $rows[] = $this->orderRow();
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('orders')->insert($chunk);
        }

        return DB::table('orders')
            ->where('event_id', $this->eventId)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    private function insertOrder(): int
    {
        return DB::table('orders')->insertGetId($this->orderRow());
    }

    private function orderRow(): array
    {
        return [
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
        ];
    }

    private function makeAuthenticatedUser(string $role): array
    {
        $user = User::factory()->withAccount()->create();
        $accountId = $user->accounts()->first()->id;

        DB::table('account_users')
            ->where('account_id', $accountId)
            ->where('user_id', $user->id)
            ->update(['role' => $role]);

        $token = JWTAuth::claims(['account_id' => $accountId])->fromUser($user);

        return [$user->id, $token, $accountId];
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

    private function makeEvent(): int
    {
        return DB::table('events')->insertGetId([
            'title' => 'Orders Stable Sort Test Event',
            'account_id' => $this->accountId,
            'user_id' => $this->userId,
            'organizer_id' => $this->organizerId,
            'currency' => 'USD',
            'timezone' => 'UTC',
            'short_id' => 'ev_'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function authHeaders(?string $token = null): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.($token ?? $this->authToken)];
    }
}
