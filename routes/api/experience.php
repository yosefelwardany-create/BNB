<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Experience\DocumentController;
use App\Http\Controllers\Api\V1\Experience\ReviewController;
use App\Http\Controllers\Api\V1\Experience\SmartLockController;
use App\Http\Controllers\Api\V1\Experience\UpsellController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest experience
|--------------------------------------------------------------------------
|
| Reviews, extras, documents and door access. Four things that look unrelated
| and share one property: each is a record about a guest that outlives the
| stay, and each has a rule about what may be changed afterwards.
|
| There is no endpoint that edits a review's text, at any permission level. It
| is what the guest said, and the copy on the channel is the authority.
|
*/

Route::prefix('reviews')->name('reviews.')->group(function (): void {
    Route::get('/', [ReviewController::class, 'index'])
        ->middleware('permission:reviews.view,reservations.view')->name('index');

    Route::get('summary', [ReviewController::class, 'summary'])
        ->middleware('permission:reviews.view,reservations.view')->name('summary');

    // A review taken by hand — a direct guest who emailed one in.
    Route::post('/', [ReviewController::class, 'store'])
        ->middleware('permission:reviews.respond')->name('store');

    Route::get('{review}', [ReviewController::class, 'show'])
        ->middleware('permission:reviews.view,reservations.view')->name('show');

    // Replying is published under the business's name and cannot be taken
    // back, which is why it is narrower than reading.
    Route::post('{review}/response', [ReviewController::class, 'respond'])
        ->middleware('permission:reviews.respond')->name('respond');

    // Suppresses the review here. It stays public on the channel, which is
    // why this is not called delete.
    Route::post('{review}/hide', [ReviewController::class, 'hide'])
        ->middleware('permission:reviews.respond')->name('hide');

    Route::post('{review}/unhide', [ReviewController::class, 'unhide'])
        ->middleware('permission:reviews.respond')->name('unhide');
});

Route::prefix('upsells')->name('upsells.')->middleware('feature:upsells')->group(function (): void {
    Route::get('/', [UpsellController::class, 'index'])
        ->middleware('permission:upsells.manage,reservations.view')->name('index');

    Route::post('/', [UpsellController::class, 'store'])
        ->middleware('permission:upsells.manage')->name('store');

    // Orders sit above {product} so "orders" is never read as a product id.
    Route::get('orders', [UpsellController::class, 'orders'])
        ->middleware('permission:reservations.view,upsells.manage')->name('orders.index');

    Route::post('orders/{order}/approve', [UpsellController::class, 'approve'])
        ->middleware('permission:reservations.update,upsells.manage')->name('orders.approve');

    Route::post('orders/{order}/decline', [UpsellController::class, 'decline'])
        ->middleware('permission:reservations.update,upsells.manage')->name('orders.decline');

    Route::post('orders/{order}/fulfil', [UpsellController::class, 'fulfil'])
        ->middleware('permission:reservations.update,upsells.manage')->name('orders.fulfil');

    Route::post('orders/{order}/cancel', [UpsellController::class, 'cancelOrder'])
        ->middleware('permission:reservations.update,upsells.manage')->name('orders.cancel');

    Route::get('{product}', [UpsellController::class, 'show'])
        ->middleware('permission:upsells.manage,reservations.view')->name('show');

    Route::patch('{product}', [UpsellController::class, 'update'])
        ->middleware('permission:upsells.manage')->name('update');

    Route::delete('{product}', [UpsellController::class, 'destroy'])
        ->middleware('permission:upsells.manage')->name('destroy');
});

Route::prefix('reservations/{reservation}')->name('reservations.')->group(function (): void {
    // The menu for this stay, already filtered by what can still be delivered
    // in the time remaining.
    Route::get('upsells', [UpsellController::class, 'availableFor'])
        ->middleware('permission:reservations.view')->name('upsells.available');

    Route::post('upsells', [UpsellController::class, 'order'])
        ->middleware('permission:reservations.update,upsells.manage')->name('upsells.order');

    // Issues a code on every lock the stay needs. A guest given one of two is
    // a guest who cannot get through the front door.
    Route::post('access-codes', [SmartLockController::class, 'issueForReservation'])
        ->middleware('permission:locks.manage')->name('access-codes.issue');
});

Route::prefix('locks')->name('locks.')->middleware('feature:smart_locks')->group(function (): void {
    Route::get('/', [SmartLockController::class, 'index'])
        ->middleware('permission:locks.view,locks.manage')->name('index');

    Route::post('/', [SmartLockController::class, 'store'])
        ->middleware('permission:locks.manage')->name('store');

    // Codes sit above {lock} so "codes" is never read as a lock id.
    Route::get('codes', [SmartLockController::class, 'codes'])
        ->middleware('permission:locks.view,locks.manage')->name('codes.index');

    // The plain code, for somebody who may operate the lock and has asked. A
    // listing shows four digits, because a list of working codes is a set of
    // keys.
    Route::get('codes/{code}/reveal', [SmartLockController::class, 'reveal'])
        ->middleware('permission:locks.manage')->name('codes.reveal');

    Route::delete('codes/{code}', [SmartLockController::class, 'revoke'])
        ->middleware('permission:locks.manage')->name('codes.revoke');

    Route::get('{lock}', [SmartLockController::class, 'show'])
        ->middleware('permission:locks.view,locks.manage')->name('show');

    Route::patch('{lock}', [SmartLockController::class, 'update'])
        ->middleware('permission:locks.manage')->name('update');

    // Asks the door, rather than reporting our last guess about it.
    Route::get('{lock}/status', [SmartLockController::class, 'status'])
        ->middleware('permission:locks.view,locks.manage')->name('status');

    Route::post('{lock}/codes', [SmartLockController::class, 'issue'])
        ->middleware('permission:locks.manage')->name('codes.store');
});

/*
 * Documents. Every read is streamed through the application rather than served
 * from a storage URL, so the permission is checked on each one — a permanent
 * link to a passport scan is a permanent link to a passport scan, whatever the
 * folder is called.
 */
Route::prefix('documents')->name('documents.')->group(function (): void {
    Route::get('/', [DocumentController::class, 'index'])
        ->middleware('permission:documents.view,documents.manage')->name('index');

    Route::post('/', [DocumentController::class, 'store'])
        ->middleware('permission:documents.manage')->name('store');

    Route::get('{document}', [DocumentController::class, 'show'])
        ->middleware('permission:documents.view,documents.manage')->name('show');

    Route::get('{document}/download', [DocumentController::class, 'download'])
        ->middleware('permission:documents.view,documents.manage')->name('download');

    Route::patch('{document}', [DocumentController::class, 'update'])
        ->middleware('permission:documents.manage')->name('update');

    // Soft by default; `purge` removes the file too, which is what an expired
    // identity document and an erasure request both need.
    Route::delete('{document}', [DocumentController::class, 'destroy'])
        ->middleware('permission:documents.manage')->name('destroy');
});
