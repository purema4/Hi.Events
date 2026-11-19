<?php

namespace Tests\Feature\Jobs\Waitlist;

use DateTimeInterface;
use HiEvents\DomainObjects\Enums\EventType;
use HiEvents\DomainObjects\Enums\ProductQuantityAppliesTo;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\Status\WaitlistEntryStatus;
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

        $this->assertDatabaseHas('waitlist_entries', [
            'email' => 'first@example.com',
            'product_price_id' => $priceId,
            'status' => WaitlistEntryStatus::WAITING->name,
            'awaiting_sale_start' => true,
        ]);
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

        $this->assertDatabaseHas('waitlist_entries', [
            'email' => 'first@example.com',
            'status' => WaitlistEntryStatus::WAITING->name,
            'awaiting_sale_start' => true,
        ]);
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
        $this->assertSame(0, DB::table('waitlist_entries')->where('product_price_id', $priceId)->where('awaiting_sale_start', true)->count());
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
            'awaiting_sale_start' => false,
        ]);
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
