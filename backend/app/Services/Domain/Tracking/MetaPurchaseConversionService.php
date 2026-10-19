<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Tracking;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\TrackingPixelProvider;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Exceptions\Meta\MetaConversionsApiException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Infrastructure\Meta\MetaConversionsApiClient;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Psr\Log\LoggerInterface;

class MetaPurchaseConversionService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrganizerSettingsRepositoryInterface $organizerSettingsRepository,
        private readonly MetaConversionsApiClient $client,
        private readonly Encrypter $encrypter,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws MetaConversionsApiException
     */
    public function sendPurchase(int $orderId): void
    {
        /** @var OrderDomainObject $order */
        $order = $this->orderRepository->findById($orderId);
        $trackingContext = $order->getAdTrackingContext();

        if (! $order->isOrderCompleted() || ! is_array($trackingContext)) {
            return;
        }

        /** @var EventDomainObject $event */
        $event = $this->eventRepository->findById($order->getEventId());

        /** @var OrganizerSettingDomainObject|null $settings */
        $settings = $this->organizerSettingsRepository->findFirstWhere([
            'organizer_id' => $event->getOrganizerId(),
        ]);

        $pixelId = $this->findEnabledFacebookPixelId($settings);
        $accessToken = $this->decryptAccessToken($settings, $order);

        if ($pixelId === null || $accessToken === null) {
            return;
        }

        $this->client->sendEvents($pixelId, $accessToken, [
            $this->buildPurchaseEvent($order, $event, $trackingContext),
        ]);
    }

    private function findEnabledFacebookPixelId(?OrganizerSettingDomainObject $settings): ?string
    {
        $pixels = $settings?->getTrackingPixels();

        if (! is_array($pixels)) {
            return null;
        }

        foreach ($pixels as $pixel) {
            if (($pixel['provider'] ?? null) === TrackingPixelProvider::FACEBOOK_PIXEL->value
                && ! empty($pixel['enabled'])
                && ! empty($pixel['pixel_id'])) {
                return (string) $pixel['pixel_id'];
            }
        }

        return null;
    }

    private function decryptAccessToken(?OrganizerSettingDomainObject $settings, OrderDomainObject $order): ?string
    {
        $encryptedToken = $settings?->getMetaConversionsApiAccessToken();

        if ($encryptedToken === null) {
            return null;
        }

        try {
            return $this->encrypter->decryptString($encryptedToken);
        } catch (DecryptException) {
            $this->logger->warning('Meta Conversions API access token could not be decrypted', [
                'order_id' => $order->getId(),
                'organizer_settings_id' => $settings->getId(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, string|null>  $trackingContext
     * @return array<string, mixed>
     */
    private function buildPurchaseEvent(
        OrderDomainObject $order,
        EventDomainObject $event,
        array $trackingContext,
    ): array {
        return array_filter([
            'event_name' => $order->getTotalGross() > 0 ? 'Purchase' : 'CompleteRegistration',
            'event_time' => Carbon::now()->getTimestamp(),
            'event_id' => $order->getShortId(),
            'action_source' => 'website',
            'event_source_url' => $trackingContext['event_source_url'] ?? null,
            'user_data' => $this->buildUserData($order, $trackingContext),
            'custom_data' => [
                'currency' => strtoupper($order->getCurrency()),
                'value' => round($order->getTotalGross(), 2),
                'content_ids' => [(string) $event->getId()],
                'content_name' => $event->getTitle(),
                'content_type' => 'product',
                'order_id' => $order->getShortId(),
            ],
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, string|null>  $trackingContext
     * @return array<string, mixed>
     */
    private function buildUserData(OrderDomainObject $order, array $trackingContext): array
    {
        $address = is_array($order->getAddress()) ? $order->getAddress() : [];

        return array_filter([
            'em' => $this->hash($order->getEmail()),
            'fn' => $this->hash($order->getFirstName()),
            'ln' => $this->hash($order->getLastName()),
            'ct' => $this->hash($this->stripNonAlphanumeric($address['city'] ?? null)),
            'st' => $this->hash($this->stripNonAlphanumeric($address['state_or_region'] ?? null)),
            'zp' => $this->hash($this->stripNonAlphanumeric($address['zip_or_postal_code'] ?? null)),
            'country' => $this->hash($address['country'] ?? null),
            'client_ip_address' => $trackingContext['client_ip_address'] ?? null,
            'client_user_agent' => $trackingContext['client_user_agent'] ?? null,
            'fbp' => $trackingContext['fbp'] ?? null,
            'fbc' => $trackingContext['fbc'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return array<int, string>|null
     */
    private function hash(?string $value): ?array
    {
        $normalized = mb_strtolower(trim((string) $value));

        if ($normalized === '') {
            return null;
        }

        return [hash('sha256', $normalized)];
    }

    private function stripNonAlphanumeric(?string $value): ?string
    {
        return $value === null ? null : preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
    }
}
