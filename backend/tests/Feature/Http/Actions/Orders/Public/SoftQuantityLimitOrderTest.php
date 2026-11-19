<?php

namespace Tests\Feature\Http\Actions\Orders\Public;

use HiEvents\DomainObjects\Enums\CapacityAssignmentAppliesTo;
use HiEvents\DomainObjects\Enums\EventType;
use HiEvents\DomainObjects\Enums\ProductQuantityAppliesTo;
use HiEvents\DomainObjects\Status\CapacityAssignmentStatus;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Models\AccountConfiguration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Support\InsertsRecurringEventRows;
use Tests\TestCase;

class SoftQuantityLimitOrderTest extends TestCase
{
    use DatabaseTransactions;
    use InsertsRecurringEventRows;

    private int $occurrenceId;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
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
        DB::table('event_settings')->insert(['event_id' => $this->eventId, 'created_at' => now(), 'updated_at' => now()]);
        $this->occurrenceId = $this->insertOccurrence();
    }

    public function test_the_order_that_crosses_the_limit_goes_through_and_the_next_one_is_refused(): void
    {
        $productId = $this->insertLimitedProduct(softLimit: true);
        $priceId = $this->insertEventWidePrice($productId, initialQuantity: 10, quantitySold: 8);

        $this->postOrder('first-session', $productId, $priceId, 4)->assertCreated();

        $this->postOrder('second-session', $productId, $priceId, 1)->assertUnprocessable();
    }

    public function test_without_the_soft_limit_an_order_over_the_remaining_quantity_is_refused(): void
    {
        $productId = $this->insertLimitedProduct(softLimit: false);
        $priceId = $this->insertEventWidePrice($productId, initialQuantity: 10, quantitySold: 8);

        $this->postOrder('first-session', $productId, $priceId, 4)->assertUnprocessable();
    }

    public function test_a_tier_that_overflows_sells_out_and_opens_the_next_tier(): void
    {
        $productId = $this->insertLimitedProduct(softLimit: true, priceType: 'TIERED');
        DB::table('products')->where('id', $productId)->update(['sequential_tier_release_enabled' => true]);
        $earlyBird = $this->insertEventWidePrice($productId, initialQuantity: 10, quantitySold: 8, label: 'Early bird', order: 1);
        $standard = $this->insertEventWidePrice($productId, initialQuantity: 50, quantitySold: 0, label: 'Standard', order: 2);

        $this->postOrder('third-session', $productId, $standard, 1)->assertUnprocessable();

        $shortId = $this->postOrder('first-session', $productId, $earlyBird, 4)->assertCreated()->json('data.short_id');
        $orderId = DB::table('orders')->where('short_id', $shortId)->value('id');
        $this->assertSame(4, (int) DB::table('order_items')->where('order_id', $orderId)->where('product_price_id', $earlyBird)->sum('quantity'));

        $this->postOrder('second-session', $productId, $earlyBird, 1)->assertUnprocessable();
        $this->postOrder('third-session', $productId, $standard, 1)->assertCreated();
    }

    public function test_a_shared_capacity_stays_strict(): void
    {
        $productId = $this->insertLimitedProduct(softLimit: true);
        $priceId = $this->insertEventWidePrice($productId, initialQuantity: 10, quantitySold: 8);
        $this->insertCapacityAssignment($productId, capacity: 3);

        $this->postOrder('first-session', $productId, $priceId, 4)->assertUnprocessable();
        $this->postOrder('second-session', $productId, $priceId, 3)->assertCreated();
    }

    private function insertLimitedProduct(bool $softLimit, string $priceType = 'FREE'): int
    {
        $productId = $this->insertProduct(priceType: $priceType);
        DB::table('products')->where('id', $productId)->update([
            'soft_quantity_limit' => $softLimit,
            'max_per_order' => 10,
        ]);

        return $productId;
    }

    private function insertEventWidePrice(int $productId, int $initialQuantity, int $quantitySold, ?string $label = null, int $order = 1): int
    {
        $priceId = $this->insertPrice(
            $productId,
            initialQuantity: $initialQuantity,
            appliesTo: ProductQuantityAppliesTo::EVENT->name,
            quantitySold: $quantitySold,
            label: $label,
            price: 0.00,
        );
        DB::table('product_prices')->where('id', $priceId)->update(['order' => $order]);

        return $priceId;
    }

    private function insertCapacityAssignment(int $productId, int $capacity): void
    {
        $capacityAssignmentId = DB::table('capacity_assignments')->insertGetId([
            'event_id' => $this->eventId,
            'name' => 'Venue',
            'capacity' => $capacity,
            'used_capacity' => 0,
            'applies_to' => CapacityAssignmentAppliesTo::PRODUCTS->name,
            'status' => CapacityAssignmentStatus::ACTIVE->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_capacity_assignments')->insert([
            'product_id' => $productId,
            'capacity_assignment_id' => $capacityAssignmentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function postOrder(string $sessionIdentifier, int $productId, int $priceId, int $quantity): TestResponse
    {
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $route->flushController();
        }

        return $this->postJson("/public/events/{$this->eventId}/order?session_identifier={$sessionIdentifier}", [
            'products' => [[
                'product_id' => $productId,
                'event_occurrence_id' => $this->occurrenceId,
                'quantities' => [['price_id' => $priceId, 'quantity' => $quantity]],
            ]],
        ]);
    }
}
