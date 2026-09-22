<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Revenue\FeeRuleController;
use App\Http\Controllers\Api\V1\Revenue\PricingRuleController;
use App\Http\Controllers\Api\V1\Revenue\PromotionController;
use App\Http\Controllers\Api\V1\Revenue\QuoteController;
use App\Http\Controllers\Api\V1\Revenue\RatePlanController;
use App\Http\Controllers\Api\V1\Revenue\RevenueAnalyticsController;
use App\Http\Controllers\Api\V1\Revenue\TaxRuleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Revenue management
|--------------------------------------------------------------------------
|
| Everything that decides what a night costs, plus the figures an operator
| judges those decisions by.
|
| Reading is deliberately wider than writing throughout: an agent on the phone
| has to be able to explain a price, which means seeing the rules behind it,
| and a manager who can see occupancy is not thereby able to change rates.
|
| Nothing here deletes. A pricing rule, a promotion or a tax rule is the
| explanation for what a past guest was charged; removing it does not un-charge
| them, it only makes the charge unexplainable. Every `destroy` deactivates.
|
*/

Route::prefix('rate-plans')->name('rate-plans.')->group(function (): void {
    Route::get('/', [RatePlanController::class, 'index'])
        ->middleware('permission:pricing.view,rate_plans.manage')->name('index');

    Route::post('/', [RatePlanController::class, 'store'])
        ->middleware('permission:rate_plans.manage,pricing.update')->name('store');

    Route::get('{ratePlan}', [RatePlanController::class, 'show'])
        ->middleware('permission:pricing.view,rate_plans.manage')->name('show');

    Route::patch('{ratePlan}', [RatePlanController::class, 'update'])
        ->middleware('permission:rate_plans.manage,pricing.update')->name('update');

    Route::delete('{ratePlan}', [RatePlanController::class, 'destroy'])
        ->middleware('permission:rate_plans.manage,pricing.update')->name('destroy');
});

Route::prefix('pricing-rules')->name('pricing-rules.')->group(function (): void {
    Route::get('/', [PricingRuleController::class, 'index'])
        ->middleware('permission:pricing.view')->name('index');

    Route::post('/', [PricingRuleController::class, 'store'])
        ->middleware('permission:pricing.update')->name('store');

    Route::get('{pricingRule}', [PricingRuleController::class, 'show'])
        ->middleware('permission:pricing.view')->name('show');

    // What a rule *would* do, priced through the real engine against real
    // dates. A revenue manager should never have to switch a rule on against
    // live inventory to find out what it does.
    Route::post('{pricingRule}/preview', [PricingRuleController::class, 'preview'])
        ->middleware('permission:pricing.view')->name('preview');

    Route::patch('{pricingRule}', [PricingRuleController::class, 'update'])
        ->middleware('permission:pricing.update')->name('update');

    Route::delete('{pricingRule}', [PricingRuleController::class, 'destroy'])
        ->middleware('permission:pricing.update')->name('destroy');
});

Route::prefix('promotions')->name('promotions.')->group(function (): void {
    Route::get('/', [PromotionController::class, 'index'])
        ->middleware('permission:pricing.view,promotions.manage')->name('index');

    // Whether a code applies to a stay, with the reasons when it does not.
    // Available to anyone who can take a booking, because that is who has the
    // guest on the phone reading out a code.
    Route::post('check', [PromotionController::class, 'check'])
        ->middleware('permission:pricing.view,reservations.create')->name('check');

    Route::post('/', [PromotionController::class, 'store'])
        ->middleware('permission:promotions.manage,pricing.update')->name('store');

    Route::get('{promotion}', [PromotionController::class, 'show'])
        ->middleware('permission:pricing.view,promotions.manage')->name('show');

    Route::patch('{promotion}', [PromotionController::class, 'update'])
        ->middleware('permission:promotions.manage,pricing.update')->name('update');

    Route::delete('{promotion}', [PromotionController::class, 'destroy'])
        ->middleware('permission:promotions.manage,pricing.update')->name('destroy');
});

Route::prefix('fee-rules')->name('fee-rules.')->group(function (): void {
    Route::get('/', [FeeRuleController::class, 'index'])
        ->middleware('permission:pricing.view')->name('index');

    Route::post('/', [FeeRuleController::class, 'store'])
        ->middleware('permission:pricing.update')->name('store');

    Route::get('{feeRule}', [FeeRuleController::class, 'show'])
        ->middleware('permission:pricing.view')->name('show');

    Route::patch('{feeRule}', [FeeRuleController::class, 'update'])
        ->middleware('permission:pricing.update')->name('update');

    Route::delete('{feeRule}', [FeeRuleController::class, 'destroy'])
        ->middleware('permission:pricing.update')->name('destroy');
});

/*
 * Tax rules have their own permission because they are not a commercial
 * decision. Getting one wrong means either overcharging guests or under-
 * remitting to a tax authority, and neither is a revenue manager's call.
 */
Route::prefix('tax-rules')->name('tax-rules.')->group(function (): void {
    Route::get('/', [TaxRuleController::class, 'index'])
        ->middleware('permission:taxes.manage,pricing.view')->name('index');

    Route::post('/', [TaxRuleController::class, 'store'])
        ->middleware('permission:taxes.manage')->name('store');

    Route::get('{taxRule}', [TaxRuleController::class, 'show'])
        ->middleware('permission:taxes.manage,pricing.view')->name('show');

    Route::patch('{taxRule}', [TaxRuleController::class, 'update'])
        ->middleware('permission:taxes.manage')->name('update');

    Route::delete('{taxRule}', [TaxRuleController::class, 'destroy'])
        ->middleware('permission:taxes.manage')->name('destroy');
});

/*
 * Quotes: a price, held. Stored rather than recomputed so a guest who was
 * shown a number and comes back to pay is charged that number.
 */
Route::prefix('quotes')->name('quotes.')->group(function (): void {
    Route::get('/', [QuoteController::class, 'index'])
        ->middleware('permission:pricing.view,reservations.view')->name('index');

    Route::post('/', [QuoteController::class, 'store'])
        ->middleware('permission:reservations.create,pricing.view')->name('store');

    Route::get('{quote}', [QuoteController::class, 'show'])
        ->middleware('permission:pricing.view,reservations.view')->name('show');
});

/*
 * Occupancy, ADR, RevPAR and pace — computed from reservation nights at
 * request time, never from a rollup that would drift from the bookings it
 * summarises.
 */
Route::prefix('revenue')->name('revenue.')->middleware('permission:revenue.view')->group(function (): void {
    Route::get('summary', [RevenueAnalyticsController::class, 'summary'])->name('summary');
    Route::get('daily', [RevenueAnalyticsController::class, 'daily'])->name('daily');
    Route::get('by-source', [RevenueAnalyticsController::class, 'bySource'])->name('by-source');
    Route::get('by-property', [RevenueAnalyticsController::class, 'byProperty'])->name('by-property');
    Route::get('pace', [RevenueAnalyticsController::class, 'pace'])->name('pace');
});
