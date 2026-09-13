<?php

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\AppleWalletRegistrationDomainObject;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<AppleWalletRegistrationDomainObject>
 */
interface AppleWalletRegistrationRepositoryInterface extends RepositoryInterface
{
    /**
     * @return Collection<int, AppleWalletRegistrationDomainObject>
     */
    public function findWhereAttendee(array $attendeeWhere): Collection;
}
