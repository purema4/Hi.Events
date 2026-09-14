@php /** @var \HiEvents\DomainObjects\EventDomainObject $event */ @endphp
@php /** @var \HiEvents\DomainObjects\EventSettingDomainObject $eventSettings */ @endphp
@php /** @var \HiEvents\DomainObjects\OrganizerDomainObject $organizer */ @endphp
@php /** @var \HiEvents\DomainObjects\OrderDomainObject $order */ @endphp
@php /** @var \Illuminate\Support\Collection<int, \HiEvents\Services\Domain\Email\DTO\AttendeeTicketSummaryDTO> $tickets */ @endphp
@php /** @var bool $ticketsShareSchedule */ @endphp
@php /** @var string $ticketUrl */ @endphp
@php /** @var string|null $googleWalletSaveUrl */ @endphp
@php /** @var string|null $googleWalletButtonPath */ @endphp
@php /** @var string|null $appleWalletPassUrl */ @endphp
@php /** @var string|null $appleWalletButtonPath */ @endphp
@php /** @see \HiEvents\Mail\Attendee\AttendeeTicketMail */ @endphp

@php
    $first = $tickets->first();
@endphp

<x-mail::message>
# {{ __('You\'re going to') }} {{ $event->getTitle() }}! 🎉

@if($order->isOrderAwaitingOfflinePayment())
<div style="border-radius: 4px; background-color: #f8d7da; color: #842029; margin-bottom: 1.5rem; padding: 1rem;">
<p>
{{ __('ℹ️ Your order is pending payment. Tickets have been issued but will not be valid until payment is received.') }}
</p>
</div>
@endif

@if($tickets->count() > 1)
{{ __('Please find the details for your :count tickets below.', ['count' => $tickets->count()]) }}
@else
{{ __('Please find your ticket details below.') }}
@endif

<div style="border: 1px solid #e5e7eb; border-radius: 6px; padding: 16px; margin: 16px 0; line-height: 1.6;">
@if($ticketsShareSchedule)
@if($first->startFormatted)
<strong>{{ __('Date & Time:') }}</strong> {{ $first->startFormatted }}@if($first->endFormatted) – {{ $first->endFormatted }}@endif<br>
@if($first->sessionLabel)
<strong>{{ __('Session:') }}</strong> {{ $first->sessionLabel }}<br>
@endif
@endif
@if($first->venueName || $first->addressString)
<strong>{{ __('Location:') }}</strong> {{ trim(($first->venueName ? $first->venueName . ($first->addressString ? ', ' : '') : '') . ($first->addressString ?? '')) }}<br>
@endif
@endif
<strong>@if($tickets->count() > 1){{ __('Tickets:') }}@else{{ __('Ticket:') }}@endif</strong><br>
@foreach($tickets as $ticket)
{{ $ticket->productTitle }} — {{ $ticket->attendeeName }}@if(! $ticketsShareSchedule && $ticket->startFormatted) · {{ $ticket->sessionLabel ?: $ticket->startFormatted }}@endif<br>
@endforeach
</div>

<x-mail::button :url="$ticketUrl">
@if($tickets->count() > 1){{ __('View Tickets') }}@else{{ __('View Ticket') }}@endif
</x-mail::button>

@if($googleWalletSaveUrl || $appleWalletPassUrl)
<div style="text-align: center; margin: 0 0 16px; font-size: 0; line-height: 0;">
@if($googleWalletSaveUrl)
@php($googleWalletLabel = $tickets->count() > 1
    ? __('Add :count tickets to Google Wallet', ['count' => $tickets->count()])
    : __('Add to Google Wallet'))
@php([$googleWalletImageWidth, $googleWalletImageHeight] = getimagesize($googleWalletButtonPath))
<a href="{{ $googleWalletSaveUrl }}" style="display: inline-block; margin: 8px; text-decoration: none; vertical-align: middle; font-size: 14px; line-height: normal;">
<img src="{{ $message->embed($googleWalletButtonPath) }}" alt="{{ $googleWalletLabel }}" width="{{ (int) round($googleWalletImageWidth * 50 / $googleWalletImageHeight) }}" height="50" style="height: 50px; width: auto; border: 0; display: block;">
</a>
@endif
@if($appleWalletPassUrl)
@php($appleWalletLabel = $tickets->count() > 1
    ? __('Add :count tickets to Apple Wallet', ['count' => $tickets->count()])
    : __('Add to Apple Wallet'))
@php([$appleWalletImageWidth, $appleWalletImageHeight] = getimagesize($appleWalletButtonPath))
<a href="{{ $appleWalletPassUrl }}" style="display: inline-block; margin: 8px; text-decoration: none; vertical-align: middle; font-size: 14px; line-height: normal;">
<img src="{{ $message->embed($appleWalletButtonPath) }}" alt="{{ $appleWalletLabel }}" width="{{ (int) round($appleWalletImageWidth * 50 / $appleWalletImageHeight) }}" height="50" style="height: 50px; width: auto; border: 0; display: block;">
</a>
@endif
</div>
@endif

{{ __('If you have any questions or need assistance, please reply to this email or contact the event organizer') }}
{{ __('at') }} <a href="mailto:{{$eventSettings->getSupportEmail()}}">{{$eventSettings->getSupportEmail()}}</a>.

{{ __('Best regards,') }}<br>
{{ $organizer->getName() ?: config('app.name') }}

</x-mail::message>
