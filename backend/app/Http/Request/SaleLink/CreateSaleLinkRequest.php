<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\SaleLink;

use HiEvents\DomainObjects\Enums\SaleLinkPromoMode;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class CreateSaleLinkRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'event_occurrence_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_price_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'price_includes_tax' => ['boolean'],
            'promo_code_id' => ['nullable', 'integer'],
            'promo_mode' => [Rule::in(SaleLinkPromoMode::valuesArray())],
            'affiliate_id' => ['nullable', 'integer'],
            'bypass_capacity' => ['boolean'],
            'count_toward_cap' => ['boolean'],
            'max_uses' => ['integer', 'min:1', 'max:10000'],
            'expires_at' => ['nullable', 'date'],
            'email' => ['nullable', 'email', 'max:255'],
            'email_locked' => ['boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
