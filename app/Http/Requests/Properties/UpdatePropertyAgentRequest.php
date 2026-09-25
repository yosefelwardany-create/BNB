<?php

declare(strict_types=1);

namespace App\Http\Requests\Properties;

use App\Domain\Agents\DataObjects\AgentBrief;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validating one property's agent brief.
 *
 * `auto_send` is restricted to {@see AgentBrief::AUTO_SENDABLE} here so the
 * caller gets a 422 naming the problem rather than a silent narrowing. The
 * brief narrows it again when it is read back, and that duplication is
 * deliberate: this rule is a courtesy to whoever is using the API, and the one
 * in the brief is the control. A row written before this rule existed, or by a
 * future import, still cannot make refunds answer themselves.
 */
class UpdatePropertyAgentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'persona' => ['sometimes', 'string', 'max:600'],

            'languages' => ['sometimes', 'array', 'max:10'],
            'languages.*' => ['string', 'min:2', 'max:12'],

            'never' => ['sometimes', 'array', 'max:20'],
            'never.*' => ['string', 'max:200'],

            'escalate' => ['sometimes', 'array', 'max:40'],
            // Matched as a substring of the guest's message, so a one-character
            // entry would escalate almost everything.
            'escalate.*' => ['string', 'min:3', 'max:80'],

            'extra_knowledge' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'auto_send' => ['sometimes', 'array'],
            'auto_send.*' => ['string', Rule::in(AgentBrief::AUTO_SENDABLE)],

            'confidence_floor' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'auto_send.*.in' => 'Only questions about '
                .implode(', ', array_map(
                    static fn (string $intent): string => str_replace('_', ' ', $intent),
                    AgentBrief::AUTO_SENDABLE,
                ))
                .' can ever be answered without a person reading the reply first.',
            'escalate.*.min' => 'An escalation keyword that short would send almost every message to a person.',
        ];
    }

    /**
     * The brief fields present on this request, ready to merge into settings.
     *
     * Only the top-level keys. `only('escalate.*')` does not mean "the escalate
     * array" — it resolves through `data_get` and yields `['escalate' => ['*' =>
     * [...]]]`, which would be written into the property's settings as a key
     * called `*` and blow up the next read of the brief. Deriving the list from
     * the rules is still right; filtering the item rules out of it is the part
     * that is easy to miss.
     *
     * @return array<string, mixed>
     */
    public function briefChanges(): array
    {
        $fields = array_values(array_filter(
            array_keys($this->rules()),
            static fn (string $key): bool => ! str_contains($key, '.'),
        ));

        return $this->only($fields);
    }
}
