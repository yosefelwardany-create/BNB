<x-mail::message>
# Your request was not accepted

Hi {{ $booking['first_name'] }}, we are sorry: your request for **{{ $booking['listing'] }}** could not be accepted.

@if ($reason)
> {{ $reason }}
@endif

Nothing was charged. The authorisation on your card is released; depending on your bank it can take a few days to disappear from your statement.

<x-mail::button :url="$booking['site_url']">
Find another stay
</x-mail::button>

@include('mail.websites._contact')
</x-mail::message>
