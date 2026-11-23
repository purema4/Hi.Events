<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\SaleLink;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Repository\Interfaces\SaleLinkRepositoryInterface;
use Illuminate\Validation\ValidationException;

class SaleLinkEmailLockService
{
    public function __construct(
        private readonly SaleLinkRepositoryInterface $saleLinkRepository,
    ) {}

    /**
     * @throws ValidationException
     */
    public function assertEmailAllowed(OrderDomainObject $order, ?string $email): void
    {
        if ($order->getSaleLinkId() === null) {
            return;
        }

        $saleLink = $this->saleLinkRepository->findFirst($order->getSaleLinkId());

        if ($saleLink === null || ! $saleLink->getEmailLocked() || $saleLink->getEmail() === null) {
            return;
        }

        if (strtolower(trim((string) $email)) !== strtolower($saleLink->getEmail())) {
            throw ValidationException::withMessages([
                'order.email' => __('This link can only be used with :email', ['email' => $saleLink->getEmail()]),
            ]);
        }
    }
}
