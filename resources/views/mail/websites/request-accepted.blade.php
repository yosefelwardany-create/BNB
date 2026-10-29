<x-mail::message>
# Your request is accepted

Hi {{ $booking['first_name'] }}, good news: the host accepted your request for **{{ $booking['listing'] }}**, and {{ $booking['due_now'] }} has been taken from your card.

@include('mail.websites._stay')

@foreach ($booking['later'] as $instalment)
The remaining {{ $instalment['amount'] }} is taken from the same card on {{ $instalment['due_on'] }}.
@endforeach

@if ($booking['booking_url'])
<x-mail::button :url="$booking['booking_url']">
View your booking
</x-mail::button>
@endif

@include('mail.websites._contact')
</x-mail::message>
