<?php

namespace Tests\Feature\Http\Actions\SaleLinks;

use HiEvents\DomainObjects\Enums\EventType;
use HiEvents\DomainObjects\Enums\ProductQuantityAppliesTo;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Feature\Support\InsertsRecurringEventRows;
use Tests\TestCase;

class SaleLinkTest extends TestCase
{
    use DatabaseTransactions;
    use InsertsRecurringEventRows;

    private int $occurrenceId;

    private int $productId;

    private int $priceId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'array']);
        config(['app.homepage_product_quantities_cache_ttl' => null]);

        AccountConfiguration::firstOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'Default',
            'is_system_default' => true,
            'application_fees' => ['percentage' => 0, 'fixed' => 0],
        ]);

        $this->insertAccountAndOrganizer();
        $this->eventId = $this->insertEvent(EventType::SINGLE->name);
        DB::table('events')->where('id', $this->eventId)->update(['status' => EventStatus::LIVE->name]);
        DB::table('event_settings')->insert(['event_id' => $this->eventId, 'created_at' => now(), 'updated_at' => now()]);
        $this->occurrenceId = $this->insertOccurrence();
        $this->productId = $this->insertProduct();
        $this->priceId = $this->insertPrice($this->productId, initialQuantity: 10, appliesTo: ProductQuantityAppliesTo::EVENT->name, price: 50.00);
    }

    public function test_an_organizer_creates_a_link_and_the_buyer_gets_an_order_at_the_link_price(): void
    {
        $link = $this->createLink(['items' => [$this->item(quantity: 2, unitPrice: 20)]])
            ->assertCreated()
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.event_occurrence_id', $this->occurrenceId)
            ->json('data');

        $this->assertSame(32, strlen($link['token']));

        $this->getJson("/public/events/{$this->eventId}/sale-links/{$link['token']}")
            ->assertOk()
            ->assertJsonPath('data.is_usable', true)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonMissingPath('data.note');

        $shortId = $this->openLink($link['token'], 'buyer-session')->assertCreated()->json('data.short_id');

        $order = DB::table('orders')->where('short_id', $shortId)->first();
        $this->assertSame($link['id'], $order->sale_link_id);
        $this->assertSame('buyer-session', $order->session_id);
        $this->assertEquals(40.0, (float) $order->total_gross);
        $this->assertEquals(20.0, (float) DB::table('order_items')->where('order_id', $order->id)->value('price'));
    }

    public function test_opening_the_link_again_in_the_same_session_returns_the_same_order(): void
    {
        $token = $this->createLink()->json('data.token');

        $first = $this->openLink($token, 'buyer-session')->assertCreated()->json('data.short_id');
        $second = $this->openLink($token, 'buyer-session')->assertCreated()->json('data.short_id');

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('orders')->where('session_id', 'buyer-session')->count());
    }

    public function test_a_single_use_link_cannot_be_opened_by_a_second_buyer_while_the_first_holds_it(): void
    {
        $token = $this->createLink()->json('data.token');

        $this->openLink($token, 'first-session')->assertCreated();
        $this->openLink($token, 'second-session')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sale_link']);
    }

    public function test_a_completed_order_uses_up_the_link(): void
    {
        $token = $this->createLink(['items' => [$this->item(quantity: 1, unitPrice: 0)]])->json('data.token');
        $shortId = $this->openLink($token, 'buyer-session')->json('data.short_id');

        $this->completeOrder($shortId, 'buyer-session', 'buyer@example.test')->assertOk();

        $this->assertSame(OrderStatus::COMPLETED->name, DB::table('orders')->where('short_id', $shortId)->value('status'));
        $this->assertSame(1, (int) DB::table('sale_links')->where('token', $token)->value('uses_count'));
        $this->getJson("/public/events/{$this->eventId}/sale-links/{$token}")
            ->assertJsonPath('data.is_usable', false)
            ->assertJsonPath('data.unavailable_reason', 'USED');
        $this->openLink($token, 'other-session')->assertUnprocessable();
    }

    public function test_a_link_ignores_the_ticket_cap_when_asked(): void
    {
        DB::table('product_prices')->where('id', $this->priceId)->update(['quantity_sold' => 10]);

        $bypassing = $this->createLink(['bypass_capacity' => true])->json('data.token');
        $strict = $this->createLink(['bypass_capacity' => false])->json('data.token');

        $this->openLink($strict, 'strict-session')->assertUnprocessable()->assertJsonValidationErrors(['sale_link']);
        $this->openLink($bypassing, 'bypass-session')->assertCreated();
    }

    public function test_a_link_that_does_not_count_toward_the_cap_leaves_public_availability_alone(): void
    {
        $token = $this->createLink([
            'items' => [$this->item(quantity: 3, unitPrice: 0)],
            'count_toward_cap' => false,
        ])->json('data.token');

        $shortId = $this->openLink($token, 'buyer-session')->json('data.short_id');
        $this->assertFalse((bool) DB::table('orders')->where('short_id', $shortId)->value('counts_toward_capacity'));

        $this->completeOrder($shortId, 'buyer-session', 'buyer@example.test', quantity: 3)->assertOk();

        $this->assertSame(0, (int) DB::table('product_prices')->where('id', $this->priceId)->value('quantity_sold'));
    }

    public function test_a_link_that_counts_toward_the_cap_increases_quantity_sold(): void
    {
        $token = $this->createLink(['items' => [$this->item(quantity: 3, unitPrice: 0)]])->json('data.token');

        $shortId = $this->openLink($token, 'buyer-session')->json('data.short_id');
        $this->completeOrder($shortId, 'buyer-session', 'buyer@example.test', quantity: 3)->assertOk();

        $this->assertSame(3, (int) DB::table('product_prices')->where('id', $this->priceId)->value('quantity_sold'));
    }

    public function test_expired_and_revoked_links_cannot_be_opened(): void
    {
        $expired = $this->createLink()->json('data');
        DB::table('sale_links')->where('id', $expired['id'])->update(['expires_at' => now()->subMinute()]);

        $revoked = $this->createLink()->json('data');
        $this->postJson("/events/{$this->eventId}/sale-links/{$revoked['id']}/revoke", [], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', 'REVOKED');

        $this->openLink($expired['token'], 'session-a')->assertUnprocessable();
        $this->openLink($revoked['token'], 'session-b')->assertUnprocessable();
        $this->getJson("/public/events/{$this->eventId}/sale-links/{$expired['token']}")->assertJsonPath('data.unavailable_reason', 'EXPIRED');
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->getJson("/public/events/{$this->eventId}/sale-links/nope")->assertNotFound();
        $this->openLink('nope', 'session')->assertNotFound();
    }

    public function test_a_price_that_includes_tax_is_backed_out_before_tax_is_added(): void
    {
        $taxId = DB::table('taxes_and_fees')->insertGetId([
            'account_id' => $this->accountId,
            'name' => 'VAT',
            'calculation_type' => 'PERCENTAGE',
            'type' => 'TAX',
            'rate' => 25,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('product_taxes_and_fees')->insert(['product_id' => $this->productId, 'tax_and_fee_id' => $taxId]);

        $token = $this->createLink([
            'items' => [$this->item(quantity: 1, unitPrice: 50)],
            'price_includes_tax' => true,
        ])->json('data.token');

        $shortId = $this->openLink($token, 'buyer-session')->json('data.short_id');
        $order = DB::table('orders')->where('short_id', $shortId)->first();

        $this->assertEquals(40.0, (float) $order->total_before_additions);
        $this->assertEquals(10.0, (float) $order->total_tax);
        $this->assertEquals(50.0, (float) $order->total_gross);
    }

    public function test_a_promo_code_only_attributes_unless_the_link_applies_its_discount(): void
    {
        $promoCodeId = DB::table('promo_codes')->insertGetId([
            'event_id' => $this->eventId,
            'code' => 'friend',
            'discount' => 10,
            'discount_type' => 'PERCENTAGE',
            'applicable_product_ids' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $attribution = $this->createLink(['promo_code_id' => $promoCodeId])->json('data.token');
        $discount = $this->createLink(['promo_code_id' => $promoCodeId, 'promo_mode' => 'APPLY_DISCOUNT'])->json('data.token');

        $attributedOrder = DB::table('orders')->where('short_id', $this->openLink($attribution, 'session-a')->json('data.short_id'))->first();
        $discountedOrder = DB::table('orders')->where('short_id', $this->openLink($discount, 'session-b')->json('data.short_id'))->first();

        $this->assertSame($promoCodeId, $attributedOrder->promo_code_id);
        $this->assertEquals(20.0, (float) $attributedOrder->total_gross);
        $this->assertSame($promoCodeId, $discountedOrder->promo_code_id);
        $this->assertEquals(18.0, (float) $discountedOrder->total_gross);
    }

    public function test_the_order_is_attributed_to_the_links_affiliate(): void
    {
        $affiliateId = DB::table('affiliates')->insertGetId([
            'event_id' => $this->eventId,
            'account_id' => $this->accountId,
            'name' => 'Promoter',
            'code' => 'PROMO1',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $this->createLink(['affiliate_id' => $affiliateId])->json('data.token');
        $shortId = $this->openLink($token, 'buyer-session')->json('data.short_id');

        $this->assertSame($affiliateId, DB::table('orders')->where('short_id', $shortId)->value('affiliate_id'));
    }

    public function test_a_link_with_several_items_creates_one_order_with_every_item(): void
    {
        $secondProduct = $this->insertProduct();
        $secondPrice = $this->insertPrice($secondProduct, initialQuantity: 10, appliesTo: ProductQuantityAppliesTo::EVENT->name, price: 30.00);

        $token = $this->createLink(['items' => [
            $this->item(quantity: 2, unitPrice: 15),
            ['product_id' => $secondProduct, 'product_price_id' => $secondPrice, 'quantity' => 1, 'unit_price' => 5],
        ]])->json('data.token');

        $shortId = $this->openLink($token, 'buyer-session')->json('data.short_id');
        $order = DB::table('orders')->where('short_id', $shortId)->first();

        $this->assertSame(2, DB::table('order_items')->where('order_id', $order->id)->count());
        $this->assertEquals(35.0, (float) $order->total_gross);
    }

    public function test_a_locked_email_must_be_used_at_checkout(): void
    {
        $token = $this->createLink([
            'items' => [$this->item(quantity: 1, unitPrice: 0)],
            'email' => 'Vip@Example.test',
            'email_locked' => true,
        ])->json('data.token');

        $shortId = $this->openLink($token, 'buyer-session')->json('data.short_id');

        $this->completeOrder($shortId, 'buyer-session', 'someone@example.test')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order.email']);
        $this->completeOrder($shortId, 'buyer-session', 'vip@example.test')->assertOk();
    }

    public function test_items_must_belong_to_the_event(): void
    {
        $otherEvent = $this->insertEvent(EventType::SINGLE->name);
        $originalEvent = $this->eventId;
        $this->eventId = $otherEvent;
        $foreignProduct = $this->insertProduct();
        $foreignPrice = $this->insertPrice($foreignProduct, initialQuantity: 10);
        $this->eventId = $originalEvent;

        $this->createLink(['items' => [['product_id' => $foreignProduct, 'product_price_id' => $foreignPrice, 'quantity' => 1, 'unit_price' => 1]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_price_id']);
    }

    public function test_listing_links_requires_authentication(): void
    {
        $this->getJson("/events/{$this->eventId}/sale-links")->assertUnauthorized();

        $this->createLink();
        $this->getJson("/events/{$this->eventId}/sale-links", $this->authHeaders())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    private function item(int $quantity = 1, float $unitPrice = 20): array
    {
        return [
            'product_id' => $this->productId,
            'product_price_id' => $this->priceId,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ];
    }

    private function createLink(array $overrides = []): TestResponse
    {
        return $this->postJson("/events/{$this->eventId}/sale-links", array_merge([
            'items' => [$this->item()],
        ], $overrides), $this->authHeaders());
    }

    private function authHeaders(): array
    {
        $this->app['auth']->forgetGuards();

        $token = JWTAuth::claims(['account_id' => $this->accountId])->fromUser(User::find($this->userId));

        return ['Authorization' => 'Bearer '.$token];
    }

    private function openLink(string $token, string $session): TestResponse
    {
        $this->app['auth']->forgetGuards();
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $route->flushController();
        }

        return $this->postJson("/public/events/{$this->eventId}/sale-links/{$token}/order?session_identifier={$session}");
    }

    private function completeOrder(string $shortId, string $session, string $email, int $quantity = 1): TestResponse
    {
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $route->flushController();
        }

        return $this->putJson("/public/events/{$this->eventId}/order/{$shortId}?session_identifier={$session}", [
            'order' => [
                'first_name' => 'Ada',
                'last_name' => 'Buyer',
                'email' => $email,
                'email_confirmation' => $email,
                'address' => [
                    'address_line_1' => '1 Main St',
                    'city' => 'Montreal',
                    'country' => 'CA',
                ],
            ],
            'products' => array_fill(0, $quantity, [
                'product_id' => $this->productId,
                'product_price_id' => $this->priceId,
                'first_name' => 'Ada',
                'last_name' => 'Buyer',
                'email' => $email,
                'email_confirmation' => $email,
            ]),
        ]);
    }
}
