<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\GoogleWallet;

use Carbon\Carbon;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\DateHelper;
use HiEvents\Helper\EventVenueHelper;
use HiEvents\Services\Domain\GoogleWallet\DTO\GoogleWalletPassSettingsDTO;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Translation\Translator;

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
            'logo' => $this->image($passSettings->logoUrl, $organizer->getName()),
            'heroImage' => $this->image($passSettings->heroImageUrl, $event->getTitle()),
            'hexBackgroundColor' => $passSettings->backgroundColor,
        ], static fn ($value) => $value !== null && $value !== []);
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
        if ($uri === null) {
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
