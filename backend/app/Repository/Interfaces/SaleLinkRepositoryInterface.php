<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\SaleLinkDomainObject;
use HiEvents\Http\DTO\QueryParamsDTO;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends RepositoryInterface<SaleLinkDomainObject>
 */
interface SaleLinkRepositoryInterface extends RepositoryInterface
{
    public function loadItemsWithProducts(): static;

    public function findByEventId(int $eventId, QueryParamsDTO $params): LengthAwarePaginator;

    public function countOrdersHoldingUse(int $saleLinkId, ?string $excludeSessionId = null): int;

    public function refreshUsesCount(int $saleLinkId): void;
}
