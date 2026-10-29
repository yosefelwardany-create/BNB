<?php

declare(strict_types=1);

use App\Http\Controllers\Sites\SiteAssetController;
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
        Route::get('/', [SitePageController::class, 'home'])->name('home');
        Route::get('search', [SitePageController::class, 'search'])->name('search');
        Route::get('stay/{listing}', [SitePageController::class, 'property'])->name('property');

        Route::get('photos/{photo}', [SiteAssetController::class, 'photo'])->whereUlid('photo')->name('photo');
        Route::get('media/{kind}', [SiteAssetController::class, 'media'])->name('media');
        Route::get('sitemap.xml', [SiteAssetController::class, 'sitemap'])->name('sitemap');
        Route::get('robots.txt', [SiteAssetController::class, 'robots'])->name('robots');
    });
