<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\Exceptions\AppleWallet\AppleWalletConfigurationException;
use HiEvents\Exceptions\AppleWallet\AppleWalletPassGenerationException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassService;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassSettingsResolver;
use HiEvents\Services\Domain\AppleWallet\AppleWalletUrlGenerator;
use HiEvents\Services\Domain\AppleWallet\SyncAppleWalletPassesService;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class PreviewAppleWalletPassCommand extends Command
{
    private const ATTENDEE_CODE_PREFIX = 'A-';

    private const ORDER_NUMBER_PREFIX = 'O-';

    private const OUTPUT_DIRECTORY = 'app/apple-wallet-previews';

    protected $signature = 'apple-wallet:preview {code : Attendee ticket code (A-XXXXXXX) or order number (O-XXXXXXX)}';

    protected $description = 'Build the latest Apple Wallet passes for a ticket or an order, notify registered devices and save the pass file, without sending an email';

    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly AppleWalletPassSettingsResolver $passSettingsResolver,
        private readonly SyncAppleWalletPassesService $syncService,
        private readonly AppleWalletPassService $passService,
        private readonly AppleWalletUrlGenerator $urlGenerator,
        private readonly Filesystem $filesystem,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->passSettingsResolver->isConfigured()) {
            $this->error('Apple Wallet is not configured. Set APPLE_WALLET_ENABLED, APPLE_WALLET_PASS_TYPE_IDENTIFIER and APPLE_WALLET_TEAM_IDENTIFIER.');

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
            code: $code,
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
            code: $code,
            eventId: $order->getEventId(),
            sync: fn () => $this->syncService->syncOrder($order->getId()),
            where: [AttendeeDomainObjectAbstract::ORDER_ID => $order->getId()],
        );
    }

    /**
     * @param  callable(): void  $sync
     * @param  array<string, int>  $where
     */
    private function preview(string $code, int $eventId, callable $sync, array $where): int
    {
        $organizerId = $this->eventRepository->findById($eventId)->getOrganizerId();

        if ($this->passSettingsResolver->resolveForOrganizer($organizerId) === null) {
            return $this->failWith("Apple Wallet is disabled for organizer $organizerId. Enable it in the organizer settings.");
        }

        try {
            $sync();
            $pass = $this->passService->generate($where);
        } catch (AppleWalletConfigurationException|AppleWalletPassGenerationException $exception) {
            return $this->failWith($exception->getMessage());
        }

        if ($pass === null) {
            return $this->failWith('No pass was created.');
        }

        $path = storage_path(self::OUTPUT_DIRECTORY.'/'.$code.'-'.$pass->filename);
        $this->filesystem->ensureDirectoryExists(dirname($path));
        $this->filesystem->put($path, $pass->contents);

        $attendeeShortIds = $this->attendeeRepository
            ->findWhere($where)
            ->map(fn (AttendeeDomainObject $attendee) => $attendee->getShortId())
            ->values()
            ->all();

        $this->info("Apple Wallet pass saved to $path");
        $this->line('Open this link on an iPhone to add it:');
        $this->line($this->urlGenerator->passesDownloadUrl($eventId, $attendeeShortIds));

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
