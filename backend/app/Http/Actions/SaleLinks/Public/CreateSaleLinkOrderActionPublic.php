<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\SaleLinks\Public;

use HiEvents\Exceptions\EventNotOpenForOrdersException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SaleLinkUnavailableException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Order\OrderResourcePublic;
use HiEvents\Services\Application\Handlers\SaleLink\CreateSaleLinkOrderHandler;
use HiEvents\Services\Application\Locale\LocaleService;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateSaleLinkOrderActionPublic extends BaseAction
{
    public function __construct(
        private readonly CreateSaleLinkOrderHandler $createSaleLinkOrderHandler,
        private readonly CheckoutSessionManagementService $sessionIdentifierService,
        private readonly LocaleService $localeService,
    ) {}

    /**
     * Create Order From Sale Link
     *
     * Reserves the link's tickets at the link's prices in a new order for the current checkout
     * session. Opening the same link again in the same session returns the open order.
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function __invoke(Request $request, int $eventId, string $token): JsonResponse|Response
    {
        $sessionId = $this->sessionIdentifierService->getSessionId();

        try {
            $order = $this->createSaleLinkOrderHandler->handle(
                eventId: $eventId,
                token: $token,
                sessionId: $sessionId,
                locale: $this->localeService->getLocaleOrDefault($request->getPreferredLanguage()),
            );
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        } catch (SaleLinkUnavailableException $exception) {
            throw ValidationException::withMessages(['sale_link' => $exception->getMessage()]);
        } catch (EventNotOpenForOrdersException $exception) {
            return $this->errorResponse(
                message: $exception->getMessage(),
                statusCode: ResponseCodes::HTTP_FORBIDDEN,
            );
        }

        $order->setSessionIdentifier($sessionId);

        return $this->resourceResponse(
            resource: OrderResourcePublic::class,
            data: $order,
            statusCode: ResponseCodes::HTTP_CREATED,
        )->withCookie(
            cookie: $this->sessionIdentifierService->getSessionCookie(),
        );
    }
}
