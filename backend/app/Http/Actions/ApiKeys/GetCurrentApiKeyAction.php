<?php

namespace HiEvents\Http\Actions\ApiKeys;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Repository\Interfaces\PersonalAccessTokenRepositoryInterface;
use HiEvents\Resources\ApiKey\CurrentApiKeyResource;
use HiEvents\Services\Domain\Auth\AuthUserService;
use Illuminate\Http\JsonResponse;

class GetCurrentApiKeyAction extends BaseAction
{
    public function __construct(
        private readonly AuthUserService $authUserService,
        private readonly PersonalAccessTokenRepositoryInterface $personalAccessTokenRepository,
    ) {}

    public function __invoke(): JsonResponse
    {
        $accessToken = $this->authUserService->getAccessToken();

        if ($accessToken === null) {
            return $this->errorResponse(
                message: __('This route can only be called with an API key.'),
                statusCode: ResponseCodes::HTTP_FORBIDDEN,
            );
        }

        return $this->resourceResponse(
            resource: CurrentApiKeyResource::class,
            data: $this->personalAccessTokenRepository->findById($accessToken->getKey()),
        );
    }
}
