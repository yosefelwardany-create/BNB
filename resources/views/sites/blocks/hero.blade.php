{{-- Big photo + search: the site's heading over its photo, with the search bar. --}}
<div class="hero hero--{{ $f['text_tone'] }} @if ($image) hero--photo @endif" @if ($image) style="background-image:url('{{ $image }}')" @endif>
    <div class="wrap hero__inner">
        <h1>{{ $f['heading'] !== '' ? $f['heading'] : $site->name }}</h1>
        @if ($f['tagline'] !== '')
            <p class="hero__tagline">{!! nl2br(e($f['tagline'])) !!}</p>
        @endif
        @if ($f['show_search'])
            @include('sites.default.partials.search-form', ['criteria' => $criteria])
        @endif
    </div>
</div>
