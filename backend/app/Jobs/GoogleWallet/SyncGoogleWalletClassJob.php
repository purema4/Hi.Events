<?php

namespace HiEvents\Jobs\GoogleWallet;

use HiEvents\Services\Domain\GoogleWallet\EnsureGoogleWalletClassService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Throwable;

class SyncGoogleWalletClassJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $occurrenceId) {}

    public function handle(EnsureGoogleWalletClassService $ensureClassService, LoggerInterface $logger): void
    {
        try {
            $ensureClassService->ensureForOccurrenceId($this->occurrenceId);
        } catch (Throwable $exception) {
            $logger->error('Failed to sync the Google Wallet class for an occurrence', [
                'occurrence_id' => $this->occurrenceId,
                'exception' => $exception,
            ]);
        }
    }
}
