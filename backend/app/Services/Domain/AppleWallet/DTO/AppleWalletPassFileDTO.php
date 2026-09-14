<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\AppleWallet\DTO;

use Carbon\CarbonInterface;
use HiEvents\DataTransferObjects\BaseDataObject;

class AppleWalletPassFileDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $contents,
        public readonly string $mimeType,
        public readonly string $filename,
        public readonly CarbonInterface $lastModified,
    ) {}
}
