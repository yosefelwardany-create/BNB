@extends('sites.default.layout')

@php
    $hero = \App\Domain\Websites\Support\WebsiteMedia::url($site, 'hero');
@endphp

@section('content')
    <section class="hero @if ($hero) hero--photo @endif" @if ($hero) style="background-image:url('{{ $hero }}')" @endif>
        <div class="wrap hero__inner">
            <h1>{{ $site->setting('heading') ?: $site->name }}</h1>
            @if ($site->setting('tagline'))
                <p class="hero__tagline">{{ $site->setting('tagline') }}</p>
            @endif
            @include('sites.default.partials.search-form')
        </div>
    </section>

    <section class="wrap section">
        <div class="section__head">
            <h2>Our stays</h2>
            <a href="{{ $site->url('search') }}">See all</a>
        </div>
        @if ($featured->isEmpty())
            <p class="muted">New homes are on their way. Please check back soon.</p>
        @else
            <div class="grid">
                @foreach ($featured as $listing)
                    @include('sites.default.partials.card', ['listing' => $listing])
                @endforeach
            </div>
        @endif
    </section>
@endsection
