<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\AppleWallet\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class AppleWalletCredentialsDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $certificate,
        public readonly string $privateKey,
        public readonly string $wwdrCertificate,
    ) {}
}
