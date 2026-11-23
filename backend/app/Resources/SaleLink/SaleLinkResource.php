<?php

declare(strict_types=1);

namespace HiEvents\Resources\SaleLink;

use Carbon\Carbon;
use HiEvents\DomainObjects\SaleLinkDomainObject;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin SaleLinkDomainObject
 */
class SaleLinkResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'event_id' => $this->getEventId(),
            'event_occurrence_id' => $this->getEventOccurrenceId(),
            'token' => $this->getToken(),
            /** @var 'ACTIVE'|'USED'|'EXPIRED'|'REVOKED' */
            'status' => $this->getDerivedStatus()->value,
            'price_includes_tax' => $this->getPriceIncludesTax(),
            'promo_code_id' => $this->getPromoCodeId(),
            /** @var 'ATTRIBUTION_ONLY'|'APPLY_DISCOUNT' */
            'promo_mode' => $this->getPromoMode(),
            'affiliate_id' => $this->getAffiliateId(),
            'bypass_capacity' => $this->getBypassCapacity(),
            'count_toward_cap' => $this->getCountTowardCap(),
            'max_uses' => $this->getMaxUses(),
            'uses_count' => $this->getUsesCount(),
            'expires_at' => $this->getExpiresAt() === null ? null : Carbon::parse($this->getExpiresAt())->toIso8601String(),
            'email' => $this->getEmail(),
            'email_locked' => $this->getEmailLocked(),
            'note' => $this->getNote(),
            'items' => SaleLinkItemResource::collection($this->getSaleLinkItems() ?? collect()),
            'created_at' => $this->getCreatedAt(),
            'updated_at' => $this->getUpdatedAt(),
        ];
    }
}
