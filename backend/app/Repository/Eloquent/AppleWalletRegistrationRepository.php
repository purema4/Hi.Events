<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\AppleWalletRegistrationDomainObject;
use HiEvents\Models\AppleWalletRegistration;
use HiEvents\Repository\Interfaces\AppleWalletRegistrationRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<AppleWalletRegistrationDomainObject>
 */
class AppleWalletRegistrationRepository extends BaseRepository implements AppleWalletRegistrationRepositoryInterface
{
    protected function getModel(): string
    {
        return AppleWalletRegistration::class;
    }

    public function getDomainObject(): string
    {
        return AppleWalletRegistrationDomainObject::class;
    }

    public function findWhereAttendee(array $attendeeWhere): Collection
    {
        return $this->runQuery(fn () => $this->handleResults(
            $this->model
                ->whereHas('attendee', static fn (Builder $query) => $query->where($attendeeWhere))
                ->get()
        ));
    }
}
