@php
    /** @var \App\Domain\Listings\Models\Listing $listing */
    /** @var \App\Domain\Websites\Services\WebsiteCatalogue $catalogue */
    $photo = $catalogue->photos($site, $listing)[0] ?? null;
    $embed = $embed ?? false;
    $search = isset($query) && $query !== [] ? http_build_query($query) : '';
    // In a frame on the client's store, a home with a page of its own on the
    // store goes there; any other opens in the pop-up.
    $storeUrl = $embed !== false ? $listing->getRelation('websiteRow')?->store_url : null;
    $href = $storeUrl !== null
        ? $storeUrl.($search === '' ? '' : (str_contains($storeUrl, '?') ? '&' : '?').$search)
        : \App\Domain\Websites\Support\SiteLinks::to($site, 'stay/'.$catalogue->slugFor($site, $listing), $embed).($search === '' ? '' : '?'.$search);
    $city = $listing->property?->city;
@endphp
<article class="card">
    <a href="{{ $href }}" class="card__link"@if ($storeUrl !== null) data-habitat-go @elseif ($embed !== false) data-habitat-open @endif>
        <div class="card__photo">
            @if ($photo)
                <img src="{{ $photo['url'] }}" alt="{{ $photo['photo']->caption ?: $listing->displayTitle() }}" loading="lazy">
            @else
                <div class="card__photo--empty" aria-hidden="true"></div>
            @endif
        </div>
        <div class="card__body">
            <h3 class="card__title">{{ $listing->displayTitle() }}</h3>
            @if ($city)
                <div class="muted">{{ $city }}</div>
            @endif
            <div class="card__facts">
                {{ $listing->maxOccupancy() }} guests
                @if ($listing->resolved('bedrooms'))
                    · {{ $listing->resolved('bedrooms') }} {{ (int) $listing->resolved('bedrooms') === 1 ? 'bedroom' : 'bedrooms' }}
                @endif
                @if ($listing->resolved('bathrooms'))
                    · {{ rtrim(rtrim((string) $listing->resolved('bathrooms'), '0'), '.') }} {{ (float) $listing->resolved('bathrooms') === 1.0 ? 'bathroom' : 'bathrooms' }}
                @endif
            </div>
            <div class="card__price">
                @if (($total ?? null) !== null)
                    <strong>{{ \App\Domain\Websites\Support\SiteFormat::money($total) }}</strong> total
                @elseif ($listing->baseRate()->isPositive())
                    From <strong>{{ \App\Domain\Websites\Support\SiteFormat::money($listing->baseRate(), true) }}</strong> a night
                @endif
            </div>
        </div>
    </a>
</article>
