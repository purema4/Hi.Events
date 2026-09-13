<?php

namespace HiEvents\Http\Actions\AppleWallet;

use HiEvents\Exceptions\AppleWallet\AppleWalletAuthenticationException;
use HiEvents\Http\Request\AppleWallet\RegisterAppleWalletDeviceRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\AppleWallet\DTO\RegisterAppleWalletDeviceDTO;
use HiEvents\Services\Application\Handlers\AppleWallet\RegisterAppleWalletDeviceHandler;
use Illuminate\Http\Response;

class RegisterAppleWalletDeviceAction extends BaseAppleWalletWebServiceAction
{
    public function __construct(
        private readonly RegisterAppleWalletDeviceHandler $handler,
    ) {}

    public function __invoke(
        RegisterAppleWalletDeviceRequest $request,
        string $deviceLibraryIdentifier,
        string $passTypeIdentifier,
        string $serialNumber,
    ): Response {
        try {
            $registered = $this->handler->handle(new RegisterAppleWalletDeviceDTO(
                passTypeIdentifier: $passTypeIdentifier,
                serialNumber: $serialNumber,
                authenticationToken: $this->authenticationToken($request),
                deviceLibraryIdentifier: $deviceLibraryIdentifier,
                pushToken: $request->validated('pushToken'),
            ));
        } catch (AppleWalletAuthenticationException) {
            return $this->noContentResponse(ResponseCodes::HTTP_UNAUTHORIZED);
        }

        return $this->noContentResponse($registered ? ResponseCodes::HTTP_CREATED : ResponseCodes::HTTP_OK);
    }
}
