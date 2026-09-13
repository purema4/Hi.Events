<?php

namespace HiEvents\Http\Actions\AppleWallet;

use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\Request;

abstract class BaseAppleWalletWebServiceAction extends BaseAction
{
    private const AUTHORIZATION_SCHEME = 'ApplePass ';

    protected function authenticationToken(Request $request): ?string
    {
        $authorization = (string) $request->header('Authorization');

        if (! str_starts_with($authorization, self::AUTHORIZATION_SCHEME)) {
            return null;
        }

        return substr($authorization, strlen(self::AUTHORIZATION_SCHEME));
    }
}
