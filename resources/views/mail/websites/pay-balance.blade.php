<x-mail::message>
# A payment is needed for your stay

@if ($booking['on_store'] ?? false)
Hi {{ $booking['first_name'] }}, the remaining {{ $amount }} for your stay at **{{ $booking['listing'] }}** is now due.

Please pay it on our store to keep your booking:
@else
Hi {{ $booking['first_name'] }}, we could not take the {{ $amount }} due for your stay at **{{ $booking['listing'] }}** from your card.

@if ($why)
> {{ $why }}
@endif

Please pay it with the card of your choice to keep your booking:
@endif

@if ($booking['booking_url'])
<x-mail::button :url="$booking['booking_url']">
Pay {{ $amount }}
</x-mail::button>
@endif

@include('mail.websites._stay')

@include('mail.websites._contact')
</x-mail::message>
