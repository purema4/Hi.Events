<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Tracking;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\DomainObjects\Enums\TrackingPixelProvider;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\DomainObjects\QuestionAnswerDomainObject;
use HiEvents\Exceptions\Meta\MetaConversionsApiException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionAnswerRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Infrastructure\Meta\MetaConversionsApiClient;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Psr\Log\LoggerInterface;

class MetaPurchaseConversionService
{
    private const NORTH_AMERICAN_COUNTRIES = ['us', 'ca'];

    private const NORTH_AMERICAN_CURRENCIES = ['usd', 'cad'];

    private const STATE_CODES_BY_COUNTRY = [
        'us' => [
            'alabama' => 'al',
            'alaska' => 'ak',
            'arizona' => 'az',
            'arkansas' => 'ar',
            'california' => 'ca',
            'colorado' => 'co',
            'connecticut' => 'ct',
            'delaware' => 'de',
            'districtofcolumbia' => 'dc',
            'florida' => 'fl',
            'georgia' => 'ga',
            'hawaii' => 'hi',
            'idaho' => 'id',
            'illinois' => 'il',
            'indiana' => 'in',
            'iowa' => 'ia',
            'kansas' => 'ks',
            'kentucky' => 'ky',
            'louisiana' => 'la',
            'maine' => 'me',
            'maryland' => 'md',
            'massachusetts' => 'ma',
            'michigan' => 'mi',
            'minnesota' => 'mn',
            'mississippi' => 'ms',
            'missouri' => 'mo',
            'montana' => 'mt',
            'nebraska' => 'ne',
            'nevada' => 'nv',
            'newhampshire' => 'nh',
            'newjersey' => 'nj',
            'newmexico' => 'nm',
            'newyork' => 'ny',
            'northcarolina' => 'nc',
            'northdakota' => 'nd',
            'ohio' => 'oh',
            'oklahoma' => 'ok',
            'oregon' => 'or',
            'pennsylvania' => 'pa',
            'rhodeisland' => 'ri',
            'southcarolina' => 'sc',
            'southdakota' => 'sd',
            'tennessee' => 'tn',
            'texas' => 'tx',
            'utah' => 'ut',
            'vermont' => 'vt',
            'virginia' => 'va',
            'washington' => 'wa',
            'westvirginia' => 'wv',
            'wisconsin' => 'wi',
            'wyoming' => 'wy',
            'puertorico' => 'pr',
        ],
        'ca' => [
            'alberta' => 'ab',
            'britishcolumbia' => 'bc',
            'colombiebritannique' => 'bc',
            'manitoba' => 'mb',
            'newbrunswick' => 'nb',
            'nouveaubrunswick' => 'nb',
            'newfoundlandandlabrador' => 'nl',
            'newfoundland' => 'nl',
            'terreneuveetlabrador' => 'nl',
            'terreneuve' => 'nl',
            'novascotia' => 'ns',
            'nouvelleécosse' => 'ns',
            'nouvelleecosse' => 'ns',
            'northwestterritories' => 'nt',
            'territoiresdunordouest' => 'nt',
            'nunavut' => 'nu',
            'ontario' => 'on',
            'princeedwardisland' => 'pe',
            'îleduprinceédouard' => 'pe',
            'ileduprinceedouard' => 'pe',
            'québec' => 'qc',
            'quebec' => 'qc',
            'saskatchewan' => 'sk',
            'yukon' => 'yt',
        ],
    ];

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrganizerSettingsRepositoryInterface $organizerSettingsRepository,
        private readonly QuestionRepositoryInterface $questionRepository,
        private readonly QuestionAnswerRepositoryInterface $questionAnswerRepository,
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
        $country = mb_strtolower(trim((string) ($address['country'] ?? '')));

        return array_filter([
            'em' => $this->hash($order->getEmail()),
            'ph' => $this->hash($this->normalizePhone($this->findBuyerPhone($order), $country, (string) $order->getCurrency())),
            'fn' => $this->hash($this->stripNonAlphanumeric($order->getFirstName())),
            'ln' => $this->hash($this->stripNonAlphanumeric($order->getLastName())),
            'ct' => $this->hash($this->stripNonAlphanumeric($address['city'] ?? null)),
            'st' => $this->hash($this->normalizeState($address['state_or_region'] ?? null, $country)),
            'zp' => $this->hash($this->normalizeZip($address['zip_or_postal_code'] ?? null, $country)),
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

    private function findBuyerPhone(OrderDomainObject $order): ?string
    {
        $phoneQuestionIds = $this->questionRepository->findWhere([
            'event_id' => $order->getEventId(),
            'type' => QuestionTypeEnum::PHONE->name,
            'belongs_to' => QuestionBelongsTo::ORDER->name,
        ])->map(fn ($question) => $question->getId())->all();

        if ($phoneQuestionIds === []) {
            return null;
        }

        /** @var QuestionAnswerDomainObject|null $answer */
        $answer = $this->questionAnswerRepository
            ->findWhereIn('question_id', $phoneQuestionIds, ['order_id' => $order->getId()])
            ->first(fn (QuestionAnswerDomainObject $answer) => is_string($answer->getAnswer()) && trim($answer->getAnswer()) !== '');

        return $answer?->getAnswer();
    }

    private function normalizePhone(?string $phone, string $country, string $currency): ?string
    {
        $trimmed = trim((string) $phone);
        $digits = (string) preg_replace('/\D+/', '', $trimmed);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($trimmed, '+')) {
            return $digits;
        }

        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }

        $isNorthAmerican = $country === ''
            ? in_array(mb_strtolower($currency), self::NORTH_AMERICAN_CURRENCIES, true)
            : in_array($country, self::NORTH_AMERICAN_COUNTRIES, true);

        if (! $isNorthAmerican) {
            return null;
        }

        if (strlen($digits) === 10) {
            return '1'.$digits;
        }

        return strlen($digits) === 11 && str_starts_with($digits, '1') ? $digits : null;
    }

    private function normalizeState(?string $state, string $country): string
    {
        $normalized = mb_strtolower((string) $this->stripNonAlphanumeric($state));

        return self::STATE_CODES_BY_COUNTRY[$country][$normalized] ?? $normalized;
    }

    private function normalizeZip(?string $zip, string $country): string
    {
        $normalized = (string) $this->stripNonAlphanumeric($zip);

        return $country === 'us' ? substr($normalized, 0, 5) : $normalized;
    }

    private function stripNonAlphanumeric(?string $value): ?string
    {
        return $value === null ? null : preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
    }
}
