{{-- Text + photo, with an optional button; the photo on either side. --}}
<div class="wrap section split split--photo-{{ $f['image_side'] === 'left' ? 'left' : 'right' }}">
    <div class="split__text prose">
        @include('sites.blocks.partials.heading', ['text' => $f['heading']])
        {!! \App\Domain\Websites\Content\RichText::clean((string) $f['body']) !!}
        @include('sites.blocks.partials.button', ['link' => $f['button']])
    </div>
    <div class="split__media">
        @if ($image)
            <img src="{{ $image }}" alt="{{ $f['heading'] }}" loading="lazy">
        @else
            <div class="split__placeholder" aria-hidden="true"></div>
        @endif
    </div>
</div>
