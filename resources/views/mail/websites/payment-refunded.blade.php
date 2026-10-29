<x-mail::message>
# Your payment has been refunded

Hi {{ $booking['first_name'] }}, your payment of {{ $amount }} for **{{ $booking['listing'] }}** reached us after we could no longer keep the booking for you.

> {{ $why }}

We have refunded the {{ $amount }} to your card in full. Depending on your bank it can take 5 to 10 days to appear on your statement.

<x-mail::button :url="$booking['site_url']">
Find another stay
</x-mail::button>

@include('mail.websites._contact')
</x-mail::message>
