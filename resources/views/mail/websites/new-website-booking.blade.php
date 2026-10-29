<x-mail::message>
@if ($booking['is_request'])
# New request to book {{ $booking['listing'] }}

{{ $booking['guest_name'] }} asked to book through {{ $booking['site'] }}. Their card is authorised for {{ $booking['due_now'] }}.

@if ($booking['answer_by'])
**Answer by {{ $booking['answer_by'] }}**, or the request is declined for you and the guest's money released.
@endif
@else
# New booking: {{ $booking['listing'] }}

{{ $booking['guest_name'] }} booked and paid {{ $booking['due_now'] }} through {{ $booking['site'] }}.
@endif

@include('mail.websites._stay')

<x-mail::table>
| Guest | |
|:--|--:|
| Name | {{ $booking['guest_name'] }} |
| Email | {{ $booking['guest_email'] }} |
| Phone | {{ $booking['guest_phone'] }} |
</x-mail::table>

@if ($booking['message'])
> {{ $booking['message'] }}
@endif

<x-mail::button :url="$booking['app_url']">
{{ $booking['is_request'] ? 'Answer in Habitat' : 'Open in Habitat' }}
</x-mail::button>
</x-mail::message>
