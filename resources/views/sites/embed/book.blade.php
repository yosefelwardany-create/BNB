@extends('sites.default.layout')

{{-- One home's booking box, for the client's own page about that home.
     Book continues in the pop-up (public/sites/embed/frame.js). --}}

@section('title', $listing->displayTitle().' · '.$site->name)

@section('content')
    <div class="embed-piece">
        @include('sites.default.partials.booking-box')
    </div>
@endsection

@if (\App\Domain\Websites\Support\SitePayments::online())
    @push('scripts')
        <script src="{{ asset('sites/default/booking.js') }}?v={{ @filemtime(public_path('sites/default/booking.js')) }}" defer></script>
    @endpush
@endif
