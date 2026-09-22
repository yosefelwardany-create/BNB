<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Domain\Platform\Models\CustomField;
use App\Domain\Platform\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * Lets an organization attach its own fields to a core record without a schema
 * change.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasCustomFields
{
    public function customFieldValues(): MorphMany
    {
        return $this->morphMany(CustomFieldValue::class, 'entity');
    }

    /**
     * The identifier used in `custom_fields.entity_type`. Defaults to the
     * model's short class name, e.g. "Reservation".
     */
    public static function customFieldEntityType(): string
    {
        return class_basename(static::class);
    }

    /**
     * All custom field values keyed by field key.
     *
     * @return array<string, mixed>
     */
    public function customFields(): array
    {
        $values = $this->customFieldValues()->with('customField')->get();

        $result = [];

        foreach ($values as $value) {
            if ($value->customField === null) {
                continue;
            }

            $result[$value->customField->key] = $value->value();
        }

        return $result;
    }

    /**
     * Write one custom field value, creating the row if needed.
     */
    public function setCustomField(string $key, mixed $value): void
    {
        $field = CustomField::query()
            ->where('organization_id', $this->getAttribute('organization_id'))
            ->where('entity_type', static::customFieldEntityType())
            ->where('key', $key)
            ->first();

        if ($field === null) {
            throw new \InvalidArgumentException(sprintf(
                'No custom field [%s] is defined for [%s].',
                $key,
                static::customFieldEntityType(),
            ));
        }

        $payload = [
            'value_text' => null,
            'value_number' => null,
            'value_date' => null,
            'value_boolean' => null,
            'value_json' => null,
        ];

        $payload[$field->valueColumn()] = $this->castCustomFieldValue($field, $value);

        CustomFieldValue::query()->updateOrCreate(
            [
                'custom_field_id' => $field->getKey(),
                'entity_type' => $this->getMorphClass(),
                'entity_id' => $this->getKey(),
            ],
            $payload + ['organization_id' => $this->getAttribute('organization_id')],
        );
    }

    /**
     * Write several custom fields at once.
     *
     * @param  array<string, mixed>  $values
     */
    public function setCustomFields(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->setCustomField($key, $value);
        }
    }

    private function castCustomFieldValue(CustomField $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($field->type) {
            'number' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'date' => Str::of((string) $value)->substr(0, 10)->toString(),
            'multiselect' => (array) $value,
            default => (string) $value,
        };
    }
}
