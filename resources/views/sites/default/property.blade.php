@extends('sites.default.layout')

@php
    /** @var \App\Domain\Listings\Models\Listing $listing */
    /** @var \App\Domain\Websites\Services\WebsiteCatalogue $catalogue */
    /** @var \App\Domain\Websites\DataObjects\SearchCriteria $criteria */
    use App\Domain\Websites\Support\SiteFormat;

    $property = $listing->property;
    $photos = $listing->effectivePhotos();
    $amenities = collect($listing->effectiveAmenities())->sortBy('name')->values();
    $place = SiteFormat::approximately($property?->latitude, $property?->longitude);
    $policy = $listing->cancellationPolicy ?? $property?->cancellationPolicy;
    $paragraphs = fn (?string $text): string => $text === null || trim($text) === ''
        ? ''
        : collect(preg_split('/\R{2,}/', trim($text)))->map(fn (string $part): string => '<p>'.nl2br(e($part)).'</p>')->implode('');
    $bathrooms = $listing->resolved('bathrooms');
@endphp

@section('title', $listing->displayTitle().' · '.$site->name)
@section('description', (string) ($listing->resolved('summary') ?: $listing->resolved('description')))
@section('canonical', $site->url('stay/'.$catalogue->slugFor($site, $listing)))
@if ($photos !== [])
    @section('og_image', $catalogue->photoUrl($site, $photos[0]))
@endif

