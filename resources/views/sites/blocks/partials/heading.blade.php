{{-- A block's title. Given a field, the editor's preview lets it be typed in place, even empty. --}}
@if (is_string($text ?? null) && ($text !== '' || (($preview ?? false) && isset($field))))
    <h2 class="block__title"{{ isset($field) ? $editable($field, $text, $placeholder ?? 'Add a title') : '' }}>{{ $text }}</h2>
@endif
