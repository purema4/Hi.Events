<?php

namespace HiEvents\Http\Actions\GoogleWallet\Public;

use Dedoc\Scramble\Attributes\Response as ResponseAttribute;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\GoogleWallet\ResolveGoogleWalletSavePublicRequest;
use HiEvents\Services\Application\Handlers\GoogleWallet\ResolveGoogleWalletSaveUrlPublicHandler;
use Symfony\Component\HttpFoundation\Response;

class RedirectToGoogleWalletSavePublicAction extends BaseAction
{
    public function __construct(
        private readonly ResolveGoogleWalletSaveUrlPublicHandler $handler,
    ) {}

    #[ResponseAttribute(status: 302, description: 'Redirect to the Google Wallet save link for these tickets')]
    public function __invoke(ResolveGoogleWalletSavePublicRequest $request, int $eventId): Response
    {
        try {
            $saveUrl = $this->handler->handle($eventId, $request->attendeeShortIds());
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->redirectResponse($saveUrl);
    }
}
