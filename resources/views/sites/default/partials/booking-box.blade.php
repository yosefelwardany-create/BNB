@php
    /**
     * A home's booking box: dates, the calendar, the price and Book. On the
     * home's page, and on its own as a piece on the client's store.
     *
     * @var \App\Domain\Listings\Models\Listing $listing
     * @var \App\Domain\Websites\Services\WebsiteCatalogue $catalogue
     * @var \App\Domain\Websites\DataObjects\SearchCriteria $criteria
     */
    use App\Domain\Websites\Support\SiteFormat;
    use App\Domain\Websites\Support\SiteLinks;

    $online = \App\Domain\Websites\Support\SitePayments::onlineFor($site);
    $slug = $catalogue->slugFor($site, $listing);
@endphp
<aside class="booking-box" aria-label="Book this stay"
    @if ($online)
        data-booking-box
        data-calendar-url="{{ $site->url('api/listings/'.$slug.'/calendar') }}"
        data-quote-url="{{ $site->url('api/listings/'.$slug.'/quote') }}"
        data-checkout-url="{{ SiteLinks::to($site, 'checkout', $embed ?? false) }}"
    @endif
>
    <div class="booking-box__price" data-price-head
        @if ($listing->baseRate()->isPositive()) data-from-amount="{{ SiteFormat::money($listing->baseRate(), true) }}" @endif>
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
        <label>Guests <input type="number" name="guests" min="1" max="{{ $listing->maxOccupancy() }}" value="{{ $criteria->guests }}"></label>
        <button type="submit" class="button">Check price</button>
    </form>

    @if ($online)
        <div class="cal" data-calendar hidden></div>
        <p class="notice" data-booking-notice role="status" hidden></p>
        <div data-quote aria-live="polite"></div>
    @endif

    @if ($criteria->problem())
        <p class="notice" role="alert" data-server-notice>{{ $criteria->problem() }}</p>
    @elseif ($reasons !== [])
        <div class="notice" role="status" data-server-notice>
            @foreach ($reasons as $reason)
                <p>{{ $reason }}</p>
            @endforeach
        </div>
    @endif

    @if ($online)
        @if ($site->setting('contact_email'))
            <p class="muted small">Questions before you book? <a href="mailto:{{ $site->setting('contact_email') }}?subject={{ rawurlencode('Question: '.$listing->displayTitle()) }}">Email us</a></p>
        @endif
    @else
        @include('sites.default.partials.contact-to-book')
    @endif
</aside>
