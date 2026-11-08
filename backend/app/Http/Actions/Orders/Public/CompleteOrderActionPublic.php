<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Orders\Public;

use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Order\CompleteOrderRequest;
use HiEvents\Resources\Order\OrderResourcePublic;
use HiEvents\Services\Application\Handlers\Order\CompleteOrderHandler;
use HiEvents\Services\Application\Handlers\Order\DTO\CompleteOrderDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\CompleteOrderOrderDTO;
use HiEvents\Services\Domain\Tracking\DTO\AdTrackingContextDTO;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CompleteOrderActionPublic extends BaseAction
{
    public function __construct(private readonly CompleteOrderHandler $orderService) {}

    /**
     * Complete Order
     *
     * `order.questions` and `products.*.questions` are validated against the questions configured
     * for the event. Every required question that is shown must be answered, so leaving out
     * `order.questions` fails when the event has required order questions. The `order.address`
     * fields become required when the event's settings require a billing address.
     */
    public function __invoke(CompleteOrderRequest $request, int $eventId, string $orderShortId): JsonResponse
    {
        try {
            $order = $this->orderService->handle($orderShortId, CompleteOrderDTO::fromArray([
                'order' => CompleteOrderOrderDTO::fromArray([
                    'first_name' => $request->validated('order.first_name'),
                    'last_name' => $request->validated('order.last_name'),
                    'email' => $request->validated('order.email'),
                    'address' => $request->validated('order.address'),
                    'questions' => $request->has('order.questions')
                        ? $request->input('order.questions')
                        : null,
                    'opted_into_marketing' => $request->boolean('order.opted_into_marketing'),
                ]),
                'products' => $request->input('products'),
                'event_id' => $eventId,
                'ad_tracking_context' => $this->buildAdTrackingContext($request),
            ]));
        } catch (ResourceConflictException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_CONFLICT);
        }

        return $this->resourceResponse(OrderResourcePublic::class, $order);
    }

    private function buildAdTrackingContext(CompleteOrderRequest $request): ?AdTrackingContextDTO
    {
        if (! is_array($request->validated('ad_tracking'))) {
            return null;
        }

        return new AdTrackingContextDTO(
            fbp: $request->validated('ad_tracking.fbp'),
            fbc: $request->validated('ad_tracking.fbc'),
            event_source_url: $request->validated('ad_tracking.event_source_url'),
            client_ip_address: $request->ip(),
            client_user_agent: $request->userAgent(),
        );
    }
}
