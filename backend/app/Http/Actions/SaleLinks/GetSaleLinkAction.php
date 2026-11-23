<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\SaleLinks;

use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\SaleLinkDomainObjectAbstract;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Attributes\RequiresPermission;
use HiEvents\Repository\Interfaces\SaleLinkRepositoryInterface;
use HiEvents\Resources\SaleLink\SaleLinkResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

#[RequiresPermission(Permission::VIEW_FINANCIALS)]
class GetSaleLinkAction extends BaseAction
{
    public function __construct(
        private readonly SaleLinkRepositoryInterface $saleLinkRepository,
    ) {}

    /**
     * Get Sale Link
     */
    public function __invoke(int $eventId, int $saleLinkId): JsonResponse|Response
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $saleLink = $this->saleLinkRepository
            ->loadItemsWithProducts()
            ->findFirstWhere([
                SaleLinkDomainObjectAbstract::ID => $saleLinkId,
                SaleLinkDomainObjectAbstract::EVENT_ID => $eventId,
            ]);

        if ($saleLink === null) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(
            resource: SaleLinkResource::class,
            data: $saleLink,
        );
    }
}