@section('content')
    <article class="wrap section property">
        <header class="property__head">
            <h1>{{ $listing->displayTitle() }}</h1>
            <div class="muted">
                {{ collect([$property?->neighbourhood, $property?->city])->filter()->implode(', ') }}
            </div>
        </header>

        @if ($photos !== [])
            <div class="gallery">
                @foreach (array_slice($photos, 0, 5) as $index => $photo)
                    <img src="{{ $catalogue->photoUrl($site, $photo) }}" alt="{{ $photo->caption ?: $listing->displayTitle() }}" @if ($index > 0) loading="lazy" @endif class="gallery__item gallery__item--{{ $index }}">
                @endforeach
            </div>
            @if (count($photos) > 5)
                <details class="gallery__more">
                    <summary>All {{ count($photos) }} photos</summary>
                    <div class="gallery__all">
                        @foreach (array_slice($photos, 5) as $photo)
                            <img src="{{ $catalogue->photoUrl($site, $photo) }}" alt="{{ $photo->caption ?: $listing->displayTitle() }}" loading="lazy">
                        @endforeach
                    </div>
                </details>
            @endif
        @endif

        <div class="property__layout">
            <div class="property__main">
                <ul class="facts">
                    <li>{{ $listing->maxOccupancy() }} guests</li>
                    @if ($listing->resolved('bedrooms'))
                        <li>{{ $listing->resolved('bedrooms') }} {{ (int) $listing->resolved('bedrooms') === 1 ? 'bedroom' : 'bedrooms' }}</li>
                    @endif
                    @if ($listing->resolved('beds'))
                        <li>{{ $listing->resolved('beds') }} {{ (int) $listing->resolved('beds') === 1 ? 'bed' : 'beds' }}</li>
                    @endif
                    @if ($bathrooms)
                        <li>{{ rtrim(rtrim((string) $bathrooms, '0'), '.') }} {{ (float) $bathrooms === 1.0 ? 'bathroom' : 'bathrooms' }}</li>
                    @endif
                </ul>

                @if ($listing->resolved('summary'))
                    <p class="lead">{{ $listing->resolved('summary') }}</p>
                @endif
                {!! $paragraphs($listing->resolved('description')) !!}

                @if ($listing->resolved('space_description'))
                    <h2>The space</h2>
                    {!! $paragraphs($listing->resolved('space_description')) !!}
                @endif

                @if ($amenities->isNotEmpty())
                    <h2>What this place offers</h2>
                    <ul class="amenities">
                        @foreach ($amenities as $amenity)
                            <li>{{ $amenity->name }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($property && $property->rooms->isNotEmpty())
                    <h2>Where you'll sleep</h2>
                    <ul class="rooms">
                        @foreach ($property->rooms as $room)
                            <li>
                                <strong>{{ $room->name }}</strong>
                                <span class="muted">
                                    {{ collect($room->beds ?? [])->map(fn ($bed) => ((int) ($bed['count'] ?? 1)).' × '.str_replace('_', ' ', (string) ($bed['type'] ?? 'bed')))->implode(', ') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <h2>Good to know</h2>
                <dl class="good-to-know">
                    <dt>Check-in</dt>
                    <dd>From {{ $listing->resolved('check_in_time') ? substr((string) $listing->resolved('check_in_time'), 0, 5) : '15:00' }}</dd>
                    <dt>Check-out</dt>
                    <dd>By {{ $listing->resolved('check_out_time') ? substr((string) $listing->resolved('check_out_time'), 0, 5) : '11:00' }}</dd>
                    @if ($listing->minimumNights() > 1)
                        <dt>Minimum stay</dt>
                        <dd>{{ $listing->minimumNights() }} nights</dd>
                    @endif
                    @if ($policy)
                        <dt>Cancellation</dt>
                        <dd>{{ $policy->name }}@if ($policy->description) — {{ $policy->description }}@endif</dd>
                    @endif
                </dl>

                @if ($listing->resolved('house_rules'))
                    <h3>House rules</h3>
                    {!! $paragraphs($listing->resolved('house_rules')) !!}
                @endif

                @if ($listing->resolved('neighbourhood_description') || $place)
                    <h2>Where you'll be</h2>
                    {!! $paragraphs($listing->resolved('neighbourhood_description')) !!}
                    @if ($place)
                        <iframe class="map" title="Approximate location"
                            src="https://www.openstreetmap.org/export/embed.html?bbox={{ $place['longitude'] - 0.02 }}%2C{{ $place['latitude'] - 0.012 }}%2C{{ $place['longitude'] + 0.02 }}%2C{{ $place['latitude'] + 0.012 }}&amp;layer=mapnik"
                            loading="lazy"></iframe>
                        <p class="muted small">Approximate location ({{ number_format($place['latitude'], 2) }}, {{ number_format($place['longitude'], 2) }}). The exact address is sent once you have booked.</p>
                    @endif
                @endif

                @if ($reviews->isNotEmpty())
                    <h2>What guests say</h2>
                    <ul class="reviews">
                        @foreach ($reviews as $review)
                            <li class="review">
                                @if ($review->rating)
                                    <div class="review__rating" aria-label="{{ $review->rating }} out of {{ $review->rating_scale ?: 5 }}">
                                        {{ str_repeat('★', (int) round($review->rating * 5 / max(1, $review->rating_scale ?: 5))) }}
                                    </div>
                                @endif
                                @if ($review->title)
                                    <strong>{{ $review->title }}</strong>
                                @endif
                                <p>{{ $review->public_comment }}</p>
                                @if ($review->stay_date)
                                    <div class="muted small">Stayed {{ $review->stay_date->format('F Y') }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <aside class="booking-box" aria-label="Book this stay">
                <div class="booking-box__price">
                    @if ($total !== null)
                        <strong>{{ SiteFormat::money($total) }}</strong>
                        <span class="muted">for {{ $criteria->nights() }} {{ $criteria->nights() === 1 ? 'night' : 'nights' }}</span>
                    @elseif ($listing->baseRate()->isPositive())
                        From <strong>{{ SiteFormat::money($listing->baseRate(), true) }}</strong> <span class="muted">a night</span>
                    @endif
                </div>

                <form method="get" action="{{ url()->current() }}" class="booking-box__dates">
                    <label>Check in <input type="date" name="checkin" value="{{ $criteria->checkIn?->toDateString() }}" min="{{ now()->toDateString() }}"></label>
                    <label>Check out <input type="date" name="checkout" value="{{ $criteria->checkOut?->toDateString() }}" min="{{ now()->addDay()->toDateString() }}"></label>
                    <label>Guests <input type="number" name="guests" min="1" max="{{ $listing->maxOccupancy() }}" value="{{ min($criteria->guests, $listing->maxOccupancy()) }}"></label>
                    <button type="submit" class="button">Check price</button>
                </form>

                @if ($criteria->problem())
                    <p class="notice" role="alert">{{ $criteria->problem() }}</p>
                @elseif ($criteria->hasDates() && $total === null)
                    <p class="notice" role="status">Those dates are not available. Try others.</p>
                @endif

                <div class="booking-box__contact">
                    <p>To book, get in touch:</p>
                    @if ($site->setting('contact_email'))
                        <a class="button button--wide" href="mailto:{{ $site->setting('contact_email') }}?subject={{ rawurlencode('Booking: '.$listing->displayTitle()) }}">Email us</a>
                    @endif
                    @if ($site->setting('contact_phone'))
                        <a class="button button--quiet button--wide" href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $site->setting('contact_phone')) }}">{{ $site->setting('contact_phone') }}</a>
                    @endif
                </div>
            </aside>
        </div>
    </article>
@endsection
