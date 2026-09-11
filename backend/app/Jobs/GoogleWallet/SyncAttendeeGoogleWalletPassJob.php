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

class SyncAttendeeGoogleWalletPassJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $attendeeId) {}

    public function handle(SyncGoogleWalletPassesService $syncService, LoggerInterface $logger): void
    {
        try {
            $syncService->syncAttendee($this->attendeeId);
        } catch (Throwable $exception) {
            $logger->error('Failed to sync the Google Wallet pass for an attendee', [
                'attendee_id' => $this->attendeeId,
                'exception' => $exception,
            ]);
        }
    }
}
