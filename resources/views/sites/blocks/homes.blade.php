{{-- Our homes: cards for the featured, all, or chosen homes of the site. --}}
<div class="wrap section">
    <div class="section__head">
        @include('sites.blocks.partials.heading', ['text' => $f['title']])
        <a href="{{ $site->url('search') }}">See all</a>
    </div>
    @if ($homes->isEmpty())
        <p class="muted">New homes are on their way. Please check back soon.</p>
    @else
        <div class="{{ $f['layout'] === 'carousel' ? 'carousel' : 'grid' }}">
            @foreach ($homes as $listing)
                @include('sites.default.partials.card', ['listing' => $listing])
            @endforeach
        </div>
    @endif
</div>
