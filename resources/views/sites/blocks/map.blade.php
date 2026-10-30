{{-- Map: an area map around the site's homes. Approximate, as on property pages. --}}
<div class="wrap section">
    @include('sites.blocks.partials.heading', ['text' => $f['title']])
    @if ($f['body'] !== '')
        <p class="lead">{!! nl2br(e($f['body'])) !!}</p>
    @endif
    @if ($place)
        <iframe
            class="map"
            title="Map of the area"
            loading="lazy"
            src="https://www.openstreetmap.org/export/embed.html?bbox={{ $place['longitude'] - 0.06 }}%2C{{ $place['latitude'] - 0.035 }}%2C{{ $place['longitude'] + 0.06 }}%2C{{ $place['latitude'] + 0.035 }}&amp;layer=mapnik"
        ></iframe>
    @endif
</div>
