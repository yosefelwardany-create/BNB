@extends('sites.default.layout')

{{-- The search bar, on its own, for the client's store. Its dates go to the
     homes grids on the same store page (public/sites/embed/frame.js). --}}

@section('title', 'Search stays · '.$site->name)

@section('content')
    <div class="embed-piece">
        @include('sites.default.partials.search-form')
    </div>
@endsection
