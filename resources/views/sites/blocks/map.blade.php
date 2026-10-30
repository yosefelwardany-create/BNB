{{-- Map: an area map around the site's homes. Approximate, as on property pages. --}}
<div class="wrap section">
    @include('sites.blocks.partials.heading', ['text' => $f['title'], 'field' => 'title'])
    @if ($f['body'] !== '' || $preview)
        <p class="lead"{{ $editable('body', $f['body'], 'How to get here', true) }}>{{ \App\Domain\Websites\Support\SiteFormat::lines($f['body']) }}</p>
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
