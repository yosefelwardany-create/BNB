{{-- Booking the old way, while the site cannot take payment. --}}
<div class="booking-box__contact">
    <p><strong>Contact us to book</strong></p>
    @if ($site->setting('contact_email'))
        <a class="button button--wide" href="mailto:{{ $site->setting('contact_email') }}?subject={{ rawurlencode('Booking: '.$listing->displayTitle()) }}">Email us</a>
    @endif
    @if ($site->setting('contact_phone'))
        <a class="button button--quiet button--wide" href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $site->setting('contact_phone')) }}">{{ $site->setting('contact_phone') }}</a>
    @endif
</div>
