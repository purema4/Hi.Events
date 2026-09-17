<?php

namespace HiEvents\Http\Actions\Wallet\Public;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Wallet\OrderWalletPassesResource;
use HiEvents\Services\Application\Handlers\Wallet\GetOrderWalletPassesPublicHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class GetOrderWalletPassesPublicAction extends BaseAction
{
    public function __construct(
        private readonly GetOrderWalletPassesPublicHandler $handler,
    ) {}

    public function __invoke(int $eventId, string $orderShortId): JsonResponse
    {
        try {
            $passes = $this->handler->handle($eventId, $orderShortId);
        } catch (ResourceNotFoundException $exception) {
            return $this->errorResponse($exception->getMessage(), 404);
        }

        return $this->resourceResponse(OrderWalletPassesResource::class, $passes);
    }
}
