{{-- Call to action: one sentence and a button, on a strip of colour. --}}
<div class="wrap section cta">
    <p class="cta__text">{{ $f['text'] }}</p>
    @include('sites.blocks.partials.button', ['link' => $f['button'], 'class' => 'cta__button'])
</div>
