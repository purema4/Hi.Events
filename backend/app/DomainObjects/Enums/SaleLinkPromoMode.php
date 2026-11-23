<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum SaleLinkPromoMode: string
{
    use BaseEnum;

    case ATTRIBUTION_ONLY = 'ATTRIBUTION_ONLY';
    case APPLY_DISCOUNT = 'APPLY_DISCOUNT';
}
