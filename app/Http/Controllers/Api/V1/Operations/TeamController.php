<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Operations;

use App\Domain\Operations\Models\Team;
use App\Domain\Users\Models\Membership;
use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Staff crews.
 *
 * Teams are how work is given to a group rather than a person — "housekeeping
 * has this morning's turnovers" — and membership of one is what makes a task
 * visible to the people who might pick it up.
 */
class TeamController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Team::class);

        $query = Team::query()->withCount('members');

        if ($request->boolean('active_only', true)) {
            $query->active();
        }

        if ($request->filled('kind')) {
            $query->where('kind', $request->string('kind')->toString());
        }

        return TeamResource::collection($query->orderBy('name')->paginate($this->perPage()));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Team::class);

        $data = $request->validate($this->rules());

        $team = new Team;
        $team->fill($data);
        $team->organization_id = $this->organization()->getKey();
        $team->save();

        return (new TeamResource($team->loadCount('members')))->response()->setStatusCode(201);
    }

    public function show(Team $team): TeamResource
    {
        $this->authorize('view', $team);

        return new TeamResource($team->load('members')->loadCount('members'));
    }

    public function update(Request $request, Team $team): TeamResource
    {
        $this->authorize('update', $team);

        $team->fill($request->validate($this->rules($team)))->save();

        return new TeamResource($team->fresh()->loadCount('members'));
    }

    /**
     * Set the crew's membership.
     *
     * A whole-list operation rather than add/remove calls: a rota screen edits
     * the crew as a unit, and two people removed in one action should not be
     * able to half-succeed.
     */
    public function syncMembers(Request $request, Team $team): TeamResource
    {
        $this->authorize('update', $team);

        $data = $request->validate([
            'members' => ['present', 'array'],
            'members.*.membership_id' => ['required', 'string', 'exists:memberships,id'],
            'members.*.is_lead' => ['sometimes', 'boolean'],
        ]);

        $organizationId = $this->organization()->getKey();

        $payload = [];

        foreach ($data['members'] as $member) {
            // Membership ids are checked against this organization explicitly:
            // `exists` alone would accept a membership of another tenant.
            $membership = Membership::query()
                ->whereKey($member['membership_id'])
                ->where('organization_id', $organizationId)
                ->first();

            if ($membership === null) {
                continue;
            }

            $payload[$membership->getKey()] = ['is_lead' => (bool) ($member['is_lead'] ?? false)];
        }

        $team->members()->sync($payload);

        return new TeamResource($team->fresh(['members'])->loadCount('members'));
    }

    /**
     * Deactivate a team.
     *
     * Not deleted: every task it was ever given references it, and the history
     * of who did what is worth more than a tidy list.
     */
    public function destroy(Team $team): JsonResponse
    {
        $this->authorize('delete', $team);

        $team->forceFill(['is_active' => false])->save();

        return response()->json([
            'message' => 'The team has been deactivated. Its past work is unchanged.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Team $team = null): array
    {
        return [
            'name' => [$team === null ? 'required' : 'sometimes', 'string', 'max:120'],
            'kind' => ['sometimes', Rule::in(['cleaning', 'maintenance', 'inspection', 'general'])],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'color' => ['sometimes', 'nullable', 'string', 'max:16'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
