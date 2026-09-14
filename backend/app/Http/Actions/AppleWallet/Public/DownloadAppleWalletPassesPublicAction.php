<?php

namespace HiEvents\Http\Actions\AppleWallet\Public;

use Dedoc\Scramble\Attributes\Response as ResponseAttribute;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\AppleWallet\DownloadAppleWalletPassesPublicRequest;
use HiEvents\Services\Application\Handlers\AppleWallet\DownloadAppleWalletPassesPublicHandler;
use Illuminate\Http\Response;

class DownloadAppleWalletPassesPublicAction extends BaseAction
{
    public function __construct(
        private readonly DownloadAppleWalletPassesPublicHandler $handler,
    ) {}

    #[ResponseAttribute(status: 200, description: 'Apple Wallet pass (.pkpass) for one ticket, or a .pkpasses bundle for several', mediaType: 'application/vnd.apple.pkpass', type: 'string', format: 'binary')]
    public function __invoke(DownloadAppleWalletPassesPublicRequest $request, int $eventId): Response
    {
        try {
            $pass = $this->handler->handle($eventId, $request->attendeeShortIds());
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->fileResponse($pass->contents, $pass->mimeType, $pass->filename)
            ->setLastModified($pass->lastModified);
    }
}
