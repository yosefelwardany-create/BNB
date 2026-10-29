<x-mail::table>
| | |
|:--|--:|
@if ($booking['check_in'])
| Check-in | {{ $booking['check_in'] }} |
| Check-out | {{ $booking['check_out'] }} |
@endif
| Guests | {{ $booking['guests'] }} |
| Total | **{{ $booking['total'] }}** |
@if ($booking['reference'])
| Reference | {{ $booking['reference'] }} |
@endif
</x-mail::table>
