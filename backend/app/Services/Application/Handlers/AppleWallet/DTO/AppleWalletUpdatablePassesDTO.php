<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\AppleWallet\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class AppleWalletUpdatablePassesDTO extends BaseDataObject
{
    /**
     * @param  array<int, string>  $serialNumbers
     */
    public function __construct(
        public readonly array $serialNumbers,
        public readonly string $lastUpdated,
    ) {}
}
