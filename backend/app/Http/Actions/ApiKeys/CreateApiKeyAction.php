<?php

namespace HiEvents\Http\Actions\ApiKeys;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\ApiKey\CreateApiKeyRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\ApiKey\ApiKeyResourceWithToken;
use HiEvents\Services\Application\Handlers\ApiKey\CreateApiKeyHandler;
use HiEvents\Services\Application\Handlers\ApiKey\DTO\CreateApiKeyDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CreateApiKeyAction extends BaseAction
{
    public function __construct(
        private readonly CreateApiKeyHandler $createApiKeyHandler,
    ) {}

    public function __invoke(CreateApiKeyRequest $request, int $accountId): JsonResponse
    {
        $this->isActionAuthorized($accountId, AccountDomainObject::class, Role::ADMIN);

        if ((bool) Auth::payload()->get('is_impersonating', false)) {
            return $this->errorResponse(
                message: __('API keys cannot be created while impersonating a user.'),
                statusCode: ResponseCodes::HTTP_FORBIDDEN,
            );
        }

        $apiKey = $this->createApiKeyHandler->handle(new CreateApiKeyDTO(
            name: $request->validated('name'),
            abilities: $request->validated('abilities'),
            accountId: $this->getAuthenticatedAccountId(),
            userId: $this->getAuthenticatedUser()->getId(),
            expiresAt: $request->validated('expires_at'),
        ));

        return $this->resourceResponse(
            resource: ApiKeyResourceWithToken::class,
            data: $apiKey,
            statusCode: ResponseCodes::HTTP_CREATED,
        );
    }
}
