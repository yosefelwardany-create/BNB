{{-- Spacer: a gap between blocks, with or without a line. --}}
<div class="wrap spacer spacer--{{ in_array($f['size'], ['small', 'medium', 'large'], true) ? $f['size'] : 'medium' }}">
    @if ($f['line'])
        <hr>
    @endif
</div>
