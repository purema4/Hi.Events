<?php

namespace Tests\Unit\Services\Domain\SaleLink;

use HiEvents\DomainObjects\Enums\PromoCodeDiscountTypeEnum;
use HiEvents\DomainObjects\Enums\SaleLinkPromoMode;
use HiEvents\DomainObjects\Enums\TaxCalculationType;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\PromoCodeDomainObject;
use HiEvents\DomainObjects\SaleLinkDomainObject;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Services\Domain\SaleLink\SaleLinkPriceService;
use Tests\TestCase;

class SaleLinkPriceServiceTest extends TestCase
{
    private SaleLinkPriceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SaleLinkPriceService;
    }

    public function test_the_price_is_used_as_is_when_tax_is_not_included(): void
    {
        $this->assertSame(25.0, $this->service->getUnitPrice($this->saleLink(), $this->product(), 25.0, null));
    }

    public function test_percentage_tax_is_backed_out_of_a_tax_inclusive_price(): void
    {
        $product = $this->product(taxes: [$this->rate(TaxCalculationType::PERCENTAGE, 15)]);

        $this->assertSame(100.0, $this->service->getUnitPrice($this->saleLink(includesTax: true), $product, 115.0, null));
    }

    public function test_fees_and_taxes_on_fees_are_backed_out(): void
    {
        $product = $this->product(
            taxes: [$this->rate(TaxCalculationType::PERCENTAGE, 10)],
            fees: [$this->rate(TaxCalculationType::FIXED, 2, 'FEE'), $this->rate(TaxCalculationType::PERCENTAGE, 5, 'FEE')],
        );

        $this->assertSame(100.0, $this->service->getUnitPrice($this->saleLink(includesTax: true), $product, 117.7, null));
    }

    public function test_a_promo_code_is_ignored_in_attribution_mode(): void
    {
        $this->assertSame(40.0, $this->service->getUnitPrice($this->saleLink(), $this->product(), 40.0, $this->promoCode(25)));
    }

    public function test_a_percentage_promo_code_discounts_the_link_price_when_applied(): void
    {
        $saleLink = $this->saleLink(mode: SaleLinkPromoMode::APPLY_DISCOUNT);

        $this->assertSame(30.0, $this->service->getUnitPrice($saleLink, $this->product(), 40.0, $this->promoCode(25)));
    }

    public function test_a_fixed_discount_never_makes_the_price_negative(): void
    {
        $saleLink = $this->saleLink(mode: SaleLinkPromoMode::APPLY_DISCOUNT);
        $promoCode = $this->promoCode(50, PromoCodeDiscountTypeEnum::FIXED);

        $this->assertSame(0.0, $this->service->getUnitPrice($saleLink, $this->product(), 40.0, $promoCode));
    }

    private function saleLink(bool $includesTax = false, SaleLinkPromoMode $mode = SaleLinkPromoMode::ATTRIBUTION_ONLY): SaleLinkDomainObject
    {
        return (new SaleLinkDomainObject)
            ->setPriceIncludesTax($includesTax)
            ->setPromoMode($mode->value);
    }

    private function product(array $taxes = [], array $fees = []): ProductDomainObject
    {
        return (new ProductDomainObject)
            ->setId(1)
            ->setTaxAndFees(collect([...$taxes, ...$fees]));
    }

    private function rate(TaxCalculationType $type, float $rate, string $kind = 'TAX'): TaxAndFeesDomainObject
    {
        return (new TaxAndFeesDomainObject)->setCalculationType($type->name)->setRate($rate)->setType($kind);
    }

    private function promoCode(float $discount, PromoCodeDiscountTypeEnum $type = PromoCodeDiscountTypeEnum::PERCENTAGE): PromoCodeDomainObject
    {
        return (new PromoCodeDomainObject)
            ->setDiscount($discount)
            ->setDiscountType($type->name)
            ->setApplicableProductIds([]);
    }
}
