{{-- What is still due: on a card stay, what the saved card could not pay,
     for the guest to pay with a card of their choice; on a stay paid on the
     client's Shopify store, the rest, paid there. --}}
@if ($checkout->payment_channel === \App\Domain\Websites\Models\WebsiteCheckout::CHANNEL_SHOPIFY)
<section class="checkout__form section" data-pay-balance data-endpoint="{{ $checkout->url() }}/pay-balance">
    <h2>Payment needed</h2>
    <p>{{ $balance['amount'] }} is due for this stay. Pay it on our store.</p>
    <button type="button" class="button button--wide" data-start-balance>Pay {{ $balance['amount'] }}</button>
    <p class="notice" data-balance-message role="alert" hidden></p>
</section>
@else
<section class="checkout__form section" data-pay-balance data-endpoint="{{ $checkout->url() }}/pay-balance">
    <h2>Payment needed</h2>
    <p>{{ $balance['amount'] }} is due for this stay and could not be taken from your saved card.</p>
    @if ($balance['why'])
        <p class="notice" role="status">{{ $balance['why'] }}</p>
    @endif
    <button type="button" class="button button--wide" data-start-balance>Pay {{ $balance['amount'] }}</button>
    <p class="notice" data-balance-message role="alert" hidden></p>
    @include('sites.default.partials.payment', ['payLabel' => 'Pay '.$balance['amount'], 'clientSecret' => null, 'note' => null])
</section>
@endif
