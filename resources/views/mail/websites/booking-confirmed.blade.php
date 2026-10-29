<x-mail::message>
# Your stay is booked

Hi {{ $booking['first_name'] }}, thank you for booking **{{ $booking['listing'] }}**. We have received {{ $booking['due_now'] }}.

@include('mail.websites._stay')

@foreach ($booking['later'] as $instalment)
The remaining {{ $instalment['amount'] }} is taken from the same card on {{ $instalment['due_on'] }}.
@endforeach

@if ($booking['booking_url'])
<x-mail::button :url="$booking['booking_url']">
View your booking
</x-mail::button>
@endif

The exact address and arrival details are sent before your stay.

@include('mail.websites._contact')
</x-mail::message>
