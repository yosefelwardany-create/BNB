{{-- Contact: the site's own phone, email and address, from Site details. --}}
@php
    $email = $site->setting('contact_email');
    $phone = $site->setting('contact_phone');
    $address = $site->setting('address_line');
@endphp
<div class="wrap section contact">
    @include('sites.blocks.partials.heading', ['text' => $f['title']])
    @if ($f['intro'] !== '')
        <p class="lead">{{ $f['intro'] }}</p>
    @endif
    <ul class="contact__list">
        @if ($phone)
            <li><span class="muted small">Phone</span><a href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $phone) }}">{{ $phone }}</a></li>
        @endif
        @if ($email)
            <li><span class="muted small">Email</span><a href="mailto:{{ $email }}">{{ $email }}</a></li>
        @endif
        @if ($address)
            <li><span class="muted small">Address</span><span>{{ $address }}</span></li>
        @endif
    </ul>
    @if (! $phone && ! $email && ! $address && $preview)
        <p class="muted">Add a phone, email or address in Site details.</p>
    @endif
</div>
