<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\SaleLinks;

use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Attributes\RequiresPermission;
use HiEvents\Resources\SaleLink\SaleLinkResource;
use HiEvents\Services\Application\Handlers\SaleLink\RevokeSaleLinkHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

#[RequiresPermission(Permission::VIEW_FINANCIALS)]
class RevokeSaleLinkAction extends BaseAction
{
    public function __construct(
        private readonly RevokeSaleLinkHandler $revokeSaleLinkHandler,
    ) {}

    /**
     * Revoke Sale Link
     *
     * Stops the link from creating new orders. Orders already placed through it are not affected.
     */
    public function __invoke(int $eventId, int $saleLinkId): JsonResponse|Response
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        try {
            $saleLink = $this->revokeSaleLinkHandler->handle($eventId, $saleLinkId);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(
            resource: SaleLinkResource::class,
            data: $saleLink,
        );
    }
}
