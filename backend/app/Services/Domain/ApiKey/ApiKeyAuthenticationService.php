<?php

namespace HiEvents\Services\Domain\ApiKey;

use HiEvents\DomainObjects\Generated\AccountUserDomainObjectAbstract;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Laravel\Sanctum\PersonalAccessToken;

class ApiKeyAuthenticationService
{
    public function __construct(
        private readonly AccountUserRepositoryInterface $accountUserRepository,
    ) {}

    public function isValid(PersonalAccessToken $accessToken, bool $isValid): bool
    {
        if (! $isValid || $accessToken->account_id === null) {
            return false;
        }

        return $this->accountUserRepository->findFirstWhere([
            AccountUserDomainObjectAbstract::ACCOUNT_ID => $accessToken->account_id,
            AccountUserDomainObjectAbstract::USER_ID => $accessToken->tokenable_id,
            AccountUserDomainObjectAbstract::STATUS => UserStatus::ACTIVE->name,
        ]) !== null;
    }
}
