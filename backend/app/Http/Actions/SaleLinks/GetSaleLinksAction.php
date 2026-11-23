<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\SaleLinks;

use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Attributes\RequiresPermission;
use HiEvents\Repository\Interfaces\SaleLinkRepositoryInterface;
use HiEvents\Resources\SaleLink\SaleLinkResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission(Permission::VIEW_FINANCIALS)]
class GetSaleLinksAction extends BaseAction
{
    public function __construct(
        private readonly SaleLinkRepositoryInterface $saleLinkRepository,
    ) {}

    /**
     * List Sale Links
     */
    public function __invoke(Request $request, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        return $this->resourceResponse(
            resource: SaleLinkResource::class,
            data: $this->saleLinkRepository->findByEventId($eventId, $this->getPaginationQueryParams($request)),
        );
    }
}
