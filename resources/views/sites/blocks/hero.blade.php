{{-- Big photo + search: the site's heading over its photo, with the search bar. --}}
@php
    // Guests see the site's name when there is no heading; the editor sees it
    // as the empty heading's placeholder, so typing does not start from it.
    $heading = $f['heading'] !== '' || $preview ? $f['heading'] : $site->name;
@endphp
<div class="hero hero--{{ $f['text_tone'] }} @if ($image) hero--photo @endif" @if ($image) style="background-image:url('{{ $image }}')" @endif>
    <div class="wrap hero__inner">
        <h1{{ $editable('heading', $f['heading'], $site->name) }}>{{ $heading }}</h1>
        @if ($f['tagline'] !== '' || $preview)
            <p class="hero__tagline"{{ $editable('tagline', $f['tagline'], 'Add a tagline', true) }}>{!! nl2br(e($f['tagline'])) !!}</p>
        @endif
        @if ($f['show_search'])
            @include('sites.default.partials.search-form', ['criteria' => $criteria])
        @endif
    </div>
</div>
