<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\AppleWallet;

use Carbon\Carbon;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventLocationDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Helper\EventVenueHelper;
use HiEvents\Helper\HexColorHelper;
use HiEvents\Helper\Url;
use HiEvents\Services\Domain\Wallet\DTO\WalletPassBrandingDTO;
use Illuminate\Config\Repository;

class AppleWalletPassJsonBuilder
{
    private const LIGHT_TEXT_COLOR = 'rgb(255, 255, 255)';

    private const DARK_TEXT_COLOR = 'rgb(0, 0, 0)';

    private const DARK_BACKGROUND_LUMINANCE = 0.5;

    public function __construct(
        private readonly Repository $config,
        private readonly AppleWalletSerialNumberService $serialNumberService,
        private readonly AppleWalletUrlGenerator $urlGenerator,
    ) {}

    public function build(
        AttendeeDomainObject $attendee,
        EventDomainObject $event,
        ?EventOccurrenceDomainObject $occurrence,
        OrganizerDomainObject $organizer,
        WalletPassBrandingDTO $passSettings,
    ): array {
        $serialNumber = $this->serialNumberService->serialNumberForAttendee($attendee->getId());
        $eventName = $this->eventName($event, $occurrence);
        $eventLocation = $occurrence?->getEventLocation() ?? $event->getEventLocation();
        $timezone = $event->getTimezone() ?: 'UTC';
        $startDate = $this->isoDate($occurrence?->getStartDate() ?? $event->getStartDate(), $timezone);
        $endDate = $this->isoDate($occurrence?->getEndDate() ?? $event->getEndDate(), $timezone);
        $coordinates = $this->coordinates($eventLocation);

        return array_filter([
            'formatVersion' => 1,
            'passTypeIdentifier' => (string) $this->config->get('apple-wallet.pass_type_identifier'),
            'teamIdentifier' => (string) $this->config->get('apple-wallet.team_identifier'),
            'serialNumber' => $serialNumber,
            'authenticationToken' => $this->serialNumberService->authenticationTokenFor($serialNumber),
            'webServiceURL' => $this->urlGenerator->webServiceUrl(),
            'organizationName' => $organizer->getName() ?: (string) $this->config->get('apple-wallet.organization_name'),
            'description' => __('Ticket for :event', ['event' => $eventName]),
            'logoText' => $organizer->getName(),
            'relevantDate' => $startDate,
            'voided' => $attendee->getStatus() === AttendeeStatus::CANCELLED->name ? true : null,
            'barcodes' => [
                [
                    'format' => 'PKBarcodeFormatQR',
                    'message' => $attendee->getPublicId(),
                    'messageEncoding' => 'iso-8859-1',
                    'altText' => $attendee->getPublicId(),
                ],
            ],
            'locations' => $coordinates === null ? null : [[...$coordinates, 'relevantText' => $eventName]],
            'semantics' => array_filter([
                'eventName' => $eventName,
                'eventStartDate' => $startDate,
                'eventEndDate' => $endDate,
                'venueName' => EventVenueHelper::venueName($eventLocation),
                'venueLocation' => $coordinates,
            ], static fn ($value) => $value !== null),
            ...$this->colors($event, $passSettings),
            'eventTicket' => $this->eventTicket($attendee, $event, $eventName, $eventLocation, $startDate, $endDate),
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function eventTicket(
        AttendeeDomainObject $attendee,
        EventDomainObject $event,
        string $eventName,
        ?EventLocationDomainObject $eventLocation,
        ?string $startDate,
        ?string $endDate,
    ): array {
        $venueName = EventVenueHelper::venueName($eventLocation);
        $address = EventVenueHelper::formattedAddress($eventLocation);
        $holderName = trim($attendee->getFirstName().' '.$attendee->getLastName());
        $productTitle = $attendee->getProduct()?->getTitle();

        return array_filter([
            'headerFields' => $attendee->getStatus() === AttendeeStatus::AWAITING_PAYMENT->name
                ? [$this->textField('status', __('Status'), __('Awaiting payment'))]
                : [],
            'primaryFields' => [$this->textField('event', __('Event'), $eventName)],
            'secondaryFields' => $this->fields([
                $startDate === null ? null : $this->dateField('starts', __('Starts'), $startDate),
                $venueName === null ? null : $this->textField('venue', __('Location'), $venueName),
            ]),
            'auxiliaryFields' => $this->fields([
                $holderName === '' ? null : $this->textField('attendee', __('Attendee'), $holderName),
                $productTitle === null ? null : $this->textField('ticket', __('Ticket'), $productTitle),
            ]),
            'backFields' => $this->fields([
                $endDate === null ? null : $this->dateField('ends', __('Ends'), $endDate),
                $address === null ? null : $this->textField('address', __('Address'), $address),
                $this->textField('ticket_number', __('Ticket number'), $attendee->getPublicId()),
                $this->linkField('ticket_link', __('View Ticket'), sprintf(
                    Url::getFrontEndUrlFromConfig(Url::ATTENDEE_TICKET),
                    $event->getId(),
                    $attendee->getShortId(),
                )),
                $this->linkField('event_link', __('Event Details'), $event->getEventUrl()),
            ]),
        ]);
    }

    private function colors(EventDomainObject $event, WalletPassBrandingDTO $passSettings): array
    {
        $hex = HexColorHelper::toRgbHex($event->getEventSettings()?->getWalletPassBackgroundColor())
            ?? $passSettings->backgroundColor
            ?? $this->coverColour($event, $passSettings)
            ?? $passSettings->themeAccentColor;

        if ($hex === null) {
            return [];
        }

        [$red, $green, $blue] = sscanf($hex, '#%02x%02x%02x');

        $textColor = (0.299 * $red + 0.587 * $green + 0.114 * $blue) / 255 < self::DARK_BACKGROUND_LUMINANCE
            ? self::LIGHT_TEXT_COLOR
            : self::DARK_TEXT_COLOR;

        return [
            'backgroundColor' => sprintf('rgb(%d, %d, %d)', $red, $green, $blue),
            'foregroundColor' => $textColor,
            'labelColor' => $textColor,
        ];
    }

    private function coverColour(EventDomainObject $event, WalletPassBrandingDTO $passSettings): ?string
    {
        $eventSettings = $event->getEventSettings();

        if (trim((string) $eventSettings?->getWalletPassAppleStripUrl()) !== ''
            || trim((string) $eventSettings?->getWalletPassBannerUrl()) !== ''
            || $passSettings->appleStripImageUrl !== null
            || $passSettings->bannerImageUrl !== null) {
            return null;
        }

        return HexColorHelper::toRgbHex($event->getImages()
            ?->first(static fn (ImageDomainObject $image) => $image->getType() === ImageType::EVENT_COVER->name)
            ?->getAvgColour());
    }

    private function coordinates(?EventLocationDomainObject $eventLocation): ?array
    {
        $location = $eventLocation?->getLocation();

        if ($location?->getLatitude() === null || $location->getLongitude() === null) {
            return null;
        }

        return [
            'latitude' => $location->getLatitude(),
            'longitude' => $location->getLongitude(),
        ];
    }

    private function eventName(EventDomainObject $event, ?EventOccurrenceDomainObject $occurrence): string
    {
        $label = $occurrence?->getLabel();

        return $label ? $event->getTitle().' - '.$label : $event->getTitle();
    }

    private function isoDate(?string $utcDate, string $timezone): ?string
    {
        if ($utcDate === null) {
            return null;
        }

        return Carbon::parse($utcDate, 'UTC')->setTimezone($timezone)->toIso8601String();
    }

    /**
     * @param  array<int, array|null>  $fields
     */
    private function fields(array $fields): array
    {
        return array_values(array_filter($fields));
    }

    private function textField(string $key, string $label, string $value): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
        ];
    }

    private function dateField(string $key, string $label, string $isoDate): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $isoDate,
            'dateStyle' => 'PKDateStyleMedium',
            'timeStyle' => 'PKDateStyleShort',
            'ignoresTimeZone' => true,
        ];
    }

    private function linkField(string $key, string $label, string $url): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $url,
            'attributedValue' => sprintf('<a href="%s">%s</a>', e($url), e($label)),
        ];
    }
}
