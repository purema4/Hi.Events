<?php

namespace HiEvents\Http\Request\AppleWallet;

use HiEvents\Http\Request\BaseRequest;

class RegisterAppleWalletDeviceRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'pushToken' => ['required', 'string', 'max:255'],
        ];
    }
}
