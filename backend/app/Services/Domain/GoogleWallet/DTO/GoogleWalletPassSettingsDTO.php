<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\GoogleWallet\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class GoogleWalletPassSettingsDTO extends BaseDataObject
{
    public function __construct(
        public readonly ?string $logoUrl,
        public readonly ?string $heroImageUrl,
        public readonly ?string $backgroundColor,
        public readonly ?string $themeAccentColor,
    ) {}
}
