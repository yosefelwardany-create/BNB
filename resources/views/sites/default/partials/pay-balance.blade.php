{{-- What the saved card could not pay, for the guest to pay with a card of their choice. --}}
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
