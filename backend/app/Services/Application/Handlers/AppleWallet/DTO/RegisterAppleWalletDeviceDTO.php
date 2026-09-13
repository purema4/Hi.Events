<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\AppleWallet\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class RegisterAppleWalletDeviceDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $passTypeIdentifier,
        public readonly string $serialNumber,
        public readonly ?string $authenticationToken,
        public readonly string $deviceLibraryIdentifier,
        public readonly string $pushToken,
    ) {}
}
