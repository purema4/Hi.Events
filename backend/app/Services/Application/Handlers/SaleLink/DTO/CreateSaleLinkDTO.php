<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\SaleLink\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\SaleLinkPromoMode;

class CreateSaleLinkDTO extends BaseDataObject
{
    /**
     * @param  SaleLinkItemDTO[]  $items
     */
    public function __construct(
        public readonly int $event_id,
        public readonly int $user_id,
        public readonly array $items,
        public readonly ?int $event_occurrence_id = null,
        public readonly bool $price_includes_tax = false,
        public readonly ?int $promo_code_id = null,
        public readonly SaleLinkPromoMode $promo_mode = SaleLinkPromoMode::ATTRIBUTION_ONLY,
        public readonly ?int $affiliate_id = null,
        public readonly bool $bypass_capacity = true,
        public readonly bool $count_toward_cap = true,
        public readonly int $max_uses = 1,
        public readonly ?string $expires_at = null,
        public readonly ?string $email = null,
        public readonly bool $email_locked = false,
        public readonly ?string $note = null,
    ) {}
}
