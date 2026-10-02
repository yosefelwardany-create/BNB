<?php

declare(strict_types=1);

namespace App\Http\Requests\Properties;

use App\Domain\Properties\Models\PropertyHelper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validating one entry in a property's helper list.
 *
 * The one rule worth spelling out: a helper has to be reachable *somehow*. A row
 * naming a role and nobody is worse than no row, because it reads as a contact
 * that exists until somebody needs it at two in the morning.
 */
class StorePropertyHelperRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(PropertyHelper::roles())],
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],

            // Points at a record somebody already maintains, or carries its own
            // details. Both is accepted and the linked record wins; neither is
            // refused below.
            'vendor_id' => ['sometimes', 'nullable', 'string', 'exists:vendors,id'],
            'user_id' => ['sometimes', 'nullable', 'string', 'exists:users,id'],

            'name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],

            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_primary' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->method() !== 'POST') {
                return;
            }

            $reachable = $this->filled('vendor_id')
                || $this->filled('user_id')
                || $this->filled('name')
                || $this->filled('phone');

            if (! $reachable) {
                $validator->errors()->add(
                    'name',
                    'A helper needs somebody behind it: pick a vendor or a colleague, or type a name and a number. '
                    .'A role with no contact reads as somebody to call until the night it is needed.',
                );
            }
        });
    }
}
