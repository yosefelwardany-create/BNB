<?php

declare(strict_types=1);

use App\Http\Controllers\Sites\SiteAssetController;
use App\Http\Controllers\Sites\SiteBookingController;
use App\Http\Controllers\Sites\SiteCheckoutController;
use App\Http\Controllers\Sites\SitePageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Booking websites
|--------------------------------------------------------------------------
|
| Mounted at /sites. Public pages: no session, no cookie, no signed-in user.
| A site is found by its address and answers 404 unless it is live; every
| listing, photo and price is read for that site alone.
|
*/

Route::prefix('{site}')
    ->where(['site' => '[a-z0-9]+(?:-[a-z0-9]+)*'])
    ->group(function (): void {
        Route::middleware('throttle:sites')->group(function (): void {
            Route::get('/', [SitePageController::class, 'home'])->name('home');
            Route::get('search', [SitePageController::class, 'search'])->name('search');
            Route::get('stay/{listing}', [SitePageController::class, 'property'])->name('property');
            Route::get('checkout/{quote}', [SitePageController::class, 'checkout'])->whereUlid('quote')->name('checkout');
            Route::get('booking/{token}', [SitePageController::class, 'booking'])->where('token', '[A-Za-z0-9]{48}')->name('booking');
            Route::get('sitemap.xml', [SiteAssetController::class, 'sitemap'])->name('sitemap');
            Route::get('robots.txt', [SiteAssetController::class, 'robots'])->name('robots');

            // The booking box's questions, answered as JSON.
            Route::get('api/listings/{listing}/calendar', [SiteBookingController::class, 'calendar'])->name('calendar');
            Route::post('api/listings/{listing}/quote', [SiteBookingController::class, 'quote'])->name('quote');
        });

        // Each hold closes real nights, so asking for one is counted tightly.
        Route::post('api/checkouts', [SiteCheckoutController::class, 'store'])
            ->middleware('throttle:site-checkouts')
            ->name('checkouts.store');

        Route::post('booking/{token}/pay-balance', [SiteCheckoutController::class, 'payBalance'])
            ->where('token', '[A-Za-z0-9]{48}')
            ->middleware('throttle:site-checkouts')
            ->name('booking.pay-balance');

        // Counted apart: a page asks for a dozen of these at once.
        Route::middleware('throttle:site-assets')->group(function (): void {
            Route::get('photos/{photo}', [SiteAssetController::class, 'photo'])->whereUlid('photo')->name('photo');
            Route::get('media/{kind}', [SiteAssetController::class, 'media'])->name('media');
            Route::get('images/{image}', [SiteAssetController::class, 'image'])->whereUlid('image')->name('image');
        });

        // The editor's preview of the draft. Signed by the editor's server
        // and short-lived; works before the site is live.
        Route::get('preview/{page?}', [SitePageController::class, 'preview'])
            ->where('page', 'pg_[a-z0-9]{12}')
            ->middleware(['signed', 'throttle:sites'])
            ->name('preview');

        // The site's own pages, by their address. Last, so every address
        // above — search, stay, sitemap.xml — always means what it says;
        // a page may not take one of them either.
        Route::get('{page}', [SitePageController::class, 'page'])
            ->where('page', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->middleware('throttle:sites')
            ->name('page');
    });
