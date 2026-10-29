@if ($booking['contact_email'] || $booking['contact_phone'])
Questions? Reply to this email{{ $booking['contact_phone'] ? ' or call '.$booking['contact_phone'] : '' }}.
@endif

{{ $booking['site'] }}
