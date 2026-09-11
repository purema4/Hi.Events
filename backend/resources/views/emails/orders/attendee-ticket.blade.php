@php /** @var \HiEvents\DomainObjects\EventDomainObject $event */ @endphp
@php /** @var \HiEvents\DomainObjects\EventSettingDomainObject $eventSettings */ @endphp
@php /** @var \HiEvents\DomainObjects\OrganizerDomainObject $organizer */ @endphp
@php /** @var \HiEvents\DomainObjects\OrderDomainObject $order */ @endphp
@php /** @var \Illuminate\Support\Collection<int, \HiEvents\Services\Domain\Email\DTO\AttendeeTicketSummaryDTO> $tickets */ @endphp
@php /** @var string|null $googleWalletSaveUrl */ @endphp
@php /** @var string|null $googleWalletButtonPath */ @endphp
@php /** @see \HiEvents\Mail\Attendee\AttendeeTicketMail */ @endphp

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

@foreach($tickets as $ticket)
@if($ticket->startFormatted || $ticket->venueName || $ticket->addressString || $ticket->productTitle)
<div style="border: 1px solid #e5e7eb; border-radius: 6px; padding: 16px; margin: 16px 0; line-height: 1.6;">
@if($ticket->startFormatted)
<strong>{{ __('Date & Time:') }}</strong> {{ $ticket->startFormatted }}@if($ticket->endFormatted) – {{ $ticket->endFormatted }}@endif<br>
@if($ticket->sessionLabel)
<strong>{{ __('Session:') }}</strong> {{ $ticket->sessionLabel }}<br>
@endif
@endif
@if($ticket->venueName || $ticket->addressString)
<strong>{{ __('Location:') }}</strong> {{ trim(($ticket->venueName ? $ticket->venueName . ($ticket->addressString ? ', ' : '') : '') . ($ticket->addressString ?? '')) }}<br>
@endif
@if($ticket->productTitle)
<strong>{{ __('Ticket:') }}</strong> {{ $ticket->productTitle }}<br>
@endif
<strong>{{ __('Attendee:') }}</strong> {{ $ticket->attendeeName }}
</div>
@endif

<x-mail::button :url="$ticket->ticketUrl">
{{ __('View Ticket') }}
</x-mail::button>
@endforeach

@if($googleWalletSaveUrl)
@php($googleWalletLabel = $tickets->count() > 1
    ? __('Add :count tickets to Google Wallet', ['count' => $tickets->count()])
    : __('Add to Google Wallet'))
<div style="text-align: center; margin: 0 0 24px; padding: 8px;">
<a href="{{ $googleWalletSaveUrl }}" style="display: inline-block; text-decoration: none;">
<img src="{{ $message->embed($googleWalletButtonPath) }}" alt="{{ $googleWalletLabel }}" height="50" style="height: auto; width: auto; border: 0; display: block;">
</a>
</div>
@endif

{{ __('If you have any questions or need assistance, please reply to this email or contact the event organizer') }}
{{ __('at') }} <a href="mailto:{{$eventSettings->getSupportEmail()}}">{{$eventSettings->getSupportEmail()}}</a>.

{{ __('Best regards,') }}<br>
{{ $organizer->getName() ?: config('app.name') }}

</x-mail::message>
