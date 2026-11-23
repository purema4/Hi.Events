<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\SaleLink;

use HiEvents\DomainObjects\Enums\PromoCodeDiscountTypeEnum;
use HiEvents\DomainObjects\Enums\SaleLinkPromoMode;
use HiEvents\DomainObjects\Enums\TaxCalculationType;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\PromoCodeDomainObject;
use HiEvents\DomainObjects\SaleLinkDomainObject;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Helper\Currency;
use Illuminate\Support\Collection;

class SaleLinkPriceService
{
    public function getUnitPrice(
        SaleLinkDomainObject $saleLink,
        ProductDomainObject $product,
        float $unitPrice,
        ?PromoCodeDomainObject $promoCode,
    ): float {
        $price = $saleLink->getPriceIncludesTax()
            ? $this->removeTaxesAndFees($product, $unitPrice)
            : $unitPrice;

        if ($saleLink->getPromoMode() === SaleLinkPromoMode::APPLY_DISCOUNT->value) {
            $price = $this->applyItemDiscount($product, $price, $promoCode);
        }

        return Currency::round(max(0.0, $price));
    }

    private function removeTaxesAndFees(ProductDomainObject $product, float $grossPrice): float
    {
        if ($grossPrice <= 0) {
            return 0.0;
        }

        [$fixedFees, $percentFees] = $this->sumRates($product->getFees());
        [$fixedTaxes, $percentTaxes] = $this->sumRates($product->getTaxRates());

        $priceWithFees = ($grossPrice - $fixedTaxes) / (1 + $percentTaxes / 100);

        return ($priceWithFees - $fixedFees) / (1 + $percentFees / 100);
    }

    /**
     * @param  Collection<TaxAndFeesDomainObject>|null  $taxesAndFees
     * @return array{0: float, 1: float}
     */
    private function sumRates(?Collection $taxesAndFees): array
    {
        $fixed = 0.0;
        $percent = 0.0;

        foreach ($taxesAndFees ?? [] as $taxOrFee) {
            if ($taxOrFee->getCalculationType() === TaxCalculationType::FIXED->name) {
                $fixed += $taxOrFee->getRate();
            } else {
                $percent += $taxOrFee->getRate();
            }
        }

        return [$fixed, $percent];
    }

    private function applyItemDiscount(ProductDomainObject $product, float $price, ?PromoCodeDomainObject $promoCode): float
    {
        if ($promoCode === null
            || ! $promoCode->appliesToProduct($product)
            || $promoCode->isOrderLevelDiscount()
            || $promoCode->getDiscountType() === PromoCodeDiscountTypeEnum::NONE->name) {
            return $price;
        }

        if ($promoCode->isFixedDiscount()) {
            return $price - $promoCode->getDiscount();
        }

        if ($promoCode->isPercentageDiscount()) {
            return $price - ($price * ($promoCode->getDiscount() / 100));
        }

        return $price;
    }
}
