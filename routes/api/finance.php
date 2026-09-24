<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Experience\GeneratedDocumentController;
use App\Http\Controllers\Api\V1\Finance\ExpenseController;
use App\Http\Controllers\Api\V1\Finance\OwnerPayoutController;
use App\Http\Controllers\Api\V1\Finance\OwnerStatementController;
use App\Http\Controllers\Api\V1\Finance\PaymentController;
use App\Http\Controllers\Api\V1\Finance\PaymentScheduleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Financials
|--------------------------------------------------------------------------
|
| Money in, money out, and what each owner is owed.
|
| The permission middleware is the coarse gate — "may this caller touch
| payments at all". Which particular records they may read is decided by the
| policies, because an owner with portal access holds statement permissions
| over exactly one owner's data: their own.
|
| Charging and refunding are separate permissions throughout. They are not
| symmetrical operations: a refund has no technical ceiling beyond what was
| captured, and it is the one action here that cannot be undone by taking the
| money again.
|
*/

Route::prefix('payments')->name('payments.')->group(function (): void {
    Route::get('/', [PaymentController::class, 'index'])
        ->middleware('permission:payments.view,financials.view')->name('index');

    // Authorise and capture in one step: the common case at the front desk.
    Route::post('/', [PaymentController::class, 'store'])
        ->middleware('permission:payments.charge')->name('store');

    // Hold funds without taking them — the normal shape of a booking taken in
    // advance. Posts nothing to the ledger.
    Route::post('hold', [PaymentController::class, 'hold'])
        ->middleware('permission:payments.charge')->name('hold');

    // Money that moved without us: a channel that collected from the guest, a
    // bank transfer, cash at the door. Never routed through a processor,
    // because simulating a capture that never happened would be a lie in the
    // ledger as well as on the screen.
    Route::post('external', [PaymentController::class, 'external'])
        ->middleware('permission:payments.charge')->name('external');

    Route::get('{payment}', [PaymentController::class, 'show'])
        ->middleware('permission:payments.view,financials.view')->name('show');

    Route::post('{payment}/capture', [PaymentController::class, 'capture'])
        ->middleware('permission:payments.charge')->name('capture');

    Route::post('{payment}/refund', [PaymentController::class, 'refund'])
        ->middleware('permission:payments.refund')->name('refund');

    Route::post('{payment}/void', [PaymentController::class, 'void'])
        ->middleware('permission:payments.charge')->name('void');
});

/*
 * Instalment plans.
 *
 * Read flat, because "what falls due next week" is a portfolio question.
 * Written under a reservation, because a plan without a booking is meaningless.
 */
Route::prefix('payment-schedules')->name('payment-schedules.')->group(function (): void {
    Route::get('/', [PaymentScheduleController::class, 'index'])
        ->middleware('permission:payments.view,financials.view')->name('index');

    Route::patch('{schedule}', [PaymentScheduleController::class, 'update'])
        ->middleware('permission:payments.charge')->name('update');

    Route::post('{schedule}/charge', [PaymentScheduleController::class, 'charge'])
        ->middleware('permission:payments.charge')->name('charge');

    Route::post('{schedule}/settle', [PaymentScheduleController::class, 'settle'])
        ->middleware('permission:payments.charge')->name('settle');

    Route::post('{schedule}/waive', [PaymentScheduleController::class, 'waive'])
        ->middleware('permission:payments.charge')->name('waive');
});

Route::prefix('reservations/{reservation}/payment-schedule')
    ->name('reservations.payment-schedule.')
    ->group(function (): void {
        Route::get('/', [PaymentScheduleController::class, 'forReservation'])
            ->middleware('permission:payments.view,financials.view,reservations.view')->name('index');

        Route::post('/', [PaymentScheduleController::class, 'store'])
            ->middleware('permission:payments.charge')->name('store');
    });

/*
 * Costs.
 *
 * Recording one and approving it are separate permissions: the person who
 * photographed the receipt should not also be the person who decides an owner
 * pays for it.
 */
