<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SaleLinkItemDomainObject;
use HiEvents\Models\SaleLinkItem;
use HiEvents\Repository\Interfaces\SaleLinkItemRepositoryInterface;

/**
 * @extends BaseRepository<SaleLinkItemDomainObject>
 */
class SaleLinkItemRepository extends BaseRepository implements SaleLinkItemRepositoryInterface
{
    protected function getModel(): string
    {
        return SaleLinkItem::class;
    }

    public function getDomainObject(): string
    {
        return SaleLinkItemDomainObject::class;
    }
}
