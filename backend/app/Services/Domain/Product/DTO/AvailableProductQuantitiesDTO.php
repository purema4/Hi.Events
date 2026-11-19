<?php

namespace HiEvents\Services\Domain\Product\DTO;

use HiEvents\Constants;
use HiEvents\DataTransferObjects\BaseDTO;
use HiEvents\DomainObjects\CapacityAssignmentDomainObject;
use Illuminate\Support\Collection;

class AvailableProductQuantitiesDTO extends BaseDTO
{
    public ?int $quantity_purchasable = null;

    public ?int $quantity_purchasable_before_seats = null;

    public function __construct(
        public int $product_id,
        public int $price_id,
        public string $product_title,
        public ?string $price_label,
        public int $quantity_available,
        public int $quantity_reserved,
        public ?int $initial_quantity_available,
        public string $product_type,
        public string $quantity_applies_to = 'EVENT',
        /** @var Collection<CapacityAssignmentDomainObject> */
        public ?Collection $capacities = null,
        public ?int $seats_available = null,
        public ?int $quantity_available_before_seats = null,
        public bool $soft_quantity_limit = false,
    ) {}

    public function purchasableQuantity(): int
    {
        return $this->quantity_purchasable ?? $this->quantity_available;
    }

    public function purchasableQuantityBeforeSeats(): int
    {
        return $this->quantity_purchasable_before_seats
            ?? $this->quantity_available_before_seats
            ?? $this->purchasableQuantity();
    }

    public function capAt(int $limit): void
    {
        $this->quantity_available = min($this->quantity_available, $limit);
        $this->quantity_purchasable = min($this->purchasableQuantity(), $limit);
    }

    public function setOwnQuantity(int $remaining, bool $softQuantityLimit): void
    {
        $this->quantity_available = $remaining;
        $this->quantity_purchasable = $softQuantityLimit && $remaining > 0 ? Constants::INFINITE : $remaining;
    }
}
