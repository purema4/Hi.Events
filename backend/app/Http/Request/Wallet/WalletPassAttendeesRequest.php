<?php

namespace HiEvents\Http\Request\Wallet;

use HiEvents\Http\Request\BaseRequest;

abstract class WalletPassAttendeesRequest extends BaseRequest
{
    private const MAX_ATTENDEES = 50;

    public function rules(): array
    {
        return [
            'attendees' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function attendeeShortIds(): array
    {
        return array_slice(array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string) $this->validated('attendees'))),
        ))), 0, self::MAX_ATTENDEES);
    }
}
