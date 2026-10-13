<?php

namespace HiEvents\DomainObjects;

class PersonalAccessTokenDomainObject extends Generated\PersonalAccessTokenDomainObjectAbstract
{
    private ?UserDomainObject $user = null;

    private ?string $plainTextToken = null;

    public function getAbilityList(): array
    {
        return json_decode($this->getAbilities() ?? '[]', true) ?: [];
    }

    public function setUser(?UserDomainObject $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getUser(): ?UserDomainObject
    {
        return $this->user;
    }

    public function setPlainTextToken(?string $plainTextToken): self
    {
        $this->plainTextToken = $plainTextToken;

        return $this;
    }

    public function getPlainTextToken(): ?string
    {
        return $this->plainTextToken;
    }
}
