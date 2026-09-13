<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\GoogleWallet;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventLocationDomainObject;
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
        private readonly GoogleWalletBannerService $bannerService,
    ) {}

    public function build(
        string $classId,
        EventDomainObject $event,
        EventOccurrenceDomainObject $occurrence,
        OrganizerDomainObject $organizer,
        GoogleWalletPassSettingsDTO $passSettings,
    ): array {
        $explicitHeroImageUrl = $this->explicitHeroImageUrl($event, $passSettings);
        $cover = $explicitHeroImageUrl === null ? $this->coverImage($event) : null;
        $backgroundColor = $this->backgroundColor($event, $passSettings, $cover);

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
            'heroImage' => $this->image(
                $explicitHeroImageUrl ?? $this->coverBannerUrl($cover, $backgroundColor),
                $event->getTitle(),
            ),
            'hexBackgroundColor' => $backgroundColor,
            'multipleDevicesAndHoldersAllowedStatus' => 'MULTIPLE_HOLDERS',
            'textModulesData' => $this->textModules($event, $occurrence),
            'classTemplateInfo' => $this->cardTemplate(),
            ...$this->smartTap(),
        ], static fn ($value) => $value !== null && $value !== []);
    }

    private function textModules(EventDomainObject $event, EventOccurrenceDomainObject $occurrence): array
    {
        $startDate = $this->readableDateTime($occurrence->getStartDate(), $event->getTimezone());
        $endDate = $this->readableDateTime($occurrence->getEndDate(), $event->getTimezone());
        $location = $this->location($occurrence->getEventLocation() ?? $event->getEventLocation());

        return array_values(array_filter([
            $startDate === null ? null : [
                'id' => 'event_date',
                'header' => __('Date'),
                'body' => $startDate,
            ],
            $location === null ? null : [
                'id' => 'event_location',
                'header' => __('Location'),
                'body' => $location,
            ],
            $endDate === null ? null : [
                'id' => 'event_end',
                'header' => __('Ends'),
                'body' => $endDate,
            ],
        ]));
    }

    private function cardTemplate(): array
    {
        return [
            'cardTemplateOverride' => [
                'cardRowTemplateInfos' => [
                    [
                        'twoItems' => [
                            'startItem' => $this->cardField("object.textModulesData['ticket']"),
                            'endItem' => $this->cardField("object.textModulesData['price']"),
                        ],
                    ],
                    [
                        'twoItems' => [
                            'startItem' => $this->cardField("class.textModulesData['event_date']"),
                            'endItem' => $this->cardField("class.textModulesData['event_location']"),
                        ],
                    ],
                ],
            ],
        ];
    }

    private function cardField(string $fieldPath): array
    {
        return ['firstValue' => ['fields' => [['fieldPath' => $fieldPath]]]];
    }

    private function location(?EventLocationDomainObject $eventLocation): ?string
    {
        $name = EventVenueHelper::venueName($eventLocation);
        $address = EventVenueHelper::formattedAddress($eventLocation);

        if ($name === null || ($address !== null && str_starts_with($address, $name))) {
            return $address;
        }

        return $address === null ? $name : $name.', '.$address;
    }

    private function readableDateTime(?string $utcDate, string $timezone): ?string
    {
        if ($utcDate === null) {
            return null;
        }

        return Carbon::parse(DateHelper::convertFromUTC($utcDate, $timezone))
            ->locale($this->translator->getLocale())
            ->translatedFormat('F j, Y @ g:i a');
    }

    private function explicitHeroImageUrl(EventDomainObject $event, GoogleWalletPassSettingsDTO $passSettings): ?string
    {
        return $this->nonEmpty($event->getEventSettings()?->getGoogleWalletBannerUrl())
            ?? $passSettings->heroImageUrl;
    }

    private function coverImage(EventDomainObject $event): ?ImageDomainObject
    {
        return $event->getImages()?->first(
            static fn (ImageDomainObject $image) => $image->getType() === ImageType::EVENT_COVER->name
        );
    }

    private function coverBannerUrl(?ImageDomainObject $cover, ?string $backgroundColor): ?string
    {
        if ($cover === null) {
            return null;
        }

        $coverUrl = Url::getCdnUrl($cover->getPath());

        if ($backgroundColor === null) {
            return $coverUrl;
        }

        return $this->bannerService->bannerUrlForCover($cover, $backgroundColor) ?? $coverUrl;
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

    private function backgroundColor(
        EventDomainObject $event,
        GoogleWalletPassSettingsDTO $passSettings,
        ?ImageDomainObject $cover,
    ): ?string {
        return HexColorHelper::toRgbHex($event->getEventSettings()?->getGoogleWalletBackgroundColor())
            ?? $passSettings->backgroundColor
            ?? HexColorHelper::toRgbHex($cover?->getAvgColour())
            ?? $passSettings->themeAccentColor;
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
