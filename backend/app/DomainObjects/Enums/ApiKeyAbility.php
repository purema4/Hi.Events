<?php

namespace HiEvents\DomainObjects\Enums;

enum ApiKeyAbility: string
{
    use BaseEnum;

    case EVENTS_READ = 'events:read';
    case PRODUCTS_READ = 'products:read';
    case ORDERS_READ = 'orders:read';
    case ATTENDEES_READ = 'attendees:read';
    case CHECK_IN_LISTS_READ = 'check-in-lists:read';
    case REPORTS_READ = 'reports:read';
    case WEBHOOKS_READ = 'webhooks:read';
    case CHECK_INS_WRITE = 'check-ins:write';

    public function allowsWrites(): bool
    {
        return $this === self::CHECK_INS_WRITE;
    }
}
