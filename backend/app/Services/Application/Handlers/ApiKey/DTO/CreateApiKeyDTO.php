<?php

namespace HiEvents\Services\Application\Handlers\ApiKey\DTO;

use HiEvents\DataTransferObjects\BaseDTO;

class CreateApiKeyDTO extends BaseDTO
{
    public function __construct(
        public string $name,
        public array $abilities,
        public int $accountId,
        public int $userId,
        public ?string $expiresAt = null,
    ) {}
}
