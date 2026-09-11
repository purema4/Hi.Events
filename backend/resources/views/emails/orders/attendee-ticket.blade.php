@php /** @var \HiEvents\DomainObjects\EventDomainObject $event */ @endphp
@php /** @var \HiEvents\DomainObjects\EventSettingDomainObject $eventSettings */ @endphp
@php /** @var \HiEvents\DomainObjects\OrganizerDomainObject $organizer */ @endphp
@php /** @var \HiEvents\DomainObjects\OrderDomainObject $order */ @endphp
@php /** @var \Illuminate\Support\Collection<int, \HiEvents\Services\Domain\Email\DTO\AttendeeTicketSummaryDTO> $tickets */ @endphp
@php /** @var bool $ticketsShareSchedule */ @endphp
@php /** @var string $ticketUrl */ @endphp
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

{{ __('If you have any questions or need assistance, please reply to this email or contact the event organizer') }}
{{ __('at') }} <a href="mailto:{{$eventSettings->getSupportEmail()}}">{{$eventSettings->getSupportEmail()}}</a>.

{{ __('Best regards,') }}<br>
{{ $organizer->getName() ?: config('app.name') }}

</x-mail::message>
