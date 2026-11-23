<?php

namespace HiEvents\DomainObjects;

use Carbon\Carbon;
use HiEvents\DomainObjects\Status\SaleLinkStatus;
use Illuminate\Support\Collection;

class SaleLinkDomainObject extends Generated\SaleLinkDomainObjectAbstract
{
    private ?Collection $saleLinkItems = null;

    private ?EventDomainObject $event = null;

    public function setEvent(?EventDomainObject $event): self
    {
        $this->event = $event;

        return $this;
    }

    public function getEvent(): ?EventDomainObject
    {
        return $this->event;
    }

    public function setSaleLinkItems(?Collection $saleLinkItems): self
    {
        $this->saleLinkItems = $saleLinkItems;

        return $this;
    }

    /**
     * @return Collection<SaleLinkItemDomainObject>|null
     */
    public function getSaleLinkItems(): ?Collection
    {
        return $this->saleLinkItems;
    }

    public function getDerivedStatus(): SaleLinkStatus
    {
        if ($this->getStatus() === SaleLinkStatus::REVOKED->name) {
            return SaleLinkStatus::REVOKED;
        }

        if ($this->getUsesCount() >= $this->getMaxUses()) {
            return SaleLinkStatus::USED;
        }

        if ($this->isExpired()) {
            return SaleLinkStatus::EXPIRED;
        }

        return SaleLinkStatus::ACTIVE;
    }

    public function isUsable(): bool
    {
        return $this->getDerivedStatus() === SaleLinkStatus::ACTIVE;
    }

    public function isExpired(): bool
    {
        return $this->getExpiresAt() !== null && Carbon::parse($this->getExpiresAt())->isPast();
    }
}
