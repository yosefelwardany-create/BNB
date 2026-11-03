{{-- Booking the old way, while the site cannot take payment. --}}
<div class="booking-box__contact">
    @if (\App\Domain\Websites\Support\SitePayments::onShopify($site))
        {{-- Paid on the client's store, which cannot take it just now. --}}
        <p><strong>Online booking is unavailable right now</strong></p>
        <p class="muted small">Please get in touch, and we will book your stay for you.</p>
    @else
        <p><strong>Contact us to book</strong></p>
    @endif
    @if ($site->setting('contact_email'))
        <a class="button button--wide" href="mailto:{{ $site->setting('contact_email') }}?subject={{ rawurlencode('Booking: '.$listing->displayTitle()) }}">Email us</a>
    @endif
    @if ($site->setting('contact_phone'))
        <a class="button button--quiet button--wide" href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $site->setting('contact_phone')) }}">{{ $site->setting('contact_phone') }}</a>
    @endif
</div>
