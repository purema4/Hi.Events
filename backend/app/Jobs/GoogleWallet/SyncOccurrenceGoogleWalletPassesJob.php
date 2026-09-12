<?php

namespace HiEvents\Jobs\GoogleWallet;

use HiEvents\Services\Domain\GoogleWallet\SyncGoogleWalletPassesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Throwable;

class SyncOccurrenceGoogleWalletPassesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $occurrenceId) {}

    public function handle(SyncGoogleWalletPassesService $syncService, LoggerInterface $logger): void
    {
        try {
            $syncService->syncOccurrence($this->occurrenceId);
        } catch (Throwable $exception) {
            $logger->error('Failed to sync Google Wallet passes for an occurrence', [
                'occurrence_id' => $this->occurrenceId,
                'exception' => $exception,
            ]);
        }
    }
}
