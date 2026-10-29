@php
    /** @var \App\Domain\Listings\Models\Listing $listing */
    /** @var \App\Domain\Websites\Services\WebsiteCatalogue $catalogue */
    $photo = $listing->effectivePhotos()[0] ?? null;
    $href = $site->url('stay/'.$catalogue->slugFor($site, $listing)).(isset($query) && $query !== [] ? '?'.http_build_query($query) : '');
    $city = $listing->property?->city;
@endphp
<article class="card">
    <a href="{{ $href }}" class="card__link">
        <div class="card__photo">
            @if ($photo)
                <img src="{{ $catalogue->photoUrl($site, $photo) }}" alt="{{ $photo->caption ?: $listing->displayTitle() }}" loading="lazy">
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
