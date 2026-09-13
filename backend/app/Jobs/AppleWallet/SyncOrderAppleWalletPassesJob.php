<?php

namespace HiEvents\Jobs\AppleWallet;

use HiEvents\Services\Domain\AppleWallet\SyncAppleWalletPassesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Throwable;

class SyncOrderAppleWalletPassesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $orderId) {}

    public function handle(SyncAppleWalletPassesService $syncService, LoggerInterface $logger): void
    {
        try {
            $syncService->syncOrder($this->orderId);
        } catch (Throwable $exception) {
            $logger->error('Failed to sync Apple Wallet passes for an order', [
                'order_id' => $this->orderId,
                'exception' => $exception,
            ]);
        }
    }
}
