<?php

namespace HiEvents\Http\Actions\AppleWallet;

use HiEvents\Services\Application\Handlers\AppleWallet\GetUpdatableAppleWalletPassesHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GetUpdatableAppleWalletPassesAction extends BaseAppleWalletWebServiceAction
{
    public function __construct(
        private readonly GetUpdatableAppleWalletPassesHandler $handler,
    ) {}

    public function __invoke(Request $request, string $deviceLibraryIdentifier, string $passTypeIdentifier): JsonResponse|Response
    {
        $updatablePasses = $this->handler->handle(
            passTypeIdentifier: $passTypeIdentifier,
            deviceLibraryIdentifier: $deviceLibraryIdentifier,
            passesUpdatedSince: $request->query('passesUpdatedSince'),
        );

        if ($updatablePasses === null) {
            return $this->noContentResponse();
        }

        return $this->jsonResponse([
            'serialNumbers' => $updatablePasses->serialNumbers,
            'lastUpdated' => $updatablePasses->lastUpdated,
        ]);
    }
}
