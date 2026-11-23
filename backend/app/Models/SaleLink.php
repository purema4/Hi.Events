<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleLink extends BaseModel
{
    use SoftDeletes;

    protected function getCastMap(): array
    {
        return [
            'price_includes_tax' => 'boolean',
            'bypass_capacity' => 'boolean',
            'count_toward_cap' => 'boolean',
            'email_locked' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function sale_link_items(): HasMany
    {
        return $this->hasMany(SaleLinkItem::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
