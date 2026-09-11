<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\GoogleWallet\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class GoogleWalletCredentialsDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $clientEmail,
        public readonly string $privateKey,
    ) {}
}
