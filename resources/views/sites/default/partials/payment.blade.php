{{--
    Stripe's payment form. On the checkout page it is filled in by the script
    once the stay is held; on the booking page it arrives with the intent's
    client secret, for a guest coming back to pay.
--}}
<section class="checkout__form section" data-payment
    data-publishable-key="{{ config('services.stripe.key') }}"
    @if ($clientSecret) data-client-secret="{{ $clientSecret }}" @endif
    @unless ($clientSecret) hidden @endunless>
    <h2>Payment</h2>
    <p class="muted small" data-held-until hidden></p>
    <div data-payment-element class="payment-element"></div>
    <p class="notice" data-payment-message role="alert" hidden></p>
    <button type="button" class="button button--wide" data-pay>{{ $payLabel }}</button>
    @if ($note)
        <p class="muted small">{{ $note }}</p>
    @endif
    <p class="muted small">Card details go straight to Stripe, our payment provider; we never see them.</p>
</section>
