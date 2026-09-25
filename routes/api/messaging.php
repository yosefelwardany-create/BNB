<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Agents\ConversationAgentController;
use App\Http\Controllers\Api\V1\Messaging\AutomationRuleController;
use App\Http\Controllers\Api\V1\Messaging\ConversationController;
use App\Http\Controllers\Api\V1\Messaging\MessageTemplateController;
use App\Http\Controllers\Api\V1\Messaging\NotificationController;
use App\Http\Controllers\Api\V1\Messaging\SavedReplyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Messaging, automation and notifications
|--------------------------------------------------------------------------
|
| Reading a thread and writing to one are separate permissions throughout:
| `messages.view` opens the inbox, `messages.send` is what actually reaches a
| guest in the company's name.
|
*/

Route::prefix('conversations')->name('conversations.')->group(function (): void {
    Route::get('/', [ConversationController::class, 'index'])
        ->middleware('permission:messages.view')->name('index');

    // Counts for the inbox's own navigation, in one call.
    Route::get('summary', [ConversationController::class, 'summary'])
        ->middleware('permission:messages.view')->name('summary');

    Route::post('/', [ConversationController::class, 'store'])
        ->middleware('permission:messages.send')->name('store');

    Route::get('{conversation}', [ConversationController::class, 'show'])
        ->middleware('permission:messages.view')->name('show');

    Route::post('{conversation}/messages', [ConversationController::class, 'sendMessage'])
        ->middleware('permission:messages.send')->name('messages.store');

    // A note never leaves the building, so reading the thread is enough.
    Route::post('{conversation}/notes', [ConversationController::class, 'addNote'])
        ->middleware('permission:messages.view')->name('notes.store');

    Route::post('{conversation}/preview', [ConversationController::class, 'previewTemplate'])
        ->middleware('permission:messages.view')->name('preview');

    // Drafting only. Putting the draft in front of the guest is the send
    // endpoint above, and still needs `messages.send`.
    Route::post('{conversation}/agent-draft', [ConversationAgentController::class, 'draft'])
        ->middleware(['permission:messages.view', 'throttle:30,1'])->name('agent-draft');

    Route::post('{conversation}/assign', [ConversationController::class, 'assign'])
        ->middleware('permission:messages.assign')->name('assign');

    Route::post('{conversation}/{action}', [ConversationController::class, 'transition'])
        ->whereIn('action', ['snooze', 'archive', 'close', 'reopen', 'read'])
        ->middleware('permission:messages.view')->name('transition');
});

Route::prefix('message-templates')->name('message-templates.')->group(function (): void {
    Route::get('/', [MessageTemplateController::class, 'index'])
        ->middleware('permission:templates.manage,messages.send,messages.view')->name('index');

    // The complete placeholder vocabulary, for the editor's picker. Nothing
    // outside it resolves, so this list is the whole contract.
    Route::get('vocabulary', [MessageTemplateController::class, 'vocabulary'])
        ->middleware('permission:templates.manage,messages.send,messages.view')->name('vocabulary');

    Route::post('validate', [MessageTemplateController::class, 'validateBody'])
        ->middleware('permission:templates.manage,messages.send,messages.view')->name('validate');

    Route::post('/', [MessageTemplateController::class, 'store'])
        ->middleware('permission:templates.manage')->name('store');

    Route::get('{messageTemplate}', [MessageTemplateController::class, 'show'])
        ->middleware('permission:templates.manage,messages.send,messages.view')->name('show');

    Route::patch('{messageTemplate}', [MessageTemplateController::class, 'update'])
        ->middleware('permission:templates.manage')->name('update');

    Route::delete('{messageTemplate}', [MessageTemplateController::class, 'destroy'])
        ->middleware('permission:templates.manage')->name('destroy');
});

Route::prefix('saved-replies')->name('saved-replies.')->group(function (): void {
    Route::get('/', [SavedReplyController::class, 'index'])
        ->middleware('permission:messages.view,messages.send')->name('index');

    Route::post('/', [SavedReplyController::class, 'store'])
        ->middleware('permission:messages.send')->name('store');

    Route::patch('{savedReply}', [SavedReplyController::class, 'update'])
        ->middleware('permission:messages.send,templates.manage')->name('update');

    Route::delete('{savedReply}', [SavedReplyController::class, 'destroy'])
        ->middleware('permission:messages.send,templates.manage')->name('destroy');
});

Route::prefix('automation')->name('automation.')->middleware('feature:automation')->group(function (): void {
    Route::get('rules', [AutomationRuleController::class, 'index'])
        ->middleware('permission:automations.view,automations.manage')->name('rules.index');

    // Every trigger, operator and action a rule may be built from.
    Route::get('vocabulary', [AutomationRuleController::class, 'vocabulary'])
        ->middleware('permission:automations.view,automations.manage')->name('vocabulary');

    // The activity screen: what every rule did, not just one.
    Route::get('runs', [AutomationRuleController::class, 'allRuns'])
        ->middleware('permission:automations.view,automations.manage')->name('runs');

    Route::post('rules', [AutomationRuleController::class, 'store'])
        ->middleware('permission:automations.manage')->name('rules.store');

    Route::get('rules/{automationRule}', [AutomationRuleController::class, 'show'])
        ->middleware('permission:automations.view,automations.manage')->name('rules.show');

    Route::patch('rules/{automationRule}', [AutomationRuleController::class, 'update'])
        ->middleware('permission:automations.manage')->name('rules.update');

    Route::get('rules/{automationRule}/runs', [AutomationRuleController::class, 'runs'])
        ->middleware('permission:automations.view,automations.manage')->name('rules.runs');

    // Evaluate a rule against a real booking without doing anything.
    Route::post('rules/{automationRule}/test', [AutomationRuleController::class, 'test'])
        ->middleware('permission:automations.view,automations.manage')->name('rules.test');

    Route::delete('rules/{automationRule}', [AutomationRuleController::class, 'destroy'])
        ->middleware('permission:automations.manage')->name('rules.destroy');
});

/*
 * Notifications are addressed to one person, so these are scoped to the
 * authenticated user rather than gated on a permission: no permission makes
 * somebody else's bell yours to read or clear.
 */
Route::prefix('notifications')->name('notifications.')->group(function (): void {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
    Route::get('preferences', [NotificationController::class, 'preferences'])->name('preferences');
    Route::put('preferences', [NotificationController::class, 'updatePreferences'])->name('preferences.update');
    Route::post('read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
    Route::post('{notification}/read', [NotificationController::class, 'markRead'])->name('read');
});
