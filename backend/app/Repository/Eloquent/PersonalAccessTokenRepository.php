<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\PersonalAccessTokenDomainObject;
use HiEvents\Models\PersonalAccessToken;
use HiEvents\Repository\Interfaces\PersonalAccessTokenRepositoryInterface;

/**
 * @extends BaseRepository<PersonalAccessTokenDomainObject>
 */
class PersonalAccessTokenRepository extends BaseRepository implements PersonalAccessTokenRepositoryInterface
{
    protected function getModel(): string
    {
        return PersonalAccessToken::class;
    }

    public function getDomainObject(): string
    {
        return PersonalAccessTokenDomainObject::class;
    }
}
