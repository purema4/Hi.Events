<?php

namespace HiEvents\Http\Actions\ApiKeys;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\ApiKey\DeleteApiKeyHandler;
use Illuminate\Http\Response;

class DeleteApiKeyAction extends BaseAction
{
    public function __construct(
        private readonly DeleteApiKeyHandler $deleteApiKeyHandler,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function __invoke(int $accountId, int $apiKeyId): Response
    {
        $this->isActionAuthorized($accountId, AccountDomainObject::class, Role::ADMIN);

        $this->deleteApiKeyHandler->handle(
            apiKeyId: $apiKeyId,
            accountId: $this->getAuthenticatedAccountId(),
            userId: $this->getAuthenticatedUser()->getId(),
        );

        return $this->deletedResponse();
    }
}
