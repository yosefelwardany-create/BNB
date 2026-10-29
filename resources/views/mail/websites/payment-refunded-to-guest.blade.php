<x-mail::message>
# Payment refunded: {{ $booking['listing'] }}

{{ $booking['guest_name'] }} paid {{ $amount }} through {{ $booking['site'] }}, but the stay was no longer booked when the payment arrived.

> {{ $why }}

The {{ $amount }} was refunded to the guest in full automatically, and they have been told. There is nothing for you to do.

@include('mail.websites._stay')

<x-mail::table>
| Guest | |
|:--|--:|
| Name | {{ $booking['guest_name'] }} |
| Email | {{ $booking['guest_email'] }} |
| Phone | {{ $booking['guest_phone'] }} |
</x-mail::table>

<x-mail::button :url="$booking['app_url']">
Open in Habitat
</x-mail::button>
</x-mail::message>
