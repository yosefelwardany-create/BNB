@extends('sites.default.layout')

{{-- The site's homes, for the client's store: as a grid placed on their page,
     or as the results of a search in the pop-up, under the search bar. --}}

@section('title', 'Homes · '.$site->name)

@section('content')
    <div class="embed-piece">
        @if ($embed === 'popup')
            @include('sites.default.partials.search-form')
        @endif

        @if ($criteria->problem())
            <p class="notice" role="alert">{{ $criteria->problem() }}</p>
        @endif

        @if ($results === [])
            <p class="empty">
                @if ($criteria->hasDates())
                    Nothing is free for those dates. Try other dates.
                @else
                    New homes are on their way. Please check back soon.
                @endif
            </p>
        @else
            <div class="grid">
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
@endsection
