<?php

namespace Tests\Feature\Jobs\Waitlist;

use DateTimeInterface;
use HiEvents\DomainObjects\Enums\CapacityChangeDirection;
use HiEvents\DomainObjects\Enums\EventType;
use HiEvents\DomainObjects\Enums\ProductQuantityAppliesTo;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\Status\WaitlistEntryStatus;
use HiEvents\Events\CapacityChangedEvent;
use HiEvents\Jobs\Waitlist\OpenPreSaleWaitlistsJob;
use HiEvents\Jobs\Waitlist\SendWaitlistConfirmationEmailJob;
use HiEvents\Jobs\Waitlist\SendWaitlistOfferEmailJob;
use HiEvents\Models\AccountConfiguration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Support\InsertsRecurringEventRows;
use Tests\TestCase;

class OpenPreSaleWaitlistsJobTest extends TestCase
{
    use DatabaseTransactions;
    use InsertsRecurringEventRows;

    private int $occurrenceId;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([SendWaitlistConfirmationEmailJob::class, SendWaitlistOfferEmailJob::class]);
        config(['app.homepage_product_quantities_cache_ttl' => null]);

        AccountConfiguration::firstOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'Default',
            'is_system_default' => true,
            'application_fees' => ['percentage' => 1.5, 'fixed' => 0],
        ]);

        $this->insertAccountAndOrganizer();
        $this->eventId = $this->insertEvent(EventType::SINGLE->name);
        DB::table('events')->where('id', $this->eventId)->update(['status' => EventStatus::LIVE->name]);
        DB::table('event_settings')->insert([
            'event_id' => $this->eventId,
            'waitlist_enabled' => true,
            'waitlist_auto_process' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->occurrenceId = $this->insertOccurrence(daysAhead: 30);
    }

    public function test_people_can_join_the_waitlist_before_sales_open(): void
    {
        $priceId = $this->insertWaitlistedPrice(saleStartsAt: now()->addDay());

        $this->joinWaitlist($priceId, 'first@example.com')->assertCreated();

        $entry = DB::table('waitlist_entries')->where('email', 'first@example.com')->first();
        $this->assertSame($priceId, $entry->product_price_id);
        $this->assertSame(WaitlistEntryStatus::WAITING->name, $entry->status);
        $this->assertNotNull($entry->next_sale_start_at);
        $this->assertFalse($entry->any_tier);
    }

    public function test_a_product_hidden_until_its_sale_starts_cannot_be_waitlisted_early(): void
    {
        $priceId = $this->insertWaitlistedPrice(saleStartsAt: now()->addDay());
        DB::table('products')->where('id', $this->productIdOf($priceId))->update(['hide_before_sale_start_date' => true]);

        $this->joinWaitlist($priceId, 'first@example.com')->assertStatus(409);
    }

    public function test_nothing_is_offered_before_sales_open(): void
    {
        $priceId = $this->insertWaitlistedPrice(saleStartsAt: now()->addDay());
        $this->joinWaitlist($priceId, 'first@example.com')->assertCreated();

        app()->call([new OpenPreSaleWaitlistsJob, 'handle']);

        $entry = DB::table('waitlist_entries')->where('email', 'first@example.com')->first();
        $this->assertSame(WaitlistEntryStatus::WAITING->name, $entry->status);
        $this->assertNotNull($entry->next_sale_start_at);
    }

    public function test_when_sales_open_the_waitlist_gets_offers_in_order_up_to_the_quantity(): void
    {
        $priceId = $this->insertWaitlistedPrice(saleStartsAt: now()->addDay(), quantity: 2);
        $this->joinWaitlist($priceId, 'first@example.com')->assertCreated();
        $this->joinWaitlist($priceId, 'second@example.com')->assertCreated();
        $this->joinWaitlist($priceId, 'third@example.com')->assertCreated();

        DB::table('products')->where('id', $this->productIdOf($priceId))->update(['sale_start_date' => now()->subMinute()]);

        app()->call([new OpenPreSaleWaitlistsJob, 'handle']);

        $statuses = DB::table('waitlist_entries')->where('product_price_id', $priceId)->orderBy('position')->pluck('status', 'email')->all();
        $this->assertSame([
            'first@example.com' => WaitlistEntryStatus::OFFERED->name,
            'second@example.com' => WaitlistEntryStatus::OFFERED->name,
            'third@example.com' => WaitlistEntryStatus::WAITING->name,
        ], $statuses);
        $this->assertSame(0, DB::table('waitlist_entries')->where('product_price_id', $priceId)->whereNotNull('next_sale_start_at')->count());
        Bus::assertDispatchedTimes(SendWaitlistOfferEmailJob::class, 2);
    }

    public function test_the_promo_code_used_to_join_is_applied_to_the_offer(): void
    {
        $priceId = $this->insertWaitlistedPrice(saleStartsAt: now()->addDay(), priceType: 'PAID', price: 20.00);
        $promoCodeId = DB::table('promo_codes')->insertGetId([
            'event_id' => $this->eventId,
            'code' => 'members',
            'discount' => 10,
            'discount_type' => 'PERCENTAGE',
            'applicable_product_ids' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->joinWaitlist($priceId, 'first@example.com', promoCode: 'MEMBERS')->assertCreated();
        $this->assertDatabaseHas('waitlist_entries', ['email' => 'first@example.com', 'promo_code_id' => $promoCodeId]);

        DB::table('products')->where('id', $this->productIdOf($priceId))->update(['sale_start_date' => now()->subMinute()]);

        app()->call([new OpenPreSaleWaitlistsJob, 'handle']);

        $orderId = DB::table('waitlist_entries')->where('email', 'first@example.com')->value('order_id');
        $this->assertSame($promoCodeId, DB::table('orders')->where('id', $orderId)->value('promo_code_id'));
        $orderItem = DB::table('order_items')->where('order_id', $orderId)->first();
        $this->assertEquals(20.00, (float) $orderItem->price_before_discount);
        $this->assertEquals(18.00, (float) $orderItem->price);
    }

    public function test_each_offer_reserves_the_minimum_per_order_and_stops_when_it_no_longer_fits(): void
    {
        $priceId = $this->insertWaitlistedPrice(saleStartsAt: now()->addDay(), quantity: 10);
        DB::table('products')->where('id', $this->productIdOf($priceId))->update(['min_per_order' => 4]);
        $this->joinWaitlist($priceId, 'first@example.com')->assertCreated();
        $this->joinWaitlist($priceId, 'second@example.com')->assertCreated();
        $this->joinWaitlist($priceId, 'third@example.com')->assertCreated();

        DB::table('products')->where('id', $this->productIdOf($priceId))->update(['sale_start_date' => now()->subMinute()]);

        app()->call([new OpenPreSaleWaitlistsJob, 'handle']);

        $entries = DB::table('waitlist_entries')->where('product_price_id', $priceId)->orderBy('position')->get();
        $this->assertSame(
            [WaitlistEntryStatus::OFFERED->name, WaitlistEntryStatus::OFFERED->name, WaitlistEntryStatus::WAITING->name],
            $entries->pluck('status')->all(),
        );
        foreach ($entries->take(2) as $entry) {
            $this->assertSame(4, (int) DB::table('order_items')->where('order_id', $entry->order_id)->sum('quantity'));
        }
    }

    public function test_a_promo_code_for_other_tickets_is_still_remembered(): void
    {
        $priceId = $this->insertWaitlistedPrice(saleStartsAt: now()->addDay(), priceType: 'PAID', price: 20.00);
        $promoCodeId = DB::table('promo_codes')->insertGetId([
            'event_id' => $this->eventId,
            'code' => 'vip',
            'discount' => 10,
            'discount_type' => 'PERCENTAGE',
            'applicable_product_ids' => json_encode([$this->productIdOf($priceId) + 1000]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->joinWaitlist($priceId, 'first@example.com', promoCode: 'vip')->assertCreated();
        DB::table('products')->where('id', $this->productIdOf($priceId))->update(['sale_start_date' => now()->subMinute()]);

        app()->call([new OpenPreSaleWaitlistsJob, 'handle']);

        $orderId = DB::table('waitlist_entries')->where('email', 'first@example.com')->value('order_id');
        $this->assertSame($promoCodeId, DB::table('orders')->where('id', $orderId)->value('promo_code_id'));
        $this->assertEquals(20.00, (float) DB::table('order_items')->where('order_id', $orderId)->value('price'));
    }

    public function test_without_automatic_processing_the_entries_stay_waiting_for_the_organizer(): void
    {
        DB::table('event_settings')->where('event_id', $this->eventId)->update(['waitlist_auto_process' => false]);
        $priceId = $this->insertWaitlistedPrice(saleStartsAt: now()->addDay());
        $this->joinWaitlist($priceId, 'first@example.com')->assertCreated();

        DB::table('products')->where('id', $this->productIdOf($priceId))->update(['sale_start_date' => now()->subMinute()]);

        app()->call([new OpenPreSaleWaitlistsJob, 'handle']);

        $this->assertDatabaseHas('waitlist_entries', [
            'email' => 'first@example.com',
            'status' => WaitlistEntryStatus::WAITING->name,
            'next_sale_start_at' => null,
        ]);
    }

    public function test_a_tiered_ticket_has_one_waitlist_for_all_its_tiers(): void
    {
        [$tierOne, $tierTwo] = $this->insertTieredProduct(saleStartsAt: now()->addDay(), tiers: [[2, 0], [10, 0]]);

        $this->joinWaitlist($tierTwo, 'first@example.com')->assertCreated();
        $this->joinWaitlist($tierOne, 'first@example.com')->assertStatus(409);

        $entry = DB::table('waitlist_entries')->where('email', 'first@example.com')->first();
        $this->assertTrue($entry->any_tier);
        $this->assertSame($tierOne, $entry->product_price_id);
    }

    public function test_when_sales_open_each_person_is_offered_the_tier_on_sale_at_that_moment(): void
    {
        [$tierOne, $tierTwo] = $this->insertTieredProduct(saleStartsAt: now()->addDay(), tiers: [[2, 0], [10, 0]]);
        foreach (['first', 'second', 'third'] as $name) {
            $this->joinWaitlist($tierOne, "{$name}@example.com")->assertCreated();
        }

        DB::table('products')->where('id', $this->productIdOf($tierOne))->update(['sale_start_date' => now()->subMinute()]);
        app()->call([new OpenPreSaleWaitlistsJob, 'handle']);

        $this->assertSame([
            'first@example.com' => $tierOne,
            'second@example.com' => $tierOne,
            'third@example.com' => $tierTwo,
        ], $this->offeredPriceByEmail());
    }

    public function test_a_tier_that_opens_later_triggers_the_offers_when_it_opens(): void
    {
        [$tierOne, $tierTwo] = $this->insertTieredProduct(saleStartsAt: now()->subDay(), tiers: [[5, 5], [10, 0, now()->addDay()]]);

        $this->joinWaitlist($tierOne, 'first@example.com')->assertCreated();
        $this->assertNotNull(DB::table('waitlist_entries')->where('email', 'first@example.com')->value('next_sale_start_at'));

        app()->call([new OpenPreSaleWaitlistsJob, 'handle']);
        $this->assertSame([], $this->offeredPriceByEmail());

        DB::table('product_prices')->where('id', $tierTwo)->update(['sale_start_date' => now()->subMinute()]);
        app()->call([new OpenPreSaleWaitlistsJob, 'handle']);

        $this->assertSame(['first@example.com' => $tierTwo], $this->offeredPriceByEmail());
    }

    public function test_a_sold_out_tiered_ticket_offers_the_tier_that_reopens(): void
    {
        [$tierOne, $tierTwo] = $this->insertTieredProduct(saleStartsAt: now()->subDay(), tiers: [[5, 5], [5, 5]]);
        $this->joinWaitlist($tierTwo, 'first@example.com')->assertCreated();

        DB::table('product_prices')->where('id', $tierOne)->update(['quantity_sold' => 4]);
        event(new CapacityChangedEvent(
            eventId: $this->eventId,
            direction: CapacityChangeDirection::INCREASED,
            productId: $this->productIdOf($tierOne),
            productPriceId: $tierOne,
            eventOccurrenceId: $this->occurrenceId,
        ));

        $this->assertSame(['first@example.com' => $tierOne], $this->offeredPriceByEmail());
    }

    public function test_with_earlier_tiers_kept_closed_returned_tickets_are_not_offered(): void
    {
        [$tierOne, , $tierThree] = $this->insertTieredProduct(saleStartsAt: now()->subDay(), tiers: [[5, 5], [5, 5], [10, 2]]);
        DB::table('products')->where('id', $this->productIdOf($tierOne))->update(['keep_earlier_tiers_closed' => true]);
        $this->joinWaitlist($tierOne, 'first@example.com')->assertCreated();

        DB::table('product_prices')->where('id', $tierOne)->update(['quantity_sold' => 1]);
        event(new CapacityChangedEvent(
            eventId: $this->eventId,
            direction: CapacityChangeDirection::INCREASED,
            productId: $this->productIdOf($tierOne),
            productPriceId: $tierOne,
            eventOccurrenceId: $this->occurrenceId,
        ));

        $this->assertSame(['first@example.com' => $tierThree], $this->offeredPriceByEmail());
    }

    /**
     * @param  array<int, array{0: int, 1: int, 2?: DateTimeInterface}>  $tiers
     * @return array<int, int>
     */
    private function insertTieredProduct(DateTimeInterface $saleStartsAt, array $tiers): array
    {
        $productId = $this->insertProduct(priceType: 'TIERED');
        DB::table('products')->where('id', $productId)->update([
            'waitlist_enabled' => true,
            'sale_start_date' => $saleStartsAt,
            'sequential_tier_release_enabled' => true,
        ]);

        return collect($tiers)->map(function (array $tier, int $index) use ($productId) {
            $priceId = $this->insertPrice($productId, initialQuantity: $tier[0], appliesTo: ProductQuantityAppliesTo::EVENT->name, quantitySold: $tier[1], label: 'Tier '.($index + 1), price: 0.00);
            DB::table('product_prices')->where('id', $priceId)->update(['order' => $index + 1, 'sale_start_date' => $tier[2] ?? null]);

            return $priceId;
        })->all();
    }

    /**
     * @return array<string, int>
     */
    private function offeredPriceByEmail(): array
    {
        return DB::table('waitlist_entries')
            ->join('order_items', 'order_items.order_id', '=', 'waitlist_entries.order_id')
            ->where('waitlist_entries.status', WaitlistEntryStatus::OFFERED->name)
            ->orderBy('waitlist_entries.id')
            ->pluck('order_items.product_price_id', 'waitlist_entries.email')
            ->map(fn ($priceId) => (int) $priceId)
            ->all();
    }

    private function insertWaitlistedPrice(DateTimeInterface $saleStartsAt, int $quantity = 10, string $priceType = 'FREE', float $price = 0.00): int
    {
        $productId = $this->insertProduct(priceType: $priceType);
        DB::table('products')->where('id', $productId)->update([
            'waitlist_enabled' => true,
            'sale_start_date' => $saleStartsAt,
        ]);

        return $this->insertPrice($productId, initialQuantity: $quantity, appliesTo: ProductQuantityAppliesTo::EVENT->name, price: $price);
    }

    private function productIdOf(int $priceId): int
    {
        return (int) DB::table('product_prices')->where('id', $priceId)->value('product_id');
    }

    private function joinWaitlist(int $priceId, string $email, ?string $promoCode = null): TestResponse
    {
        return $this->postJson("/public/events/{$this->eventId}/waitlist", [
            'product_price_id' => $priceId,
            'email' => $email,
            'first_name' => 'Alex',
            'promo_code' => $promoCode,
        ]);
    }
}
