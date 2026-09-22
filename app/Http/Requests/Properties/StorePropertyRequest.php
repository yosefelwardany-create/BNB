<?php

declare(strict_types=1);

namespace App\Http\Requests\Properties;

use App\Domain\Properties\Enums\PropertyType;
use App\Domain\Properties\Enums\RentalKind;
use App\Domain\Properties\Models\PropertyRoom;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePropertyRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller's policy call, which needs
     * the resolved model.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'internal_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'slug' => ['sometimes', 'string', 'max:160', 'regex:/^[a-z0-9-]+$/'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:32'],

            'property_type' => ['required', Rule::enum(PropertyType::class)],
            'rental_kind' => ['sometimes', Rule::enum(RentalKind::class)],
            'is_multi_unit' => ['sometimes', 'boolean'],

            'portfolio_id' => ['sometimes', 'nullable', 'string', 'exists:portfolios,id'],
            'complex_id' => ['sometimes', 'nullable', 'string', 'exists:complexes,id'],

            'address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'state' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'neighbourhood' => ['sometimes', 'nullable', 'string', 'max:160'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],

            // A property's timezone is load-bearing: every scheduled action is
            // computed in it, so an invalid value would silently misfire.
            'timezone' => ['sometimes', 'string', 'timezone'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(config('pms.currencies'))],

            'bedrooms' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'bathrooms' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'beds' => ['sometimes', 'integer', 'min:0', 'max:200'],
            'max_occupancy' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'max_adults' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:200'],
            'max_children' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:200'],
            'max_infants' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:200'],
            'max_pets' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'size_value' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'size_unit' => ['sometimes', 'nullable', Rule::in(['sqm', 'sqft'])],
            'floor' => ['sometimes', 'nullable', 'string', 'max:16'],

            'summary' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'space_description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'neighbourhood_description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'transit_description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'house_rules' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'check_in_instructions' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'check_out_instructions' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:20000'],

            'check_in_time' => ['sometimes', 'date_format:H:i'],
            'check_out_time' => ['sometimes', 'date_format:H:i'],
            'check_in_until' => ['sometimes', 'nullable', 'date_format:H:i'],
            'check_in_method' => ['sometimes', 'nullable', 'string', 'max:48'],

            'wifi_network' => ['sometimes', 'nullable', 'string', 'max:120'],
            'wifi_password' => ['sometimes', 'nullable', 'string', 'max:120'],
            'door_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'access_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],

            // Money arrives as integer minor units so nothing is lost to
            // floating point on the way in.
            'base_rate' => ['sometimes', 'integer', 'min:0'],
            'cleaning_fee' => ['sometimes', 'integer', 'min:0'],
            'security_deposit' => ['sometimes', 'integer', 'min:0'],
            'extra_guest_fee' => ['sometimes', 'integer', 'min:0'],
            'extra_guest_after' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'minimum_nights' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'maximum_nights' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650', 'gte:minimum_nights'],

            'cleaning_duration_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'preparation_hours' => ['sometimes', 'integer', 'min:0', 'max:168'],
            'cancellation_policy_id' => ['sometimes', 'nullable', 'string', 'exists:cancellation_policies,id'],
            'instant_book' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],

            'amenity_ids' => ['sometimes', 'array'],
            'amenity_ids.*' => ['string', 'exists:amenities,id'],

            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:60'],

            'rooms' => ['sometimes', 'array'],
            'rooms.*.name' => ['required', 'string', 'max:120'],
            'rooms.*.room_type' => ['required', Rule::in(['bedroom', 'living_room', 'other'])],
            'rooms.*.has_ensuite' => ['sometimes', 'boolean'],
            'rooms.*.beds' => ['sometimes', 'array'],
            'rooms.*.beds.*.type' => ['required', Rule::in(PropertyRoom::BED_TYPES)],
            'rooms.*.beds.*.count' => ['required', 'integer', 'min:1', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'timezone.timezone' => 'The timezone must be a valid IANA identifier, for example Europe/Lisbon.',
            'maximum_nights.gte' => 'The maximum stay cannot be shorter than the minimum stay.',
        ];
    }

    /**
     * The validated attributes that belong on the property itself.
     *
     * @return array<string, mixed>
     */
    public function propertyAttributes(): array
    {
        return $this->safe()->except(['amenity_ids', 'tags', 'rooms']);
    }
}
