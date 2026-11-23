<?php

declare(strict_types=1);

namespace HiEvents\Resources\SaleLink;

use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\SaleLinkItemDomainObject;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin SaleLinkItemDomainObject
 */
class SaleLinkItemResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'product_id' => $this->getProductId(),
            'product_price_id' => $this->getProductPriceId(),
            'quantity' => $this->getQuantity(),
            'unit_price' => $this->when($this->isPermitted(Permission::VIEW_FINANCIALS), fn () => $this->getUnitPrice()),
            'product_title' => $this->getProduct()?->getTitle(),
            'price_label' => $this->getProduct()?->isTieredType()
                ? $this->getProduct()->getPriceById($this->getProductPriceId())?->getLabel()
                : null,
        ];
    }
}
