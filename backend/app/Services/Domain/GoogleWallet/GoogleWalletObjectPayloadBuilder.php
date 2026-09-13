<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\GoogleWallet;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Helper\Url;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Translation\Translator;

class GoogleWalletObjectPayloadBuilder
{
    public function __construct(
        private readonly Repository $config,
        private readonly Translator $translator,
    ) {}

    public function build(
        string $objectId,
        string $classId,
        AttendeeDomainObject $attendee,
        EventDomainObject $event,
        ?string $price,
    ): array {
        return array_filter([
            'id' => $objectId,
            'classId' => $classId,
            'state' => $this->state($attendee),
            'ticketHolderName' => $this->ticketHolderName($attendee),
            'ticketNumber' => $attendee->getPublicId(),
            'ticketType' => $this->ticketType($attendee),
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => $attendee->getPublicId(),
                'alternateText' => $attendee->getPublicId(),
            ],
            'smartTapRedemptionValue' => $this->smartTapRedemptionValue($attendee),
            'textModulesData' => $this->textModules($attendee, $price),
            'linksModuleData' => [
                'uris' => [
                    [
                        'uri' => $this->ticketUrl($attendee, $event),
                        'description' => __('View Ticket'),
                        'id' => 'ticket',
                    ],
                ],
            ],
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function textModules(AttendeeDomainObject $attendee, ?string $price): array
    {
        $productTitle = $attendee->getProduct()?->getTitle();

        return array_values(array_filter([
            $productTitle === null ? null : [
                'id' => 'ticket',
                'header' => __('Ticket'),
                'body' => $productTitle,
            ],
            $price === null ? null : [
                'id' => 'price',
                'header' => __('Price'),
                'body' => $price,
            ],
        ]));
    }

    private function smartTapRedemptionValue(AttendeeDomainObject $attendee): ?string
    {
        $redemptionIssuerId = trim((string) $this->config->get('google-wallet.redemption_issuer_id'));

        return $redemptionIssuerId === '' ? null : $attendee->getPublicId();
    }

    private function state(AttendeeDomainObject $attendee): string
    {
        return match ($attendee->getStatus()) {
            AttendeeStatus::ACTIVE->name => 'ACTIVE',
            AttendeeStatus::AWAITING_PAYMENT->name => 'INACTIVE',
            default => 'EXPIRED',
        };
    }

    private function ticketHolderName(AttendeeDomainObject $attendee): ?string
    {
        $name = trim($attendee->getFirstName().' '.$attendee->getLastName());

        return $name === '' ? null : $name;
    }

    private function ticketType(AttendeeDomainObject $attendee): ?array
    {
        $productTitle = $attendee->getProduct()?->getTitle();

        if ($productTitle === null) {
            return null;
        }

        return [
            'defaultValue' => [
                'language' => str_replace('_', '-', $this->translator->getLocale()),
                'value' => $productTitle,
            ],
        ];
    }

    private function ticketUrl(AttendeeDomainObject $attendee, EventDomainObject $event): string
    {
        return sprintf(
            Url::getFrontEndUrlFromConfig(Url::ATTENDEE_TICKET),
            $event->getId(),
            $attendee->getShortId(),
        );
    }
}
