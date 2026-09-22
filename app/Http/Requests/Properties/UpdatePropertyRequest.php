<?php

declare(strict_types=1);

namespace App\Http\Requests\Properties;

/**
 * Updating reuses the create rules: every field there is already optional
 * (`sometimes`), which is exactly the semantics of a partial update. The one
 * difference is that `name` and `property_type` are not required, because an
 * update may touch neither.
 */
class UpdatePropertyRequest extends StorePropertyRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['name'] = ['sometimes', 'string', 'max:160'];
        $rules['property_type'] = ['sometimes', ...array_slice($rules['property_type'], 1)];

        return $rules;
    }
}
