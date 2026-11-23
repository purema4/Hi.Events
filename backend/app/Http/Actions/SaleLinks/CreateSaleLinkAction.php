<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\SaleLinks;

use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\Enums\SaleLinkPromoMode;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Attributes\RequiresPermission;
use HiEvents\Http\Request\SaleLink\CreateSaleLinkRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\SaleLink\SaleLinkResource;
use HiEvents\Services\Application\Handlers\SaleLink\CreateSaleLinkHandler;
use HiEvents\Services\Application\Handlers\SaleLink\DTO\CreateSaleLinkDTO;
use HiEvents\Services\Application\Handlers\SaleLink\DTO\SaleLinkItemDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

#[RequiresPermission(Permission::VIEW_FINANCIALS)]
class CreateSaleLinkAction extends BaseAction
{
    public function __construct(
        private readonly CreateSaleLinkHandler $createSaleLinkHandler,
    ) {}

    /**
     * Create Sale Link
     *
     * Creates a private link that sells the given tickets at custom prices. The order is
     * created when the buyer opens the link and continues to checkout.
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function __invoke(CreateSaleLinkRequest $request, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $saleLink = $this->createSaleLinkHandler->handle(new CreateSaleLinkDTO(
            event_id: $eventId,
            user_id: $this->getAuthenticatedUser()->getId(),
            items: array_map(static fn (array $item) => new SaleLinkItemDTO(
                product_id: (int) $item['product_id'],
                product_price_id: (int) $item['product_price_id'],
                quantity: (int) $item['quantity'],
                unit_price: (float) $item['unit_price'],
            ), $request->validated('items')),
            event_occurrence_id: $request->validated('event_occurrence_id'),
            price_includes_tax: $request->boolean('price_includes_tax'),
            promo_code_id: $request->validated('promo_code_id'),
            promo_mode: SaleLinkPromoMode::from($request->validated('promo_mode') ?? SaleLinkPromoMode::ATTRIBUTION_ONLY->value),
            affiliate_id: $request->validated('affiliate_id'),
            bypass_capacity: $request->boolean('bypass_capacity', true),
            count_toward_cap: $request->boolean('count_toward_cap', true),
            max_uses: (int) ($request->validated('max_uses') ?? 1),
            expires_at: $request->validated('expires_at'),
            email: $request->validated('email'),
            email_locked: $request->boolean('email_locked'),
            note: $request->validated('note'),
        ));

        return $this->resourceResponse(
            resource: SaleLinkResource::class,
            data: $saleLink,
            statusCode: ResponseCodes::HTTP_CREATED,
        );
    }
}
