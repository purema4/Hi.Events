<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleLinkItem extends BaseModel
{
    use SoftDeletes;

    protected function getCastMap(): array
    {
        return [
            'unit_price' => 'float',
        ];
    }

    public function sale_link(): BelongsTo
    {
        return $this->belongsTo(SaleLink::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function product_price(): BelongsTo
    {
        return $this->belongsTo(ProductPrice::class);
    }
}
