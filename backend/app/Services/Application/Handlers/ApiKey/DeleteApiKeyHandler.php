<?php

namespace HiEvents\Services\Application\Handlers\ApiKey;

use HiEvents\DomainObjects\Generated\PersonalAccessTokenDomainObjectAbstract;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\PersonalAccessTokenRepositoryInterface;
use Psr\Log\LoggerInterface;

class DeleteApiKeyHandler
{
    public function __construct(
        private readonly PersonalAccessTokenRepositoryInterface $personalAccessTokenRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $apiKeyId, int $accountId, int $userId): void
    {
        $deleted = $this->personalAccessTokenRepository->deleteWhere([
            PersonalAccessTokenDomainObjectAbstract::ID => $apiKeyId,
            PersonalAccessTokenDomainObjectAbstract::ACCOUNT_ID => $accountId,
        ]);

        if ($deleted === 0) {
            throw new ResourceNotFoundException(__('API key not found'));
        }

        $this->logger->info('API key revoked', [
            'api_key_id' => $apiKeyId,
            'account_id' => $accountId,
            'user_id' => $userId,
        ]);
    }
}
