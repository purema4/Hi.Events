<?php

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppleWalletRegistration extends BaseModel
{
    public function attendee(): BelongsTo
    {
        return $this->belongsTo(Attendee::class);
    }
}
