@extends('sites.default.layout')

@php
    /** @var \App\Domain\Websites\Models\WebsiteCheckout $checkout */
    /** @var \App\Domain\Listings\Models\Listing|null $listing */
    /** @var \App\Domain\Reservations\Models\Reservation|null $reservation */
    use App\Domain\Websites\Models\WebsiteCheckout;
    use App\Domain\Websites\Support\SiteFormat;
    use App\Support\Money\Money;

    $timezone = $listing?->property?->timezone ?: config('app.timezone');
    $until = $checkout->hold_expires_at->setTimezone($timezone);
    $request = $checkout->mode === WebsiteCheckout::MODE_REQUEST;
    [$headline, $sentence] = match (true) {
        $confirming => [$request ? 'Sending your request' : 'Confirming your payment', 'This takes a few seconds; the page will update by itself.'],
        default => match ($checkout->status) {
        WebsiteCheckout::STATUS_HOLDING => [
            $request ? 'Your request is nearly sent' : 'Your stay is nearly booked',
            sprintf('These dates are held for you until %s.', $until->format('H:i \o\n D j M')),
        ],
        WebsiteCheckout::STATUS_PAID => ['Payment received', 'We are confirming your booking now.'],
        WebsiteCheckout::STATUS_REQUESTED => ['Request sent', sprintf('Your host will answer by %s. You are charged only if they accept.', $until->format('H:i \o\n D j M'))],
        WebsiteCheckout::STATUS_CONFIRMED => ['Your booking is confirmed', 'We have emailed you the details.'],
        WebsiteCheckout::STATUS_DECLINED => ['Your host could not accept this request', 'Nothing has been charged.'],
        WebsiteCheckout::STATUS_REFUNDED => ['This booking was refunded', 'The refund is on its way to your card.'],
        WebsiteCheckout::STATUS_FAILED => ['The payment did not go through', 'Nothing has been booked.'],
        default => ['This hold has lapsed', 'The dates were released because payment did not arrive in time. You are welcome to check them again.'],
        },
    };
    $problem = $checkout->payment_error ?: ($failedAtStripe ? 'The payment did not go through. You can try again, or with another card.' : null);
@endphp

@section('title', $headline.' · '.$site->name)

@push('head')
    <meta name="robots" content="noindex">
    {{-- The address of this page is the guest's key to their booking. --}}
    <meta name="referrer" content="no-referrer">
    @if ($clientSecret)
        <script src="https://js.stripe.com/v3/"></script>
    @endif
@endpush

@section('content')
    <div class="wrap section booking-status">
        <h1>{{ $headline }}</h1>
        <p>{{ $sentence }}</p>

        @if ($confirming)
            <div data-refresh-while-confirming></div>
        @endif

        @if ($clientSecret)
            @if ($problem)
                <p class="notice" role="alert">{{ $problem }}</p>
            @endif
            @include('sites.default.partials.payment', [
                'payLabel' => $request
                    ? 'Send request'
                    : 'Pay '.SiteFormat::money(Money::of($checkout->due_now_amount, $checkout->currency)),
                'note' => null,
            ])
        @endif

        <div class="checkout__stay">
            @if ($listing)
                <h2>{{ $listing->displayTitle() }}</h2>
            @endif
            <dl class="quote">
                @if ($reservation)
                    <div class="quote__line"><dt>Check-in</dt><dd>{{ $reservation->check_in_date->format('D j M Y') }}</dd></div>
                    <div class="quote__line"><dt>Check-out</dt><dd>{{ $reservation->check_out_date->format('D j M Y') }}</dd></div>
                @endif
                <div class="quote__line"><dt>Guest</dt><dd>{{ trim(($checkout->guest['first_name'] ?? '').' '.($checkout->guest['last_name'] ?? '')) }}</dd></div>
                <div class="quote__line quote__total"><dt>Total</dt><dd>{{ SiteFormat::money(Money::of($checkout->total_amount, $checkout->currency)) }}</dd></div>
                <div class="quote__line"><dt>Due now</dt><dd>{{ SiteFormat::money(Money::of($checkout->due_now_amount, $checkout->currency)) }}</dd></div>
            </dl>
        </div>

        @if ($confirmingBalance)
            <p class="notice" role="status">Confirming your payment. This takes a few seconds; the page will update by itself.</p>
            <div data-refresh-while-confirming></div>
        @elseif ($balance)
            @include('sites.default.partials.pay-balance', ['balance' => ['amount' => $balance['display'], 'why' => $balance['why']]])
        @endif

        @if ($checkout->status === WebsiteCheckout::STATUS_EXPIRED && $listing)
            <p class="section"><a class="button" href="{{ $site->url('stay/'.$catalogue->slugFor($site, $listing)) }}">Check the dates again</a></p>
        @endif
    </div>
@endsection

@if ($balance && ! $confirmingBalance)
    @push('head')
        <script src="https://js.stripe.com/v3/"></script>
    @endpush
@endif

@if ($confirming || $clientSecret || $balance)
    @push('scripts')
        <script src="{{ asset('sites/default/booking.js') }}?v={{ @filemtime(public_path('sites/default/booking.js')) }}" defer></script>
    @endpush
@endif
