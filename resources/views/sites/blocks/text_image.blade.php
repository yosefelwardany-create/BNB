{{-- Text + photo, with an optional button; the photo on either side. --}}
@php
    $body = \App\Domain\Websites\Content\RichText::clean((string) $f['body']);
    $empty = $image === null && $f['heading'] === '' && $body === '';
@endphp
@if (! $empty || $preview)
    <div class="wrap section split split--photo-{{ $f['image_side'] === 'left' ? 'left' : 'right' }} @if (! $image && ! $preview) split--text-only @endif">
        <div class="split__text prose">
            @include('sites.blocks.partials.heading', ['text' => $f['heading'], 'field' => 'heading', 'placeholder' => 'Add a heading'])
            {!! $body !!}
            @include('sites.blocks.partials.button', ['link' => $f['button'], 'field' => 'button.label'])
        </div>
        @if ($image)
            <div class="split__media">
                <img src="{{ $image }}" alt="{{ $f['heading'] }}" loading="lazy">
            </div>
        @elseif ($preview)
            <div class="split__media">
                <div class="split__placeholder" aria-hidden="true"></div>
            </div>
        @endif
    </div>
@endif
