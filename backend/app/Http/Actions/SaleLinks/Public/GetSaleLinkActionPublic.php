<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\SaleLinks\Public;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\SaleLinkDomainObjectAbstract;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\SaleLinkRepositoryInterface;
use HiEvents\Resources\SaleLink\SaleLinkResourcePublic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GetSaleLinkActionPublic extends BaseAction
{
    public function __construct(
        private readonly SaleLinkRepositoryInterface $saleLinkRepository,
    ) {}

    /**
     * Get Sale Link
     *
     * Returns the tickets, quantities and prices a sale link offers.
     */
    public function __invoke(int $eventId, string $token): JsonResponse|Response
    {
        $saleLink = $this->saleLinkRepository
            ->loadItemsWithProducts()
            ->loadRelation(new Relationship(EventDomainObject::class, name: 'event'))
            ->findFirstWhere([
                SaleLinkDomainObjectAbstract::EVENT_ID => $eventId,
                SaleLinkDomainObjectAbstract::TOKEN => $token,
            ]);

        if ($saleLink === null) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(
            resource: SaleLinkResourcePublic::class,
            data: $saleLink,
        );
    }
}
