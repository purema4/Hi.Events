<?php

namespace HiEvents\Resources\ApiKey;

use HiEvents\DomainObjects\PersonalAccessTokenDomainObject;
use HiEvents\Helper\AppVersion;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin PersonalAccessTokenDomainObject
 */
class CurrentApiKeyResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'abilities' => $this->getAbilityList(),
            'account_id' => $this->getAccountId(),
            'expires_at' => $this->getExpiresAt(),
            'last_used_at' => $this->getLastUsedAt(),
            'app_version' => AppVersion::get(),
            'api_key_contract' => config('api-keys.contract'),
            'features' => config('api-keys.features'),
        ];
    }
}
