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
        {{-- Taken away by the editor's overlay as soon as a block arrives. --}}
        <div class="wrap section" data-empty-note><p class="muted">This page has no blocks yet. Add one from the left.</p></div>
    @endif
@endsection
