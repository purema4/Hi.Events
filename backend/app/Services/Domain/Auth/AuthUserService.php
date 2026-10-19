<?php

namespace HiEvents\Services\Domain\Auth;

use Exception;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Interfaces\DomainObjectInterface;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Models\User;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Auth\AuthManager;
use Laravel\Sanctum\PersonalAccessToken;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Payload;

readonly class AuthUserService
{
    public function __construct(
        /**
         * @var AuthManager
         */
        private AuthManager $authManager,
        private AccountUserRepositoryInterface $accountUserRepository,
    ) {}

    public function getAccessToken(): ?PersonalAccessToken
    {
        if (! $this->authManager->check()) {
            return null;
        }

        /** @var User $user */
        $user = $this->authManager->user();
        $accessToken = $user->currentAccessToken();

        return $accessToken instanceof PersonalAccessToken ? $accessToken : null;
    }

    public function isApiKeyRequest(): bool
    {
        return $this->getAccessToken() !== null;
    }

    public function getAuthenticatedAccountId(): ?int
    {
        if (! $this->authManager->check()) {
            return null;
        }

        if ($accessToken = $this->getAccessToken()) {
            return $accessToken->account_id;
        }

        try {
            /** @var Payload $payload */
            $payload = $this->authManager->payload();
        } catch (JWTException) {
            return null;
        }

        return $payload->get('account_id');
    }

    public function getAuthenticatedUserRole(): ?Role
    {
        if (! $this->authManager->check()) {
            return null;
        }

        if ($accessToken = $this->getAccessToken()) {
            $accountUser = $this->accountUserRepository->findFirstWhere([
                'user_id' => $accessToken->tokenable_id,
                'account_id' => $accessToken->account_id,
            ]);

            return $accountUser ? Role::tryFrom($accountUser->getRole()) : null;
        }

        try {
            /** @var Payload $payload */
            $payload = $this->authManager->payload();
        } catch (JWTException) {
            return null;
        }

        try {
            return Role::from($payload->get('role'));
        } catch (Exception) {
            return null;
        }
    }

    public function getUser(): UserDomainObject|DomainObjectInterface|null
    {
        /** @var User $user */
        if ($user = $this->authManager->user()) {
            $user = UserDomainObject::hydrateFromModel($user);

            if ($accountId = $this->getAuthenticatedAccountId()) {
                $user->setCurrentAccountUser($this->accountUserRepository->findFirstWhere([
                    'user_id' => $user->getId(),
                    'account_id' => $accountId,
                ]));
            }

            return $user;
        }

        return null;
    }
}
