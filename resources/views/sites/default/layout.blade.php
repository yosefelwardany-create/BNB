@php
    /** @var \App\Domain\Websites\Models\Website $site */
    $colour = fn (string $key, string $fallback): string => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $site->setting($key)) === 1
        ? (string) $site->setting($key)
        : $fallback;
    $logo = \App\Domain\Websites\Support\WebsiteMedia::url($site, 'logo');
    // Sections arrive already escaped — @section('title', …) escapes its
    // value — so they are printed raw below, and only the fallbacks are
    // escaped here. Escaping both turned "Fisherman's" into "Fisherman&#039;s"
    // in every browser tab and search result.
    $pageTitle = trim($__env->yieldContent('title')) ?: e($site->name);
    $description = trim($__env->yieldContent('description'))
        ?: e(\Illuminate\Support\Str::limit(strip_tags((string) ($site->setting('tagline') ?: $site->name)), 160));
    $ogImage = trim($__env->yieldContent('og_image')) ?: e((string) \App\Domain\Websites\Support\WebsiteMedia::url($site, 'hero'));
    $canonical = trim($__env->yieldContent('canonical')) ?: e(url()->current());
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{!! $pageTitle !!}</title>
    <meta name="description" content="{!! $description !!}">
    <link rel="canonical" href="{!! $canonical !!}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $site->name }}">
    <meta property="og:title" content="{!! $pageTitle !!}">
    <meta property="og:description" content="{!! $description !!}">
    <meta property="og:url" content="{!! $canonical !!}">
    @if ($ogImage !== '')
        <meta property="og:image" content="{!! $ogImage !!}">
    @endif
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600&display=swap">
    <link rel="stylesheet" href="{{ asset('sites/default/site.css') }}?v={{ @filemtime(public_path('sites/default/site.css')) }}">
    <style>:root{--brand:{{ $colour('primary_colour', '#1F4E5F') }};--accent:{{ $colour('accent_colour', '#E07A5F') }}}</style>
</head>
<body>
    <header class="site-header">
        <div class="wrap site-header__inner">
            <a class="site-brand" href="{{ $site->url() }}">
                @if ($logo)
                    <img src="{{ $logo }}" alt="{{ $site->name }}" class="site-brand__logo">
                @else
                    <span class="site-brand__name">{{ $site->name }}</span>
                @endif
            </a>
            <nav class="site-nav" aria-label="Main">
                <a href="{{ $site->url() }}">Home</a>
                <a href="{{ $site->url('search') }}">All stays</a>
                @if ($site->setting('contact_phone'))
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $site->setting('contact_phone')) }}" class="site-nav__phone">{{ $site->setting('contact_phone') }}</a>
                @endif
            </nav>
        </div>
    </header>

    <main>
        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="wrap site-footer__inner">
            <div>
                <div class="site-footer__name">{{ $site->name }}</div>
                @if ($site->setting('tagline'))
                    <p class="muted">{{ $site->setting('tagline') }}</p>
                @endif
            </div>
            <div class="site-footer__contact">
                @if ($site->setting('contact_email'))
                    <a href="mailto:{{ $site->setting('contact_email') }}">{{ $site->setting('contact_email') }}</a>
                @endif
                @if ($site->setting('contact_phone'))
                    <span>{{ $site->setting('contact_phone') }}</span>
                @endif
                @if ($site->setting('address_line'))
                    <span>{{ $site->setting('address_line') }}</span>
                @endif
                <span class="site-footer__social">
                    @if ($site->setting('instagram_url'))
                        <a href="{{ $site->setting('instagram_url') }}" rel="noopener">Instagram</a>
                    @endif
                    @if ($site->setting('facebook_url'))
                        <a href="{{ $site->setting('facebook_url') }}" rel="noopener">Facebook</a>
                    @endif
                </span>
            </div>
        </div>
    </footer>
</body>
</html>
