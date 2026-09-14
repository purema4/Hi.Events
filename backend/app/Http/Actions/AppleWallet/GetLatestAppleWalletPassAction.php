<?php

namespace HiEvents\Http\Actions\AppleWallet;

use HiEvents\Exceptions\AppleWallet\AppleWalletAuthenticationException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\AppleWallet\GetLatestAppleWalletPassHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GetLatestAppleWalletPassAction extends BaseAppleWalletWebServiceAction
{
    public function __construct(
        private readonly GetLatestAppleWalletPassHandler $handler,
    ) {}

    public function __invoke(Request $request, string $passTypeIdentifier, string $serialNumber): Response
    {
        try {
            $pass = $this->handler->handle(
                passTypeIdentifier: $passTypeIdentifier,
                serialNumber: $serialNumber,
                authenticationToken: $this->authenticationToken($request),
            );
        } catch (AppleWalletAuthenticationException) {
            return $this->noContentResponse(ResponseCodes::HTTP_UNAUTHORIZED);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->fileResponse($pass->contents, $pass->mimeType, $pass->filename)
            ->setLastModified($pass->lastModified);
    }
}
