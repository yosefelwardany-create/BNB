{{-- FAQ: questions that open on tap, with no script. --}}
<div class="wrap section faq">
    @include('sites.blocks.partials.heading', ['text' => $f['title']])
    @foreach ($f['items'] as $item)
        @if (is_string($item['question'] ?? null) && $item['question'] !== '')
            <details class="faq__item">
                <summary>{{ $item['question'] }}</summary>
                <p>{!! nl2br(e($item['answer'] ?? '')) !!}</p>
            </details>
        @endif
    @endforeach
</div>
