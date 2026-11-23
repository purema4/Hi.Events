<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use Carbon\Carbon;
use HiEvents\DomainObjects\Generated\SaleLinkDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\SaleLinkDomainObject;
use HiEvents\DomainObjects\SaleLinkItemDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Models\Order;
use HiEvents\Models\SaleLink;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\SaleLinkRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepository<SaleLinkDomainObject>
 */
class SaleLinkRepository extends BaseRepository implements SaleLinkRepositoryInterface
{
    protected function getModel(): string
    {
        return SaleLink::class;
    }

    public function getDomainObject(): string
    {
        return SaleLinkDomainObject::class;
    }

    public function loadItemsWithProducts(): static
    {
        return $this->loadRelation(new Relationship(
            domainObject: SaleLinkItemDomainObject::class,
            nested: [
                new Relationship(
                    domainObject: ProductDomainObject::class,
                    nested: [new Relationship(ProductPriceDomainObject::class)],
                    name: 'product',
                ),
            ],
        ));
    }

    public function findByEventId(int $eventId, QueryParamsDTO $params): LengthAwarePaginator
    {
        $where = [
            [SaleLinkDomainObjectAbstract::EVENT_ID, '=', $eventId],
        ];

        if ($params->query) {
            $where[] = static function (Builder $builder) use ($params) {
                $builder
                    ->orWhere(SaleLinkDomainObjectAbstract::EMAIL, 'ilike', '%'.$params->query.'%')
                    ->orWhere(SaleLinkDomainObjectAbstract::NOTE, 'ilike', '%'.$params->query.'%');
            };
        }

        $this->model = $this->model->orderBy(SaleLinkDomainObjectAbstract::CREATED_AT, 'desc');

        return $this
            ->loadItemsWithProducts()
            ->paginateWhere(
                where: $where,
                limit: $params->per_page,
                page: $params->page,
            );
    }

    public function countOrdersHoldingUse(int $saleLinkId, ?string $excludeSessionId = null): int
    {
        return $this->runQuery(fn () => Order::query()
            ->where('sale_link_id', $saleLinkId)
            ->where(function (Builder $query) use ($excludeSessionId) {
                $query
                    ->whereIn('status', [OrderStatus::COMPLETED->name, OrderStatus::AWAITING_OFFLINE_PAYMENT->name])
                    ->orWhere(function (Builder $query) use ($excludeSessionId) {
                        $query
                            ->where('status', OrderStatus::RESERVED->name)
                            ->where('reserved_until', '>', Carbon::now())
                            ->when($excludeSessionId !== null, fn (Builder $query) => $query->where('session_id', '!=', $excludeSessionId));
                    });
            })
            ->count());
    }

    public function refreshUsesCount(int $saleLinkId): void
    {
        $this->runQuery(fn () => SaleLink::query()
            ->where('id', $saleLinkId)
            ->update([
                SaleLinkDomainObjectAbstract::USES_COUNT => Order::query()
                    ->where('sale_link_id', $saleLinkId)
                    ->whereIn('status', [OrderStatus::COMPLETED->name, OrderStatus::AWAITING_OFFLINE_PAYMENT->name])
                    ->selectRaw('COUNT(*)'),
            ]));
    }
}
