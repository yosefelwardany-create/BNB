@if (is_string($text ?? null) && $text !== '')
    <h2 class="block__title">{{ $text }}</h2>
@endif
