<x-mail::message>
# Your request is with the host

Hi {{ $booking['first_name'] }}, we have sent your request for **{{ $booking['listing'] }}** to the host.
@if ($booking['answer_by'])
They will answer by {{ $booking['answer_by'] }}.
@endif

Your card is authorised for {{ $booking['due_now'] }}. It is charged only if the host accepts; if they decline, the authorisation is released.

@include('mail.websites._stay')

@if ($booking['booking_url'])
<x-mail::button :url="$booking['booking_url']">
View your request
</x-mail::button>
@endif

@include('mail.websites._contact')
</x-mail::message>
