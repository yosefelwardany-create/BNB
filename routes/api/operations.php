<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Operations\ChecklistTemplateController;
use App\Http\Controllers\Api\V1\Operations\TaskController;
use App\Http\Controllers\Api\V1\Operations\TaskPhotoController;
use App\Http\Controllers\Api\V1\Operations\TaskRecurrenceController;
use App\Http\Controllers\Api\V1\Operations\TeamController;
use App\Http\Controllers\Api\V1\Operations\VendorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Operations
|--------------------------------------------------------------------------
|
| Cleaning, maintenance, inspections, the people who do them and the
| schedules that repeat them.
|
| The permission middleware here is the coarse gate — it answers "may this
| caller touch tasks at all". Which *particular* tasks they may see or change
| is decided by the task policy, because a housekeeper holds task permissions
| but only over their own rota.
|
*/

Route::prefix('tasks')->name('tasks.')->group(function (): void {
    Route::get('/', [TaskController::class, 'index'])
        ->middleware('permission:tasks.view,tasks.view_own')->name('index');

    // The operations board: a day's work, grouped, in one call.
    Route::get('board', [TaskController::class, 'board'])
        ->middleware('permission:tasks.view,tasks.view_own')->name('board');

    Route::post('/', [TaskController::class, 'store'])
        ->middleware('permission:tasks.create')->name('store');

    Route::get('{task}', [TaskController::class, 'show'])
        ->middleware('permission:tasks.view,tasks.view_own')->name('show');

    Route::patch('{task}', [TaskController::class, 'update'])
        ->middleware('permission:tasks.update')->name('update');

    Route::post('{task}/assign', [TaskController::class, 'assign'])
        ->middleware('permission:tasks.assign')->name('assign');

    Route::post('{task}/{action}', [TaskController::class, 'transition'])
        ->whereIn('action', ['accept', 'start', 'block', 'unblock', 'cancel'])
        ->middleware('permission:tasks.update,tasks.delete')->name('transition');

    Route::post('{task}/complete', [TaskController::class, 'complete'])
        ->middleware('permission:tasks.complete')->name('complete');

    Route::patch('{task}/checklist/{item}', [TaskController::class, 'updateChecklistItem'])
        ->middleware('permission:tasks.update')->name('checklist.update');

    Route::post('{task}/comments', [TaskController::class, 'storeComment'])
        ->middleware('permission:tasks.view,tasks.view_own')->name('comments.store');

    Route::post('{task}/photos', [TaskPhotoController::class, 'store'])
        ->middleware('permission:tasks.update')->name('photos.store');

    Route::delete('{task}/photos/{photo}', [TaskPhotoController::class, 'destroy'])
        ->middleware('permission:tasks.update')->name('photos.destroy');
});

// Outside the task prefix because a photo's URL is handed out on its own —
// TaskPhoto::url() falls back to this route wherever the storage disk cannot
// issue a temporary signed link.
Route::get('task-photos/{photo}', [TaskPhotoController::class, 'show'])
    ->middleware('permission:tasks.view,tasks.view_own')
    ->name('tasks.photos.show');

Route::prefix('teams')->name('teams.')->group(function (): void {
    Route::get('/', [TeamController::class, 'index'])
        ->middleware('permission:teams.view,tasks.view,tasks.assign')->name('index');

    Route::post('/', [TeamController::class, 'store'])
        ->middleware('permission:teams.manage')->name('store');

    Route::get('{team}', [TeamController::class, 'show'])
        ->middleware('permission:teams.view,tasks.view,tasks.assign')->name('show');

    Route::patch('{team}', [TeamController::class, 'update'])
        ->middleware('permission:teams.manage')->name('update');

    Route::put('{team}/members', [TeamController::class, 'syncMembers'])
        ->middleware('permission:teams.manage')->name('members.sync');

    Route::delete('{team}', [TeamController::class, 'destroy'])
        ->middleware('permission:teams.manage')->name('destroy');
});

Route::prefix('vendors')->name('vendors.')->group(function (): void {
    Route::get('/', [VendorController::class, 'index'])
        ->middleware('permission:vendors.manage,tasks.view')->name('index');

    Route::post('/', [VendorController::class, 'store'])
        ->middleware('permission:vendors.manage')->name('store');

    Route::get('{vendor}', [VendorController::class, 'show'])
        ->middleware('permission:vendors.manage,tasks.view')->name('show');

    Route::patch('{vendor}', [VendorController::class, 'update'])
        ->middleware('permission:vendors.manage')->name('update');

    Route::delete('{vendor}', [VendorController::class, 'destroy'])
        ->middleware('permission:vendors.manage')->name('destroy');
});

Route::prefix('checklist-templates')->name('checklist-templates.')->group(function (): void {
    Route::get('/', [ChecklistTemplateController::class, 'index'])
        ->middleware('permission:checklists.manage,tasks.view,tasks.view_own')->name('index');

    Route::post('/', [ChecklistTemplateController::class, 'store'])
        ->middleware('permission:checklists.manage')->name('store');

    Route::get('{checklistTemplate}', [ChecklistTemplateController::class, 'show'])
        ->middleware('permission:checklists.manage,tasks.view,tasks.view_own')->name('show');

    Route::patch('{checklistTemplate}', [ChecklistTemplateController::class, 'update'])
        ->middleware('permission:checklists.manage')->name('update');

    Route::delete('{checklistTemplate}', [ChecklistTemplateController::class, 'destroy'])
        ->middleware('permission:checklists.manage')->name('destroy');
});

Route::prefix('task-recurrences')->name('task-recurrences.')->group(function (): void {
    Route::get('/', [TaskRecurrenceController::class, 'index'])
        ->middleware('permission:recurrences.manage,tasks.view')->name('index');

    Route::post('/', [TaskRecurrenceController::class, 'store'])
        ->middleware('permission:recurrences.manage')->name('store');

    Route::get('{recurrence}', [TaskRecurrenceController::class, 'show'])
        ->middleware('permission:recurrences.manage,tasks.view')->name('show');

    Route::patch('{recurrence}', [TaskRecurrenceController::class, 'update'])
        ->middleware('permission:recurrences.manage')->name('update');

    Route::post('{recurrence}/generate', [TaskRecurrenceController::class, 'generate'])
        ->middleware('permission:recurrences.manage')->name('generate');

    Route::delete('{recurrence}', [TaskRecurrenceController::class, 'destroy'])
        ->middleware('permission:recurrences.manage')->name('destroy');
});
