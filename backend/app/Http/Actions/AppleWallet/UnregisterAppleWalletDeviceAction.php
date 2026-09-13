<?php

namespace HiEvents\Http\Actions\AppleWallet;

use HiEvents\Exceptions\AppleWallet\AppleWalletAuthenticationException;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\AppleWallet\DTO\UnregisterAppleWalletDeviceDTO;
use HiEvents\Services\Application\Handlers\AppleWallet\UnregisterAppleWalletDeviceHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UnregisterAppleWalletDeviceAction extends BaseAppleWalletWebServiceAction
{
    public function __construct(
        private readonly UnregisterAppleWalletDeviceHandler $handler,
    ) {}

    public function __invoke(
        Request $request,
        string $deviceLibraryIdentifier,
        string $passTypeIdentifier,
        string $serialNumber,
    ): Response {
        try {
            $this->handler->handle(new UnregisterAppleWalletDeviceDTO(
                passTypeIdentifier: $passTypeIdentifier,
                serialNumber: $serialNumber,
                authenticationToken: $this->authenticationToken($request),
                deviceLibraryIdentifier: $deviceLibraryIdentifier,
            ));
        } catch (AppleWalletAuthenticationException) {
            return $this->noContentResponse(ResponseCodes::HTTP_UNAUTHORIZED);
        }

        return $this->noContentResponse(ResponseCodes::HTTP_OK);
    }
}
