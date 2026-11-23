<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\SaleLink;

use HiEvents\DomainObjects\Generated\SaleLinkDomainObjectAbstract;
use HiEvents\DomainObjects\SaleLinkDomainObject;
use HiEvents\DomainObjects\Status\SaleLinkStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\SaleLinkRepositoryInterface;

class RevokeSaleLinkHandler
{
    public function __construct(
        private readonly SaleLinkRepositoryInterface $saleLinkRepository,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $eventId, int $saleLinkId): SaleLinkDomainObject
    {
        $saleLink = $this->saleLinkRepository->findFirstWhere([
            SaleLinkDomainObjectAbstract::ID => $saleLinkId,
            SaleLinkDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        if ($saleLink === null) {
            throw new ResourceNotFoundException(__('Sale link not found'));
        }

        $this->saleLinkRepository->updateFromArray($saleLinkId, [
            SaleLinkDomainObjectAbstract::STATUS => SaleLinkStatus::REVOKED->name,
        ]);

        return $this->saleLinkRepository
            ->loadItemsWithProducts()
            ->findById($saleLinkId);
    }
}
