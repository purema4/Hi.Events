<?php

namespace HiEvents\Http\Request\CheckInList;

use HiEvents\DomainObjects\Enums\AttendeeCheckInActionType;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class CreateAttendeeCheckInRequest extends BaseRequest
{
    private const MAX_ATTENDEES = 100;

    public function rules(): array
    {
        return [
            'attendees' => ['required', 'array', 'max:'.self::MAX_ATTENDEES],
            'attendees.*.public_id' => ['required', 'string'],
            'attendees.*.action' => ['required', 'string', Rule::in(AttendeeCheckInActionType::valuesArray())],
        ];
    }

    public function onlyChecksIn(): bool
    {
        return collect($this->validated('attendees'))
            ->every(fn (array $attendee) => $attendee['action'] === AttendeeCheckInActionType::CHECK_IN->value);
    }
}
