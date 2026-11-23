<?php

namespace Tests\Unit\DomainObjects;

use HiEvents\DomainObjects\Enums\ProductQuantityAppliesTo;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use Tests\TestCase;

class ProductPriceDomainObjectSoldOutTest extends TestCase
{
    public function test_resolved_quantity_available_is_authoritative(): void
    {
        $price = $this->price(initial: 1, sold: 1, appliesTo: ProductQuantityAppliesTo::OCCURRENCE)->setQuantityAvailable(1);
        $this->assertFalse($price->isSoldOut());

        $price = $this->price(initial: 10, sold: 0, appliesTo: ProductQuantityAppliesTo::EVENT)->setQuantityAvailable(0);
        $this->assertTrue($price->isSoldOut());
    }

    public function test_event_wide_tier_falls_back_to_counters(): void
    {
        $this->assertTrue($this->price(initial: 5, sold: 5, appliesTo: ProductQuantityAppliesTo::EVENT)->isSoldOut());
        $this->assertFalse($this->price(initial: 5, sold: 4, appliesTo: ProductQuantityAppliesTo::EVENT)->isSoldOut());
        $this->assertFalse($this->price(initial: null, sold: 99, appliesTo: ProductQuantityAppliesTo::EVENT)->isSoldOut());
    }

    public function test_per_date_tier_never_reports_sold_out_from_global_counters(): void
    {
        $this->assertFalse($this->price(initial: 1, sold: 3, appliesTo: ProductQuantityAppliesTo::OCCURRENCE)->isSoldOut());
    }

    public function test_sold_out_tier_with_unpaid_holds_is_on_hold(): void
    {
        $price = $this->price(initial: 20, sold: 8, appliesTo: ProductQuantityAppliesTo::EVENT)
            ->setQuantityAvailable(0)
            ->setQuantityReserved(12);

        $this->assertTrue($price->isSoldOut());
        $this->assertTrue($price->isOnHold());
    }

    public function test_tier_is_not_on_hold_when_fully_paid_or_still_available(): void
    {
        $this->assertFalse($this->price(initial: 20, sold: 20, appliesTo: ProductQuantityAppliesTo::EVENT)
            ->setQuantityAvailable(0)
            ->isOnHold());

        $this->assertFalse($this->price(initial: 20, sold: 8, appliesTo: ProductQuantityAppliesTo::EVENT)
            ->setQuantityAvailable(4)
            ->setQuantityReserved(8)
            ->isOnHold());

        $this->assertFalse($this->price(initial: 20, sold: 8, appliesTo: ProductQuantityAppliesTo::EVENT)
            ->setQuantityAvailable(0)
            ->isOnHold());
    }

    public function test_per_date_tier_with_holds_is_on_hold_when_sold_out(): void
    {
        $this->assertTrue($this->price(initial: 5, sold: 5, appliesTo: ProductQuantityAppliesTo::OCCURRENCE)
            ->setQuantityAvailable(0)
            ->setQuantityReserved(2)
            ->isOnHold());
    }

    private function price(?int $initial, int $sold, ProductQuantityAppliesTo $appliesTo): ProductPriceDomainObject
    {
        return (new ProductPriceDomainObject)
            ->setInitialQuantityAvailable($initial)
            ->setQuantitySold($sold)
            ->setQuantityAppliesTo($appliesTo->name);
    }
}
