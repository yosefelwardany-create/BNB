@extends('sites.default.layout')

@php
    /** @var \App\Domain\Listings\Models\Listing $listing */
    /** @var \App\Domain\Pricing\Models\Quote $quote */
    /** @var array<string, mixed>|null $priced null when the price can no longer be had */
    $property = $listing->property;
    $policy = $listing->cancellationPolicy ?? $property?->cancellationPolicy;
    $photo = $catalogue->photos($site, $listing)[0] ?? null;
    $instant = ($priced['mode'] ?? null) === 'instant';
    $back = $site->url('stay/'.$catalogue->slugFor($site, $listing)).'?'.http_build_query(array_filter([
        'checkin' => $quote->check_in_date->toDateString(),
        'checkout' => $quote->check_out_date->toDateString(),
        'guests' => ($quote->adults + $quote->children) > 1 ? $quote->adults + $quote->children : null,
    ]));
    $paragraphs = fn (?string $text): string => $text === null || trim($text) === ''
        ? ''
        : collect(preg_split('/\R{2,}/', trim($text)))->map(fn (string $part): string => '<p>'.nl2br(e($part)).'</p>')->implode('');
    $field = fn (string $name): string => '<span class="field-error" data-error-for="'.$name.'" hidden></span>';
@endphp

@section('title', ($priced === null ? 'Choose your dates again' : ($instant ? 'Confirm and pay' : 'Request to book')).' · '.$listing->displayTitle())

@push('head')
    <meta name="robots" content="noindex">
@endpush

@section('content')
    <div class="wrap section">
        <p><a href="{{ $back }}">← {{ $listing->displayTitle() }}</a></p>

        @if ($priced === null)
            <div class="booking-status">
                <h1>That price has expired</h1>
                <p>A price is kept for an hour, and only for one booking. Check the dates again for a fresh one.</p>
                <a class="button" href="{{ $back }}">Check the dates again</a>
            </div>
        @else
            <h1>{{ $instant ? 'Confirm and pay' : 'Request to book' }}</h1>

            <div class="checkout">
                <aside class="checkout__stay" aria-label="Your stay">
                    @if ($photo)
                        <img src="{{ $photo['url'] }}" alt="{{ $photo['photo']->caption ?: $listing->displayTitle() }}">
                    @endif
                    <div>
                        <h2>{{ $listing->displayTitle() }}</h2>
                        @if ($property?->city)
                            <div class="muted">{{ $property->city }}</div>
                        @endif
                    </div>
                    @include('sites.default.partials.stay-summary')
                </aside>

                <div>
                    <div class="policies">
                        @if ($policy)
                            <h3>Cancellation</h3>
                            <p>{{ $policy->name }}@if ($policy->description) — {{ $policy->description }}@endif</p>
                        @endif
                        <h3>Arrival and departure</h3>
                        <p>Check-in from {{ $listing->resolved('check_in_time') ? substr((string) $listing->resolved('check_in_time'), 0, 5) : '15:00' }}, check-out by {{ $listing->resolved('check_out_time') ? substr((string) $listing->resolved('check_out_time'), 0, 5) : '11:00' }}.</p>
                        @if ($listing->resolved('house_rules'))
                            <h3>House rules</h3>
                            {!! $paragraphs($listing->resolved('house_rules')) !!}
                        @endif
                    </div>

                    @if ($online)
                        <form class="checkout__form section" method="post" action="{{ $site->url('api/checkouts') }}"
                            data-checkout-form data-endpoint="{{ $site->url('api/checkouts') }}" novalidate>
                            <input type="hidden" name="quote_id" value="{{ $priced['quote_id'] }}">

                            <h2>Your details</h2>
                            <div class="pair">
                                <label class="field">First name <input name="first_name" autocomplete="given-name" required maxlength="100">{!! $field('first_name') !!}</label>
                                <label class="field">Last name <input name="last_name" autocomplete="family-name" required maxlength="100">{!! $field('last_name') !!}</label>
                            </div>
                            <div class="pair">
                                <label class="field">Email <input type="email" name="email" autocomplete="email" required maxlength="190">{!! $field('email') !!}</label>
                                <label class="field">Phone <input type="tel" name="phone" autocomplete="tel" required maxlength="40">{!! $field('phone') !!}</label>
                            </div>
                            <label class="field">Anything your host should know? <span class="muted">(optional)</span>
                                <textarea name="message" maxlength="2000"></textarea>{!! $field('message') !!}
                            </label>

                            <label class="consent">
                                <input type="checkbox" name="accept_policies" value="1" required>
                                <span>I accept the house rules and the cancellation policy.</span>
                            </label>
                            {!! $field('accept_policies') !!}

                            <p class="notice" data-form-message role="alert" hidden></p>

                            <button type="submit" class="button button--wide">{{ $instant ? 'Continue to payment' : 'Send request' }}</button>

                            @if ($instant)
                                <p class="muted small">We hold these dates for {{ $site->hold_minutes }} minutes while you pay.</p>
                            @else
                                <p class="muted small">Your host answers within {{ $site->request_hours }} hours. Your card is checked now and charged only if they accept.</p>
                            @endif
                        </form>
                    @else
                        <div class="section">
                            @include('sites.default.partials.contact-to-book')
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
@endsection

@if ($online && $priced !== null)
    @push('scripts')
        <script src="{{ asset('sites/default/booking.js') }}?v={{ @filemtime(public_path('sites/default/booking.js')) }}" defer></script>
    @endpush
@endif
