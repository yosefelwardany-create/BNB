<x-mail::message>
# Shopify order {{ $orderName }} needs attention

{{ $booking['guest_name'] }} paid for **{{ $booking['listing'] }}** on your Shopify store, and the booking could not simply be confirmed.

> {{ $why }}

The money is in your Shopify store. If it has to go back to the guest, refund order {{ $orderName }} in Shopify.

@include('mail.websites._stay')

<x-mail::button :url="$booking['app_url']">
Open in Habitat
</x-mail::button>
</x-mail::message>
