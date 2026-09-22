<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Operations;

use App\Domain\Operations\Enums\TaskKind;
use App\Domain\Operations\Models\ChecklistTemplate;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChecklistTemplateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Reusable checklists.
 *
 * A template can be general or specific to one property — a villa with a pool
 * needs items a city flat does not — and the most specific one wins when work
 * is created.
 */
class ChecklistTemplateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ChecklistTemplate::class);

        $query = ChecklistTemplate::query();

        if ($request->boolean('active_only', true)) {
            $query->active();
        }

        if ($request->filled('kind')) {
            $query->where('kind', $request->string('kind')->toString());
        }

        if ($request->filled('property_id')) {
            // Both the property's own templates and the general ones, since
            // either could be applied to work at that property.
            $query->where(function ($q) use ($request): void {
                $q->where('property_id', $request->string('property_id')->toString())
                    ->orWhereNull('property_id');
            });
        }

        return ChecklistTemplateResource::collection(
            $query->orderBy('kind')->orderBy('name')->paginate($this->perPage()),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ChecklistTemplate::class);

        $template = new ChecklistTemplate;
        $template->fill($request->validate($this->rules()));
        $template->organization_id = $this->organization()->getKey();
        $template->save();

        return (new ChecklistTemplateResource($template))->response()->setStatusCode(201);
    }

    public function show(ChecklistTemplate $checklistTemplate): ChecklistTemplateResource
    {
        $this->authorize('view', $checklistTemplate);

        return new ChecklistTemplateResource($checklistTemplate);
    }

    public function update(Request $request, ChecklistTemplate $checklistTemplate): ChecklistTemplateResource
    {
        $this->authorize('update', $checklistTemplate);

        $checklistTemplate->fill($request->validate($this->rules($checklistTemplate)))->save();

        return new ChecklistTemplateResource($checklistTemplate->fresh());
    }

    /**
     * Deactivate a template.
     *
     * Tasks copy their items at creation rather than referencing the template,
     * so retiring one never changes a checklist somebody already worked from.
     */
    public function destroy(ChecklistTemplate $checklistTemplate): JsonResponse
    {
        $this->authorize('delete', $checklistTemplate);

        $checklistTemplate->forceFill(['is_active' => false])->save();

        return response()->json([
            'message' => 'The template has been retired. Checklists already issued are unchanged.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?ChecklistTemplate $template = null): array
    {
        $creating = $template === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'kind' => [$creating ? 'required' : 'sometimes', Rule::enum(TaskKind::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'property_id' => ['sometimes', 'nullable', 'string', 'exists:properties,id'],
            'items' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'items.*.label' => ['required', 'string', 'max:255'],
            'items.*.section' => ['sometimes', 'nullable', 'string', 'max:120'],
            'items.*.requires_photo' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
