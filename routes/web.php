<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| The product is an API plus a single-page admin application, so there is
| very little here. The SPA is served as static files from /app by the web
| server, and every data path goes through /api/v1.
|
| Nothing in this file uses a closure, because a route defined as a closure
| cannot be serialised and would make `route:cache` fail — which the
| production image runs on every boot.
|
*/

// The root of the site is the admin application.
Route::redirect('/', '/app/')->name('home');

// A convenience alias so links written as /admin still land somewhere sensible.
Route::redirect('/admin', '/app/');
