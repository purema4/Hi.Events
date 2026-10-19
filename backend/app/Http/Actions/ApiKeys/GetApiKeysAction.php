<?php

namespace HiEvents\Http\Actions\ApiKeys;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Generated\PersonalAccessTokenDomainObjectAbstract;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\PersonalAccessTokenRepositoryInterface;
use HiEvents\Resources\ApiKey\ApiKeyResource;
use Illuminate\Http\JsonResponse;

class GetApiKeysAction extends BaseAction
{
    public function __construct(
        private readonly PersonalAccessTokenRepositoryInterface $personalAccessTokenRepository,
    ) {}

    public function __invoke(int $accountId): JsonResponse
    {
        $this->isActionAuthorized($accountId, AccountDomainObject::class, Role::ADMIN);

        $apiKeys = $this->personalAccessTokenRepository
            ->loadRelation(new Relationship(UserDomainObject::class, name: 'user'))
            ->findWhere(
                where: [PersonalAccessTokenDomainObjectAbstract::ACCOUNT_ID => $this->getAuthenticatedAccountId()],
                orderAndDirections: [new OrderAndDirection(PersonalAccessTokenDomainObjectAbstract::ID, OrderAndDirection::DIRECTION_DESC)],
            );

        return $this->resourceResponse(ApiKeyResource::class, $apiKeys);
    }
}
