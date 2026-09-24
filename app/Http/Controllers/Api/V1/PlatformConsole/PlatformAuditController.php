<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Platform\Models\PlatformAuditLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The audit trail across every tenant.
 *
 * Read-only, and it stays that way: an audit log an operator can edit is not an
 * audit log. Filterable by organization, actor and action, because the question
 * is always specific — "what did this person do", "what happened to this
 * company", "who has been granting platform administration".
 *
 * It returns the action, the actor and the description. It deliberately does not
 * return `old_values` and `new_values`, which can contain the customer's own
 * data: reading those is what a support session is for, and that leaves the
 * customer a record.
 */
class PlatformAuditController extends Controller
{
    /**
     * The platform's own trail: what the operator did, across every tenant and
     * to the platform itself.
     *
     * Read first when the question is "what have we done". The tenant trail
     * below answers "what happened inside this company", which is a different
     * question with a different audience.
     */
    public function platform(Request $request): JsonResponse
    {
        $query = PlatformAuditLog::query()->with(['actor', 'organization']);

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->string('organization_id')->toString());
        }

        if ($request->filled('actor_user_id')) {
            $query->where('actor_user_id', $request->string('actor_user_id')->toString());
        }

        if ($request->filled('action')) {
            $query->where('action', 'like', $request->string('action')->toString().'%');
        }

        if ($request->filled('since')) {
            $query->where('created_at', '>=', $request->date('since'));
        }

        $logs = $query->orderByDesc('created_at')->paginate($this->perPage());

        return response()->json([
            'data' => collect($logs->items())->map(fn (PlatformAuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                // The copies, not the joins: these rows outlive the operator
                // account and the organization they refer to.
                'actor_email' => $log->actor_email,
                'organization_id' => $log->organization_id,
                'organization_name' => $log->organization_name,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'description' => $log->description,
                'context' => $log->context,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * The tenants' own audit trails, read across organizations.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()
            ->withoutGlobalScope('organization')
            ->with(['organization', 'user']);

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->string('organization_id')->toString());
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->string('user_id')->toString());
        }

        if ($request->filled('action')) {
            $query->where('action', 'like', $request->string('action')->toString().'%');
        }

        // The platform's own actions, which is the view an operator wants when
        // reviewing what the platform did rather than what customers did.
        if ($request->boolean('platform_only')) {
            $query->where(fn ($q) => $q
                ->where('action', 'like', 'platform.%')
                ->orWhere('action', 'like', 'organization.%')
                ->orWhere('actor_type', 'platform_admin'));
        }

        if ($request->filled('since')) {
            $query->where('created_at', '>=', $request->date('since'));
        }

        $logs = $query->orderByDesc('created_at')->paginate($this->perPage());

        return response()->json([
            'data' => collect($logs->items())->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'organization_id' => $log->organization_id,
                'organization' => $log->organization?->name,
                'action' => $log->action,
                'actor_type' => $log->actor_type,
                'actor_label' => $log->actor_label,
                'user_id' => $log->user_id,
                'subject_type' => $log->auditable_type,
                'subject_id' => $log->auditable_id,
                'description' => $log->description,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'notice' => 'Values changed by an action are not shown here, because they can '
                    .'contain customer data. A support session is the recorded way to read that.',
            ],
        ]);
    }
}
