{{-- Call to action: one sentence and a button, on a strip of colour. --}}
<div class="wrap section cta">
    <p class="cta__text"{{ $editable('text', $f['text'], 'Add a sentence') }}>{{ $f['text'] }}</p>
    @include('sites.blocks.partials.button', ['link' => $f['button'], 'class' => 'cta__button', 'field' => 'button.label'])
</div>
