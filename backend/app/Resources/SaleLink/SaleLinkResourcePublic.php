<?php

declare(strict_types=1);

namespace HiEvents\Resources\SaleLink;

use Carbon\Carbon;
use HiEvents\DomainObjects\SaleLinkDomainObject;
use HiEvents\DomainObjects\SaleLinkItemDomainObject;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin SaleLinkDomainObject
 */
class SaleLinkResourcePublic extends BaseResource
{
    public function toArray(Request $request): array
    {
        $isUsable = $this->isUsable();

        return [
            'token' => $this->getToken(),
            'event_id' => $this->getEventId(),
            'event_occurrence_id' => $this->getEventOccurrenceId(),
            'currency' => $this->getEvent()?->getCurrency(),
            'expires_at' => $this->getExpiresAt() === null ? null : Carbon::parse($this->getExpiresAt())->toIso8601String(),
            'email' => $this->getEmail(),
            'email_locked' => $this->getEmailLocked(),
            'price_includes_tax' => $this->getPriceIncludesTax(),
            'is_usable' => $isUsable,
            /** @var 'EXPIRED'|'REVOKED'|'USED'|null */
            'unavailable_reason' => $isUsable ? null : $this->getDerivedStatus()->value,
            'items' => ($this->getSaleLinkItems() ?? collect())
                ->map(fn (SaleLinkItemDomainObject $item) => [
                    'product_id' => $item->getProductId(),
                    'product_price_id' => $item->getProductPriceId(),
                    'product_title' => $item->getProduct()?->getTitle(),
                    'price_label' => $item->getProduct()?->isTieredType()
                        ? $item->getProduct()->getPriceById($item->getProductPriceId())?->getLabel()
                        : null,
                    'quantity' => $item->getQuantity(),
                    'unit_price' => $item->getUnitPrice(),
                ])
                ->values()
                ->all(),
        ];
    }
}
