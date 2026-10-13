<?php

namespace HiEvents\Services\Application\Handlers\ApiKey;

use HiEvents\DomainObjects\Generated\PersonalAccessTokenDomainObjectAbstract;
use HiEvents\DomainObjects\PersonalAccessTokenDomainObject;
use HiEvents\Models\User;
use HiEvents\Repository\Interfaces\PersonalAccessTokenRepositoryInterface;
use HiEvents\Services\Application\Handlers\ApiKey\DTO\CreateApiKeyDTO;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

class CreateApiKeyHandler
{
    public function __construct(
        private readonly PersonalAccessTokenRepositoryInterface $personalAccessTokenRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(CreateApiKeyDTO $createApiKeyDTO): PersonalAccessTokenDomainObject
    {
        $secret = (new User)->generateTokenString();

        /** @var PersonalAccessTokenDomainObject $apiKey */
        $apiKey = $this->personalAccessTokenRepository->create([
            PersonalAccessTokenDomainObjectAbstract::TOKENABLE_TYPE => User::class,
            PersonalAccessTokenDomainObjectAbstract::TOKENABLE_ID => $createApiKeyDTO->userId,
            PersonalAccessTokenDomainObjectAbstract::ACCOUNT_ID => $createApiKeyDTO->accountId,
            PersonalAccessTokenDomainObjectAbstract::NAME => $createApiKeyDTO->name,
            PersonalAccessTokenDomainObjectAbstract::TOKEN => hash('sha256', $secret),
            PersonalAccessTokenDomainObjectAbstract::PARTIAL_TOKEN => substr($secret, -4),
            PersonalAccessTokenDomainObjectAbstract::ABILITIES => json_encode(array_values($createApiKeyDTO->abilities)),
            PersonalAccessTokenDomainObjectAbstract::EXPIRES_AT => $createApiKeyDTO->expiresAt
                ? Carbon::parse($createApiKeyDTO->expiresAt)->utc()
                : null,
        ]);

        $this->logger->info('API key created', [
            'api_key_id' => $apiKey->getId(),
            'account_id' => $createApiKeyDTO->accountId,
            'user_id' => $createApiKeyDTO->userId,
            'abilities' => $createApiKeyDTO->abilities,
        ]);

        return $apiKey->setPlainTextToken($apiKey->getId().'|'.$secret);
    }
}
