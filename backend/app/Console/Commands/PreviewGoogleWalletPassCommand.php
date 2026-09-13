<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\Exceptions\GoogleWallet\GoogleWalletApiException;
use HiEvents\Exceptions\GoogleWallet\GoogleWalletConfigurationException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletPassSettingsResolver;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletSaveUrlService;
use HiEvents\Services\Domain\GoogleWallet\SyncGoogleWalletPassesService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class PreviewGoogleWalletPassCommand extends Command
{
    protected $signature = 'google-wallet:preview {attendee : Attendee ID, ticket code (A-XXXXXXX) or email}';

    protected $description = 'Push the latest Google Wallet pass for an attendee and print its save link, without sending an email';

    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly GoogleWalletPassSettingsResolver $passSettingsResolver,
        private readonly SyncGoogleWalletPassesService $syncService,
        private readonly GoogleWalletSaveUrlService $saveUrlService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->passSettingsResolver->isConfigured()) {
            $this->error('Google Wallet is not configured. Set GOOGLE_WALLET_ENABLED and GOOGLE_WALLET_ISSUER_ID.');

            return self::FAILURE;
        }

        $identifier = trim((string) $this->argument('attendee'));
        $matches = $this->findAttendees($identifier);

        if ($matches->isEmpty()) {
            $this->error("No attendee found for $identifier.");

            return self::FAILURE;
        }

        if ($matches->count() > 1) {
            $this->error("$identifier matches {$matches->count()} attendees. Run the command again with one of these IDs or ticket codes:");
            $this->table(
                ['ID', 'Ticket code', 'Name', 'Event', 'Status'],
                $matches->map(fn (AttendeeDomainObject $match) => [
                    $match->getId(),
                    $match->getPublicId(),
                    trim($match->getFirstName().' '.$match->getLastName()),
                    $match->getEventId(),
                    $match->getStatus(),
                ])->all(),
            );

            return self::FAILURE;
        }

        $attendee = $matches->first();
        $attendeeId = $attendee->getId();

        $organizerId = $this->eventRepository->findById($attendee->getEventId())->getOrganizerId();

        if ($this->passSettingsResolver->resolveForOrganizer($organizerId) === null) {
            $this->error("Google Wallet is disabled for organizer $organizerId. Enable it in the organizer settings.");

            return self::FAILURE;
        }

        try {
            $this->syncService->syncAttendee($attendeeId);

            $objectId = $this->attendeeRepository
                ->findFirstWhere(['id' => $attendeeId])
                ?->getGoogleWalletObjectId();

            if ($objectId === null) {
                $this->error('No pass was created. The attendee must belong to a single event date.');

                return self::FAILURE;
            }

            $saveUrl = $this->saveUrlService->buildForObjectIds([$objectId]);
        } catch (GoogleWalletApiException|GoogleWalletConfigurationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Pass $objectId is up to date. Open this link to preview it:");
        $this->line($saveUrl);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, AttendeeDomainObject>
     */
    private function findAttendees(string $identifier): Collection
    {
        if (ctype_digit($identifier)) {
            return $this->attendeeRepository->findWhere(['id' => (int) $identifier]);
        }

        if (str_contains($identifier, '@')) {
            return $this->attendeeRepository->findWhere([['email', 'ilike', $identifier]]);
        }

        return $this->attendeeRepository->findWhere(['public_id' => strtoupper($identifier)]);
    }
}
