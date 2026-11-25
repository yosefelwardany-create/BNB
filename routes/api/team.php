<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Team\TeamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Team
|--------------------------------------------------------------------------
|
| Who is in the account and what each of them sees. The permission here is
| the gate for the action; the rules on what may be given, and to whom, are
| the team service's.
|
*/

Route::prefix('team')->name('team.')->group(function (): void {
    Route::get('/', [TeamController::class, 'index'])
        ->middleware('permission:users.view')->name('index');
    Route::get('options', [TeamController::class, 'options'])
        ->middleware('permission:users.view')->name('options');

    Route::post('invitations', [TeamController::class, 'invite'])
        ->middleware('permission:users.invite')->name('invitations.store');
    Route::delete('invitations/{invitation}', [TeamController::class, 'withdraw'])
        ->middleware('permission:users.invite')->name('invitations.destroy');

    Route::patch('{membership}', [TeamController::class, 'update'])
        ->middleware('permission:users.update')->name('update');
    Route::post('{membership}/deactivate', [TeamController::class, 'deactivate'])
        ->middleware('permission:users.deactivate')->name('deactivate');
    Route::post('{membership}/reactivate', [TeamController::class, 'reactivate'])
        ->middleware('permission:users.deactivate')->name('reactivate');
});
