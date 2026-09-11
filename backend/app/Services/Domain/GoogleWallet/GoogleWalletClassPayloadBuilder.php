<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\GoogleWallet;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\DateHelper;
use HiEvents\Helper\EventVenueHelper;
use HiEvents\Helper\HexColorHelper;
use HiEvents\Helper\Url;
use HiEvents\Services\Domain\GoogleWallet\DTO\GoogleWalletPassSettingsDTO;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Collection;

class GoogleWalletClassPayloadBuilder
{
    public function __construct(
        private readonly Repository $config,
        private readonly Translator $translator,
    ) {}

    public function build(
        string $classId,
        EventDomainObject $event,
        EventOccurrenceDomainObject $occurrence,
        OrganizerDomainObject $organizer,
        GoogleWalletPassSettingsDTO $passSettings,
    ): array {
        return array_filter([
            'id' => $classId,
            'issuerName' => $organizer->getName() ?: (string) $this->config->get('google-wallet.issuer_name'),
            'reviewStatus' => 'UNDER_REVIEW',
            'eventName' => $this->localizedString($this->eventName($event, $occurrence)),
            'homepageUri' => [
                'uri' => $event->getEventUrl(),
                'description' => __('Event Details'),
            ],
            'dateTime' => $this->dateTime($event, $occurrence),
            'venue' => $this->venue($event, $occurrence),
            'logo' => $this->image($this->logoUrl($event, $organizer, $passSettings), $organizer->getName()),
            'heroImage' => $this->image($this->heroImageUrl($event, $passSettings), $event->getTitle()),
            'hexBackgroundColor' => $this->backgroundColor($event, $passSettings),
            'multipleDevicesAndHoldersAllowedStatus' => 'MULTIPLE_HOLDERS',
            'textModulesData' => $this->textModules($event, $occurrence),
            ...$this->smartTap(),
        ], static fn ($value) => $value !== null && $value !== []);
    }

    private function textModules(EventDomainObject $event, EventOccurrenceDomainObject $occurrence): array
    {
        $endDate = $this->readableDateTime($occurrence->getEndDate(), $event->getTimezone());
        $address = EventVenueHelper::formattedAddress(
            $occurrence->getEventLocation() ?? $event->getEventLocation()
        );

        return array_values(array_filter([
            $endDate === null ? null : [
                'id' => 'event_end',
                'header' => __('Ends'),
                'body' => $endDate,
            ],
            $address === null ? null : [
                'id' => 'event_address',
                'header' => __('Address'),
                'body' => $address,
            ],
        ]));
    }

    private function readableDateTime(?string $utcDate, string $timezone): ?string
    {
        if ($utcDate === null) {
            return null;
        }

        return Carbon::parse(DateHelper::convertFromUTC($utcDate, $timezone))->format('D, M j, Y · g:i A');
    }

    private function heroImageUrl(EventDomainObject $event, GoogleWalletPassSettingsDTO $passSettings): ?string
    {
        return $this->nonEmpty($event->getEventSettings()?->getGoogleWalletBannerUrl())
            ?? $passSettings->heroImageUrl
            ?? $this->imageUrl($event->getImages(), ImageType::EVENT_COVER);
    }

    private function logoUrl(
        EventDomainObject $event,
        OrganizerDomainObject $organizer,
        GoogleWalletPassSettingsDTO $passSettings,
    ): ?string {
        return $this->nonEmpty($event->getEventSettings()?->getGoogleWalletLogoUrl())
            ?? $passSettings->logoUrl
            ?? $this->imageUrl($organizer->getImages(), ImageType::ORGANIZER_LOGO);
    }

    private function backgroundColor(EventDomainObject $event, GoogleWalletPassSettingsDTO $passSettings): ?string
    {
        return HexColorHelper::toRgbHex($event->getEventSettings()?->getGoogleWalletBackgroundColor())
            ?? $passSettings->backgroundColor;
    }

    private function nonEmpty(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function smartTap(): array
    {
        $redemptionIssuerId = trim((string) $this->config->get('google-wallet.redemption_issuer_id'));

        if ($redemptionIssuerId === '') {
            return [];
        }

        return [
            'enableSmartTap' => true,
            'redemptionIssuers' => [$redemptionIssuerId],
        ];
    }

    /**
     * @param  Collection<int, ImageDomainObject>|null  $images
     */
    private function imageUrl(?Collection $images, ImageType $type): ?string
    {
        $path = $images
            ?->first(static fn (ImageDomainObject $image) => $image->getType() === $type->name)
            ?->getPath();

        return $path === null ? null : Url::getCdnUrl($path);
    }

    private function eventName(EventDomainObject $event, EventOccurrenceDomainObject $occurrence): string
    {
        $label = $occurrence->getLabel();

        return $label ? $event->getTitle().' - '.$label : $event->getTitle();
    }

    private function dateTime(EventDomainObject $event, EventOccurrenceDomainObject $occurrence): array
    {
        return array_filter([
            'start' => $this->venueLocalDateTime($occurrence->getStartDate(), $event->getTimezone()),
            'end' => $this->venueLocalDateTime($occurrence->getEndDate(), $event->getTimezone()),
        ], static fn ($value) => $value !== null);
    }

    private function venue(EventDomainObject $event, EventOccurrenceDomainObject $occurrence): ?array
    {
        $eventLocation = $occurrence->getEventLocation() ?? $event->getEventLocation();

        $address = EventVenueHelper::formattedAddress($eventLocation);
        $name = EventVenueHelper::venueName($eventLocation) ?? $address;

        if ($name === null || $address === null) {
            return null;
        }

        return [
            'name' => $this->localizedString($name),
            'address' => $this->localizedString($address),
        ];
    }

    private function image(?string $uri, ?string $description): ?array
    {
        if ($uri === null || ! str_starts_with(strtolower($uri), 'https://')) {
            return null;
        }

        return [
            'sourceUri' => ['uri' => $uri],
            'contentDescription' => $this->localizedString($description ?? ''),
        ];
    }

    private function venueLocalDateTime(?string $utcDate, string $timezone): ?string
    {
        if ($utcDate === null) {
            return null;
        }

        return Carbon::parse(DateHelper::convertFromUTC($utcDate, $timezone))->format('Y-m-d\TH:i:s');
    }

    private function localizedString(string $value): array
    {
        return [
            'defaultValue' => [
                'language' => str_replace('_', '-', $this->translator->getLocale()),
                'value' => $value,
            ],
        ];
    }
}
