<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\AppleWallet\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class AppleWalletPassSettingsDTO extends BaseDataObject
{
    public function __construct(
        public readonly ?string $logoUrl,
        public readonly ?string $stripImageUrl,
        public readonly ?string $backgroundColor,
    ) {}
}
