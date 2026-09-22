<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Messaging\Services\TemplateRenderer;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageTemplateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Message templates.
 *
 * The placeholder vocabulary is published as data so the editor can offer a
 * picker and validate as somebody types. Nothing outside that vocabulary
 * resolves — a template is substitution, not a program — so the list is the
 * whole truth about what a template can say.
 */
class MessageTemplateController extends Controller
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MessageTemplate::class);

        $query = MessageTemplate::query();

        if ($request->boolean('active_only', true)) {
            $query->active();
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }

        if ($request->filled('language')) {
            $query->where('language', $request->string('language')->toString());
        }

        // Translations hang off their original, so the default listing shows
        // originals only and the variants come with each one.
        if (! $request->boolean('include_translations')) {
            $query->whereNull('translation_of_id');
        }

        return MessageTemplateResource::collection(
            $query->with('translations')->orderBy('category')->orderBy('name')->paginate($this->perPage()),
        );
    }

    /**
     * Every placeholder a template may use, grouped for a picker.
     */
    public function vocabulary(): JsonResponse
    {
        $this->authorize('viewAny', MessageTemplate::class);

        return response()->json([
            'data' => TemplateRenderer::vocabulary(),
            'modifiers' => [
                'upper' => 'UPPERCASE',
                'lower' => 'lowercase',
                'title' => 'Title Case',
                'long' => 'Monday 4 May 2026',
                'short' => '4 May 2026',
                'day' => 'Monday',
                'time' => '15:00',
            ],
            'syntax' => '{{ guest.first_name }} or {{ check_in_date|long }}',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', MessageTemplate::class);

        $data = $request->validate($this->rules());

        $this->assertPlaceholdersAreKnown($data);

        $template = new MessageTemplate;
        $template->fill($data);
        $template->organization_id = $this->organization()->getKey();
        $template->created_by_id = $this->currentUser()->getKey();
        $template->save();

        return (new MessageTemplateResource($template))->response()->setStatusCode(201);
    }

    public function show(MessageTemplate $messageTemplate): MessageTemplateResource
    {
        $this->authorize('view', $messageTemplate);

        return new MessageTemplateResource($messageTemplate->load('translations'));
    }

    public function update(Request $request, MessageTemplate $messageTemplate): MessageTemplateResource
    {
        $this->authorize('update', $messageTemplate);

        $data = $request->validate($this->rules($messageTemplate));

        $this->assertPlaceholdersAreKnown($data);

        $messageTemplate->fill($data)->save();

        return new MessageTemplateResource($messageTemplate->fresh('translations'));
    }

    /**
     * Check a draft without saving it, for the editor's live validation.
     */
    public function validateBody(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MessageTemplate::class);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:50000'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $body = $this->renderer->validate($data['body']);
        $subject = isset($data['subject']) ? $this->renderer->validate($data['subject']) : null;

        return response()->json([
            'data' => [
                'valid' => $body['valid'] && ($subject === null || $subject['valid']),
                'body' => $body,
                'subject' => $subject,
            ],
        ]);
    }

    /**
     * Retire a template.
     *
     * Deactivated rather than deleted: messages already sent reference it, and
     * so may automation rules whose run log would otherwise lose its meaning.
     */
    public function destroy(MessageTemplate $messageTemplate): JsonResponse
    {
        $this->authorize('delete', $messageTemplate);

        $messageTemplate->forceFill(['is_active' => false])->save();

        return response()->json([
            'message' => 'The template has been retired. Messages already sent are unchanged.',
        ]);
    }

    /**
     * Refuse a template containing a placeholder that will never resolve.
     *
     * An unknown placeholder does not break anything — it is left visible
     * rather than guessed at — but a guest reading "Dear {{ guest.forename }}"
     * is exactly the failure a template editor exists to prevent.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertPlaceholdersAreKnown(array $data): void
    {
        $unknown = [];

        foreach (['body', 'subject'] as $field) {
            if (! isset($data[$field]) || $data[$field] === null) {
                continue;
            }

            $unknown = array_merge($unknown, $this->renderer->validate((string) $data[$field])['unknown']);
        }

        if ($unknown === []) {
            return;
        }

        throw \Illuminate\Validation\ValidationException::withMessages([
            'body' => sprintf(
                'These placeholders are not recognised and would be shown to the guest as written: %s.',
                implode(', ', array_unique($unknown)),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?MessageTemplate $template = null): array
    {
        $creating = $template === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:48'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body' => [$creating ? 'required' : 'sometimes', 'string', 'max:50000'],
            'language' => ['sometimes', 'string', 'max:12'],
            'translation_of_id' => ['sometimes', 'nullable', 'string', 'exists:message_templates,id'],
            'transport' => ['sometimes', Rule::in(['channel', 'email', 'sms', 'portal', 'whatsapp'])],
            'property_ids' => ['sometimes', 'nullable', 'array'],
            'property_ids.*' => ['string', 'exists:properties,id'],
            'channels' => ['sometimes', 'nullable', 'array'],
            'channels.*' => ['string', 'max:48'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
