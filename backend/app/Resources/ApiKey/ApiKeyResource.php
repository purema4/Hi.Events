<?php

namespace HiEvents\Resources\ApiKey;

use HiEvents\DomainObjects\PersonalAccessTokenDomainObject;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin PersonalAccessTokenDomainObject
 */
class ApiKeyResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'abilities' => $this->getAbilityList(),
            'partial_token' => $this->getPartialToken(),
            'created_by' => $this->getUser() ? [
                'id' => $this->getUser()->getId(),
                'first_name' => $this->getUser()->getFirstName(),
                'last_name' => $this->getUser()->getLastName(),
            ] : null,
            'created_at' => $this->getCreatedAt(),
            'last_used_at' => $this->getLastUsedAt(),
            'last_used_ip' => $this->getLastUsedIp(),
            'expires_at' => $this->getExpiresAt(),
        ];
    }
}
