<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Wallet\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class OrderWalletPassesDTO extends BaseDataObject
{
    public function __construct(
        public readonly ?string $appleWalletPassUrl,
        public readonly ?string $googleWalletSaveUrl,
    ) {}
}
