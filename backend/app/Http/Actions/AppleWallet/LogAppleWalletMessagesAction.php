<?php

namespace HiEvents\Http\Actions\AppleWallet;

use HiEvents\Http\ResponseCodes;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

class LogAppleWalletMessagesAction extends BaseAppleWalletWebServiceAction
{
    private const MAX_MESSAGES = 20;

    private const MAX_MESSAGE_LENGTH = 1000;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): Response
    {
        collect((array) $request->input('logs', []))
            ->filter(static fn (mixed $message) => is_string($message))
            ->take(self::MAX_MESSAGES)
            ->each(fn (string $message) => $this->logger->warning('Apple Wallet device log', [
                'message' => Str::limit($message, self::MAX_MESSAGE_LENGTH),
            ]));

        return $this->noContentResponse(ResponseCodes::HTTP_OK);
    }
}
