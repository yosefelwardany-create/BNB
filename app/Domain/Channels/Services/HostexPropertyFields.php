<?php

declare(strict_types=1);

namespace App\Domain\Channels\Services;

use App\Domain\Integrations\Support\HostexData;
use DateTimeZone;
use ResourceBundle;

/** Optional listing metadata is accepted only when its value has the expected shape. */
class HostexPropertyFields
{
    public const KEYS = [
        'city', 'country_name', 'country_code', 'state', 'postal_code', 'neighbourhood',
        'longitude', 'latitude', 'house_picture_list', 'timezone', 'summary', 'description',
        'space_description', 'neighbourhood_description', 'transit_description', 'house_rules',
        'check_in_instructions', 'check_out_instructions', 'check_in_time', 'check_out_time',
        'check_in_until', 'bedrooms', 'bathrooms', 'beds', 'max_occupancy', 'guest_capacity',
        'amenities', 'amenity_list',
        'person_capacity', 'city_name', 'province_name', 'district_name',
        'type', 'space_type', 'area', 'private_bathroom_count', 'public_bathroom_count',
        'descriptions', 'house_room_list', 'allows_children_as_host', 'allows_events_as_host',
        'allows_infants_as_host', 'allows_pets_as_host', 'allows_smoking_as_host',
    ];

    public function values(array $metadata): array
    {
        foreach (['person_capacity' => 'max_occupancy', 'city_name' => 'city', 'province_name' => 'state', 'district_name' => 'neighbourhood'] as $source => $target) {
            $metadata[$target] ??= $metadata[$source] ?? null;
        }
        $descriptions = array_values(array_filter(is_array($metadata['descriptions'] ?? null) ? $metadata['descriptions'] : [], 'is_array'));
        $english = array_values(array_filter($descriptions, fn ($entry) => ($entry['locale'] ?? null) === 'en'));
        $description = count($english) === 1 ? $english[0] : (count($descriptions) === 1 ? $descriptions[0] : []);
        foreach (['description' => 'description', 'about_your_house' => 'space_description',
            'about_street' => 'neighbourhood_description', 'about_traffic' => 'transit_description'] as $source => $target) {
            $metadata[$target] ??= $description[$source] ?? null;
        }
        $privateBathrooms = $metadata['private_bathroom_count'] ?? null;
        $sharedBathrooms = $metadata['public_bathroom_count'] ?? null;
        if (! isset($metadata['bathrooms']) && is_numeric($privateBathrooms) && is_numeric($sharedBathrooms)
            && $privateBathrooms >= 0 && $sharedBathrooms >= 0) {
            $metadata['bathrooms'] = (float) $privateBathrooms + (float) $sharedBathrooms;
        }
        // Hostex's verified as_host fields use 0/1; other numeric enum values
        // remain unknown. Do not infer room/bed counts from duplicate room rows.
        $rules = [];
        foreach (['children' => 'Children', 'events' => 'Events', 'infants' => 'Infants', 'pets' => 'Pets', 'smoking' => 'Smoking'] as $key => $label) {
            $value = $metadata['allows_'.$key.'_as_host'] ?? null;
            if (in_array($value, [0, 1, false, true], true)) {
                $rules[] = $label.($value ? ' allowed.' : ' not allowed.');
            }
        }
        if ($rules !== []) {
            $metadata['house_rules'] ??= implode("\n", $rules);
        }
        $values = [];
        foreach (['city', 'state', 'postal_code', 'neighbourhood', 'summary', 'description',
            'space_description', 'neighbourhood_description', 'transit_description', 'house_rules',
            'check_in_instructions', 'check_out_instructions'] as $key) {
            $value = HostexData::text($metadata[$key] ?? null);
            if ($value !== null) {
                $values[$key] = mb_substr($value, 0, in_array($key, ['city', 'state', 'neighbourhood'], true) ? 120 : ($key === 'postal_code' ? 32 : ($key === 'summary' ? 2000 : 20000)));
            }
        }
        foreach (['bedrooms', 'beds', 'bathrooms', 'max_occupancy'] as $key) {
            $value = $metadata[$key] ?? ($key === 'max_occupancy' ? ($metadata['guest_capacity'] ?? null) : null);
            if (is_numeric($value) && (float) $value >= ($key === 'max_occupancy' ? 1 : 0) && (float) $value <= (in_array($key, ['beds', 'max_occupancy'], true) ? 200 : 100)
                && ($key === 'bathrooms' || floor((float) $value) === (float) $value)) {
                $values[$key] = $key === 'bathrooms' ? (float) $value : (int) $value;
            }
        }
        foreach (['check_in_time', 'check_out_time', 'check_in_until'] as $key) {
            $value = $metadata[$key] ?? null;
            if (is_string($value) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::00)?$/D', $value)) {
                $values[$key] = substr($value, 0, 5).':00';
            }
        }
        $country = $this->countryCode($metadata['country_code'] ?? $metadata['country_name'] ?? null);
        if ($country !== null) {
            $values['country_code'] = $country;
        }
        $zone = $metadata['timezone'] ?? null;
        if (is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            $values['timezone'] = $zone;
        }

        return $values;
    }

    public function countryCode(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $countries = ResourceBundle::create('en', 'ICUDATA-region')?->get('Countries');
        if ($countries === null) {
            return null;
        }
        foreach ($countries as $code => $name) {
            if (preg_match('/^[A-Z]{2}$/D', (string) $code) && $code !== 'ZZ'
                && (strcasecmp(trim($value), (string) $code) === 0 || strcasecmp(trim($value), (string) $name) === 0)) {
                return (string) $code;
            }
        }

        return null;
    }

    /** Parse only an explicit Canadian postal address, not a guessed city. */
    public function addressValues(mixed $address): array
    {
        if (! is_string($address)) {
            return [];
        }
        $parts = array_map('trim', explode(',', $address));
        if (count($parts) < 4 || $this->countryCode(end($parts)) !== 'CA') {
            return [];
        }
        $region = $parts[count($parts) - 2];
        if (! preg_match('/^(AB|BC|MB|NB|NL|NS|NT|NU|ON|PE|QC|SK|YT)\s+([A-Z]\d[A-Z])\s?(\d[A-Z]\d)$/iD', $region, $match)) {
            return [];
        }

        return [
            'address_line_1' => mb_substr(implode(', ', array_slice($parts, 0, -3)), 0, 255),
            'city' => mb_substr($parts[count($parts) - 3], 0, 120),
            'state' => strtoupper($match[1]), 'postal_code' => strtoupper($match[2].' '.$match[3]), 'country_code' => 'CA',
        ];
    }
}
