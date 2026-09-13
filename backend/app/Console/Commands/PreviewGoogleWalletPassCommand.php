<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\Exceptions\GoogleWallet\GoogleWalletApiException;
use HiEvents\Exceptions\GoogleWallet\GoogleWalletConfigurationException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletPassSettingsResolver;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletSaveUrlService;
use HiEvents\Services\Domain\GoogleWallet\SyncGoogleWalletPassesService;
use Illuminate\Console\Command;

class PreviewGoogleWalletPassCommand extends Command
{
    private const ATTENDEE_CODE_PREFIX = 'A-';

    private const ORDER_NUMBER_PREFIX = 'O-';

    protected $signature = 'google-wallet:preview {code : Attendee ticket code (A-XXXXXXX) or order number (O-XXXXXXX)}';

    protected $description = 'Push the latest Google Wallet passes for a ticket or an order and print the save link, without sending an email';

    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly OrderRepositoryInterface $orderRepository,
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

        $input = trim((string) $this->argument('code'));
        $code = strtoupper($input);

        return match (true) {
            str_starts_with($code, self::ATTENDEE_CODE_PREFIX) => $this->previewAttendee($code),
            str_starts_with($code, self::ORDER_NUMBER_PREFIX) => $this->previewOrder($code),
            default => $this->failWith("$input is not a ticket code (A-XXXXXXX) or an order number (O-XXXXXXX)."),
        };
    }

    private function previewAttendee(string $code): int
    {
        $attendee = $this->attendeeRepository->findFirstWhere([AttendeeDomainObjectAbstract::PUBLIC_ID => $code]);

        if ($attendee === null) {
            return $this->failWith("No ticket found with code $code.");
        }

        return $this->preview(
            eventId: $attendee->getEventId(),
            sync: fn () => $this->syncService->syncAttendee($attendee->getId()),
            where: [AttendeeDomainObjectAbstract::ID => $attendee->getId()],
        );
    }

    private function previewOrder(string $code): int
    {
        $order = $this->orderRepository->findFirstWhere([OrderDomainObjectAbstract::PUBLIC_ID => $code]);

        if ($order === null) {
            return $this->failWith("No order found with number $code.");
        }

        return $this->preview(
            eventId: $order->getEventId(),
            sync: fn () => $this->syncService->syncOrder($order->getId()),
            where: [AttendeeDomainObjectAbstract::ORDER_ID => $order->getId()],
        );
    }

    /**
     * @param  callable(): void  $sync
     * @param  array<string, int>  $where
     */
    private function preview(int $eventId, callable $sync, array $where): int
    {
        $organizerId = $this->eventRepository->findById($eventId)->getOrganizerId();

        if ($this->passSettingsResolver->resolveForOrganizer($organizerId) === null) {
            return $this->failWith("Google Wallet is disabled for organizer $organizerId. Enable it in the organizer settings.");
        }

        try {
            $sync();

            $objectIds = $this->attendeeRepository
                ->findWhere($where)
                ->map(fn (AttendeeDomainObject $attendee) => $attendee->getGoogleWalletObjectId())
                ->filter()
                ->values()
                ->all();

            if ($objectIds === []) {
                return $this->failWith('No pass was created. Each ticket must belong to a single event date.');
            }

            $saveUrl = $this->saveUrlService->buildForObjectIds($objectIds);
        } catch (GoogleWalletApiException|GoogleWalletConfigurationException $exception) {
            return $this->failWith($exception->getMessage());
        }

        $this->info(sprintf('%d pass(es) up to date. Open this link to preview them:', count($objectIds)));
        $this->line($saveUrl);

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
