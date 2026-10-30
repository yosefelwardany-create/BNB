{{-- Photo gallery: a grid, or a slider that scrolls sideways. --}}
@if ($photos !== [] || $preview)
    <div class="wrap section">
        @include('sites.blocks.partials.heading', ['text' => $f['title']])
        @if ($photos === [])
            <p class="muted">Choose photos for this gallery.</p>
        @else
            <div class="{{ $f['layout'] === 'slider' ? 'slider' : 'photo-grid' }}">
                @foreach ($photos as $url)
                    <img src="{{ $url }}" alt="{{ $f['title'] !== '' ? $f['title'] : $site->name }}" loading="lazy">
                @endforeach
            </div>
        @endif
    </div>
@endif
