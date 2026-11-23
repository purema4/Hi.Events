<?php

namespace HiEvents\DomainObjects;

class SaleLinkItemDomainObject extends Generated\SaleLinkItemDomainObjectAbstract
{
    private ?ProductDomainObject $product = null;

    public function setProduct(?ProductDomainObject $product): self
    {
        $this->product = $product;

        return $this;
    }

    public function getProduct(): ?ProductDomainObject
    {
        return $this->product;
    }
}
