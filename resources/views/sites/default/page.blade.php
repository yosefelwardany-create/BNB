@extends('sites.default.layout')

@php
    /** @var array<string, mixed> $page */
    /** @var list<string> $html  each block, already rendered */
    $isHome = ($page['kind'] ?? null) === 'home';
@endphp

@if (! $isHome)
    @section('title', $page['title'].' · '.$site->name)
    @section('canonical', $site->url($page['slug']))
@endif

@section('content')
    @foreach ($html as $block)
        {!! $block !!}
    @endforeach
    @if (($page['blocks'] ?? []) === [] && ($preview ?? false))
        <div class="wrap section"><p class="muted">This page has no blocks yet. Add one from the left.</p></div>
    @endif
@endsection
