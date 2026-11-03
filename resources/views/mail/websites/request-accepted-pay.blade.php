<x-mail::message>
# Your request is accepted

Hi {{ $booking['first_name'] }}, good news: the host accepted your request for **{{ $booking['listing'] }}**.

To confirm your stay, please pay {{ $booking['due_now'] }} by {{ $payBy }}. The dates are held for you until then.

<x-mail::button :url="$payUrl">
Pay {{ $booking['due_now'] }}
</x-mail::button>

@include('mail.websites._stay')

@if ($booking['booking_url'])
You can see your booking at any time on [your booking page]({{ $booking['booking_url'] }}).
@endif

@include('mail.websites._contact')
</x-mail::message>
