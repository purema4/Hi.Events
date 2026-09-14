<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Wallet\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class WalletPassBrandingDTO extends BaseDataObject
{
    public function __construct(
        public readonly ?string $logoUrl,
        public readonly ?string $bannerImageUrl,
        public readonly ?string $backgroundColor,
    ) {}
}
