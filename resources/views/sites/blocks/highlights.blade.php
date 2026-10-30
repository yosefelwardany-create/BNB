{{-- Highlights: a few short points, each with an icon from the site's own set. --}}
<div class="wrap section">
    @include('sites.blocks.partials.heading', ['text' => $f['title'], 'field' => 'title'])
    <ul class="highlights">
        @foreach ($f['items'] as $item)
            @if (is_string($item['text'] ?? null) && $item['text'] !== '')
                <li class="highlight">
                    <span class="highlight__icon icon-{{ preg_replace('/[^a-z]/', '', (string) ($item['icon'] ?? 'star')) }}" aria-hidden="true"></span>
                    <span>{{ $item['text'] }}</span>
                </li>
            @endif
        @endforeach
    </ul>
</div>
