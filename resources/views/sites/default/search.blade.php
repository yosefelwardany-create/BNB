@extends('sites.default.layout')

@section('title', 'All stays · '.$site->name)

@php
    /** @var \App\Domain\Websites\DataObjects\SearchCriteria $criteria */
    $amenities = collect($catalogue->listings($site))
        ->flatMap(fn ($listing) => $listing->effectiveAmenities())
        ->unique(fn ($amenity) => $amenity->key)
        ->sortBy('name')
        ->values();
@endphp

@section('content')
    <section class="wrap section">
        <h1 class="page-title">All stays</h1>
        @include('sites.default.partials.search-form', ['extra' => [
            'bedrooms' => $criteria->bedrooms,
            'bathrooms' => $criteria->bathrooms,
            'min_price' => $criteria->minPrice,
            'max_price' => $criteria->maxPrice,
            'amenities' => $criteria->amenities,
        ]])

        @if ($criteria->problem())
            <p class="notice" role="alert">{{ $criteria->problem() }}</p>
        @endif

        <div class="search-layout">
            <form class="filters" method="get" action="{{ $site->url('search') }}" aria-label="Filters">
                @foreach ($criteria->query() as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <h2 class="filters__title">Filters</h2>
                <label>Bedrooms (at least)
                    <input type="number" name="bedrooms" min="0" value="{{ $criteria->bedrooms }}">
                </label>
                <label>Bathrooms (at least)
                    <input type="number" name="bathrooms" min="0" value="{{ $criteria->bathrooms }}">
                </label>
                <div class="filters__pair">
                    <label>Min a night
                        <input type="number" name="min_price" min="0" value="{{ $criteria->minPrice }}">
                    </label>
                    <label>Max a night
                        <input type="number" name="max_price" min="0" value="{{ $criteria->maxPrice }}">
                    </label>
                </div>
                @if ($amenities->isNotEmpty())
                    <fieldset>
                        <legend>Amenities</legend>
                        @foreach ($amenities as $amenity)
                            <label class="check">
                                <input type="checkbox" name="amenities[]" value="{{ $amenity->key }}" @checked(in_array($amenity->key, $criteria->amenities, true))>
                                {{ $amenity->name }}
                            </label>
                        @endforeach
                    </fieldset>
                @endif
                <button type="submit" class="button button--quiet">Apply filters</button>
            </form>

            <div>
                <p class="muted">
                    {{ count($results) }} {{ count($results) === 1 ? 'stay' : 'stays' }}
                    @if ($criteria->hasDates())
                        free from {{ $criteria->checkIn->format('j M') }} to {{ $criteria->checkOut->format('j M Y') }}
                    @endif
                </p>
                @if ($results === [])
                    <p class="empty">Nothing matches that search. Try other dates or fewer filters.</p>
                @else
                    <div class="grid grid--two">
                        @foreach ($results as $result)
                            @include('sites.default.partials.card', [
                                'listing' => $result['listing'],
                                'total' => $result['total'],
                                'query' => $criteria->query(),
                            ])
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </section>
@endsection
