@php
    /** @var \App\Domain\Websites\Content\BlockRenderer $blocks */
    $href = $blocks->href($site, $link ?? null, $content);
@endphp
@if ($href !== null && is_string($link['label'] ?? null) && $link['label'] !== '')
    <a class="button {{ $class ?? '' }}" href="{{ $href }}"{{ isset($field) ? $editable($field, $link['label']) : '' }}>{{ $link['label'] }}</a>
@endif