Route::prefix('expenses')->name('expenses.')->group(function (): void {
    Route::get('/', [ExpenseController::class, 'index'])
        ->middleware('permission:expenses.manage,financials.view')->name('index');

    Route::get('summary', [ExpenseController::class, 'summary'])
        ->middleware('permission:expenses.manage,financials.view')->name('summary');

    Route::post('/', [ExpenseController::class, 'store'])
        ->middleware('permission:expenses.manage,financials.create')->name('store');

    Route::get('{expense}', [ExpenseController::class, 'show'])
        ->middleware('permission:expenses.manage,financials.view')->name('show');

    Route::patch('{expense}', [ExpenseController::class, 'update'])
        ->middleware('permission:expenses.manage,financials.update')->name('update');

    Route::post('{expense}/approve', [ExpenseController::class, 'approve'])
        ->middleware('permission:expenses.manage,financials.update')->name('approve');

    Route::post('{expense}/reject', [ExpenseController::class, 'reject'])
        ->middleware('permission:expenses.manage,financials.update')->name('reject');

    Route::post('{expense}/pay', [ExpenseController::class, 'pay'])
        ->middleware('permission:expenses.manage,financials.update')->name('pay');

    Route::delete('{expense}', [ExpenseController::class, 'destroy'])
        ->middleware('permission:expenses.manage,financials.update')->name('destroy');
});

/*
 * Owner statements.
 *
 * Three permissions across the lifecycle, because they are three commitments
 * of increasing finality: a draft consumes nothing, approval consumes the
 * revenue and expenses behind it so nothing can be billed twice, and sending
 * puts a figure in front of the owner that the business is answerable for.
 *
 * `owner_statements.view` is deliberately the gate on reads rather than
 * `financials.view`: an owner holds the first and not the second, and the
 * policy narrows the result to their own.
 */
Route::prefix('owner-statements')->name('owner-statements.')->group(function (): void {
    Route::get('/', [OwnerStatementController::class, 'index'])
        ->middleware('permission:owner_statements.view,financials.view')->name('index');

    Route::post('/', [OwnerStatementController::class, 'store'])
        ->middleware('permission:owner_statements.generate')->name('store');

    Route::get('{statement}', [OwnerStatementController::class, 'show'])
        ->middleware('permission:owner_statements.view,financials.view')->name('show');

    Route::post('{statement}/approve', [OwnerStatementController::class, 'approve'])
        ->middleware('permission:owner_statements.approve')->name('approve');

    Route::post('{statement}/send', [OwnerStatementController::class, 'send'])
        ->middleware('permission:owner_statements.send')->name('send');

    // Voided, never deleted. The owner may be holding a copy, and the system
    // has to be able to say what it said.
    Route::post('{statement}/void', [OwnerStatementController::class, 'void'])
        ->middleware('permission:owner_statements.approve')->name('void');
});

/*
 * Owner payouts.
 *
 * Raising and settling are separate calls because they are separate events:
 * only settling moves the ledger, which is why an unsent payout can be
 * withdrawn freely and a sent one cannot be touched at all.
 */
/*
 * The documents people file. Each renders a PDF, stores it and returns the
 * document record; downloading goes through the documents endpoint, which already
 * authorises reading one.
 */
Route::post('owner-statements/{statement}/document', [GeneratedDocumentController::class, 'ownerStatement'])
    ->middleware('permission:owner_statements.view,owner_statements.generate')
    ->name('owner-statements.document');

Route::post('payments/{payment}/receipt', [GeneratedDocumentController::class, 'receipt'])
    ->middleware('permission:payments.view,financials.view')
    ->name('payments.receipt');

Route::prefix('owner-payouts')->name('owner-payouts.')->group(function (): void {
    Route::get('/', [OwnerPayoutController::class, 'index'])
        ->middleware('permission:owner_payouts.manage,financials.view')->name('index');

    Route::post('/', [OwnerPayoutController::class, 'store'])
        ->middleware('permission:owner_payouts.manage')->name('store');

    Route::get('{payout}', [OwnerPayoutController::class, 'show'])
        ->middleware('permission:owner_payouts.manage,financials.view')->name('show');

    Route::post('{payout}/settle', [OwnerPayoutController::class, 'settle'])
        ->middleware('permission:owner_payouts.manage')->name('settle');

    Route::post('{payout}/fail', [OwnerPayoutController::class, 'fail'])
        ->middleware('permission:owner_payouts.manage')->name('fail');

    Route::delete('{payout}', [OwnerPayoutController::class, 'destroy'])
        ->middleware('permission:owner_payouts.manage')->name('cancel');
});
