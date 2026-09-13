<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\AppleWallet;

use Carbon\Carbon;
use HiEvents\DomainObjects\AppleWalletRegistrationDomainObject;
use HiEvents\DomainObjects\Generated\AppleWalletRegistrationDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\Exceptions\AppleWallet\AppleWalletConfigurationException;
use HiEvents\Exceptions\AppleWallet\AppleWalletPushException;
use HiEvents\Repository\Interfaces\AppleWalletRegistrationRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Services\Infrastructure\AppleWallet\AppleWalletPushClient;
use Psr\Log\LoggerInterface;

class SyncAppleWalletPassesService
{
    public function __construct(
        private readonly AppleWalletRegistrationRepositoryInterface $registrationRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly AppleWalletPushClient $pushClient,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws AppleWalletConfigurationException
     */
    public function syncEvent(int $eventId): void
    {
        $this->notifyRegisteredDevices([AttendeeDomainObjectAbstract::EVENT_ID => $eventId]);
    }

    /**
     * @throws AppleWalletConfigurationException
     */
    public function syncOccurrence(int $occurrenceId): void
    {
        $this->notifyRegisteredDevices([AttendeeDomainObjectAbstract::EVENT_OCCURRENCE_ID => $occurrenceId]);
    }

    /**
     * @throws AppleWalletConfigurationException
     */
    public function syncOrder(int $orderId): void
    {
        $this->notifyRegisteredDevices([AttendeeDomainObjectAbstract::ORDER_ID => $orderId]);
    }

    /**
     * @throws AppleWalletConfigurationException
     */
    public function syncAttendee(int $attendeeId): void
    {
        $this->notifyRegisteredDevices([AttendeeDomainObjectAbstract::ID => $attendeeId]);
    }

    /**
     * @throws AppleWalletConfigurationException
     */
    private function notifyRegisteredDevices(array $attendeeWhere): void
    {
        $registrations = $this->registrationRepository->findWhereAttendee($attendeeWhere);

        if ($registrations->isEmpty()) {
            return;
        }

        $this->attendeeRepository->updateWhere(
            attributes: [AttendeeDomainObjectAbstract::APPLE_WALLET_PASS_UPDATED_AT => Carbon::now()],
            where: [[
                AttendeeDomainObjectAbstract::ID,
                'in',
                $registrations
                    ->map(static fn (AppleWalletRegistrationDomainObject $registration) => $registration->getAttendeeId())
                    ->unique()
                    ->values()
                    ->all(),
            ]],
        );

        $registrations
            ->map(static fn (AppleWalletRegistrationDomainObject $registration) => $registration->getPushToken())
            ->unique()
            ->each(fn (string $pushToken) => $this->notifyDevice($pushToken));
    }

    /**
     * @throws AppleWalletConfigurationException
     */
    private function notifyDevice(string $pushToken): void
    {
        try {
            $delivered = $this->pushClient->notifyPassUpdated($pushToken);
        } catch (AppleWalletPushException $exception) {
            $this->logger->error('Failed to notify a device about an updated Apple Wallet pass', [
                'exception' => $exception,
            ]);

            return;
        }

        if (! $delivered) {
            $this->registrationRepository->deleteWhere([
                AppleWalletRegistrationDomainObjectAbstract::PUSH_TOKEN => $pushToken,
            ]);
        }
    }
}
