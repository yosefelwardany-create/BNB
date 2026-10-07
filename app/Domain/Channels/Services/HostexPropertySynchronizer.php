<?php

declare(strict_types=1);

namespace App\Domain\Channels\Services;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Integrations\Providers\Channels\HostexChannelAdapter;
use App\Domain\Integrations\Support\HostexData;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Properties\Models\Amenity;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyPhoto;
use App\Domain\Properties\Services\LocationTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class HostexPropertySynchronizer
{
    public function __construct(private readonly HostexChannelAdapter $hostex) {}

    public function sync(ChannelAccount $account): array
    {
        $this->hostex->readIssues = [];
        $report = ['updated' => 0, 'photos' => 0, 'calendar_days' => 0, 'failed' => 0, 'issues' => [], 'unavailable' => [
            'Supported property fields are filled when supplied. Missing or unsupported details are listed in the property editor; local edits are retained. Timezone is resolved from the property coordinates when available.',
            'Gallery metadata varies by connection. Unambiguous public image URLs are imported; unsupported entries are reported together.',
        ]];
        try {
            $listings = $this->hostex->paged($account, 'listings', 'listings');
        } catch (Throwable $e) {
            return array_replace($report, ['failed' => 1, 'issues' => ['Listing details could not be retrieved. Retry Pull after checking the connection.']]);
        }
        $report['failed'] += count($this->hostex->readIssues);
        $report['issues'] = array_merge($report['issues'], $this->hostex->readIssues);
        // Cache details before mapping too, so choosing an existing property can
        // immediately hydrate it without another remote pull.
        foreach ($account->listings()->get() as $mapping) {
            try {
                $metadata = $mapping->metadata ?? [];
                $channels = $metadata['hostex_channels'] ?? [];
                $linked = array_values(array_filter($listings, fn (array $listing): bool => collect($channels)->contains(
                    fn ($channel) => (string) ($channel['listing_id'] ?? '') === (string) ($listing['listing_id'] ?? '')
                        && ($channel['channel_type'] ?? null) === ($listing['channel_type'] ?? null),
                )));
                $airbnb = array_values(array_filter($linked, fn ($listing) => ($listing['channel_type'] ?? null) === 'airbnb'));
                $candidates = $airbnb !== [] ? $airbnb : $linked;
                if (count($candidates) !== 1) {
                    $report['issues'][] = 'Mapping '.$mapping->id.': no unique connected listing; source pricing and images need a verified listing link.';
                    $report['failed']++;

                    continue;
                }
                $listing = $candidates[0];
                $source = $metadata['hostex'] ?? [];
                foreach (['listing_id', 'channel_type', 'url', 'title', 'shelf_status', 'cover'] as $key) {
                    if (HostexData::text($listing[$key] ?? null) !== null) {
                        $source[$key] = $listing[$key];
                    }
                }
                // Metadata is best-effort. Cache only supported fields and
                // validate their individual shapes before applying them.
                $cache = is_array($listing['metadata'] ?? null) ? $listing['metadata'] : [];
                $source['metadata_fields'] = array_keys($cache);
                foreach (HostexPropertyFields::KEYS as $key) {
                    if (isset($cache[$key])) {
                        $source['listing_metadata'][$key] = $cache[$key];
                    }
                }
                $channel = collect($channels)->first(fn ($c) => (string) ($c['listing_id'] ?? '') === (string) $listing['listing_id']
                    && ($c['channel_type'] ?? null) === $listing['channel_type']);
                $source['calendar_currency'] = HostexData::currency($channel['currency'] ?? null);
                if ($listing['channel_type'] === 'airbnb') {
                    try {
                        $rules = $this->hostex->client($account)->get('listings/airbnb/price_and_rules', ['listing_id' => $listing['listing_id']]);
                        $previousRules = $source['price_rules'] ?? [];
                        $moneyKeys = ['base_price', 'weekend_price', 'cleaning_fee', 'short_term_cleaning_fee', 'security_deposit', 'extra_guest_fee', 'pet_fee', 'pet_fee_obj'];
                        if (array_key_exists('listing_currency', $rules) || array_intersect(array_keys($rules), $moneyKeys) !== []) {
                            $rules['listing_currency'] = HostexData::currency($rules['listing_currency'] ?? null);
                            if (($previousRules['listing_currency'] ?? null) !== $rules['listing_currency']) {
                                // Old decimals cannot acquire the new denomination.
                                $previousRules = array_diff_key($previousRules, array_flip($moneyKeys));
                            }
                        }
                        $source['price_rules'] = array_replace($previousRules, $rules);
                        $source['calendar_currency'] = HostexData::currency($rules['listing_currency'] ?? null) ?? $source['calendar_currency'];
                    } catch (Throwable) {
                        $report['failed']++;
                        $report['issues'][] = 'Mapping '.$mapping->id.': Airbnb base prices and rules could not be refreshed; previous values retained.';
                    }
                }
                $from = CarbonImmutable::now()->toDateString();
                $to = CarbonImmutable::now()->addDays(365)->toDateString();
                try {
                    // This documented POST is a read-only query, not a calendar update.
                    $calendar = $this->hostex->client($account)->queryCalendar([
                        'start_date' => $from, 'end_date' => $to,
                        'listings' => [['listing_id' => $listing['listing_id'], 'channel_type' => $listing['channel_type']]],
                    ]);
                    $entry = collect($calendar['listings'] ?? [])->first(fn ($entry) => (string) ($entry['listing_id'] ?? '') === (string) $listing['listing_id']
                        && ($entry['channel_type'] ?? null) === $listing['channel_type']);
                    if (! is_array($entry['calendar'] ?? null)) {
                        throw new \RuntimeException('Missing calendar.');
                    }
                    $days = collect($source['calendar'] ?? [])->keyBy('date')->all();
                    foreach ($entry['calendar'] as $day) {
                        if (! is_array($day) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day['date'] ?? '')) {
                            throw new \RuntimeException('Malformed calendar day.');
                        }
                        $days[$day['date']] = [
                            'date' => $day['date'], 'price' => HostexData::amount($day['price'] ?? null, $source['calendar_currency']),
                            'inventory' => $day['inventory'] ?? null, 'restrictions' => $day['restrictions'] ?? null,
                        ];
                    }
                    ksort($days);
                    $source['calendar'] = array_values(array_filter($days, fn ($day) => $day['date'] >= $from && $day['date'] <= $to));
                    $source['calendar_coverage'] = ['from' => $from, 'to' => $to, 'synced_at' => now()->toIso8601String()];
                    $report['calendar_days'] += count($entry['calendar']);
                } catch (Throwable) {
                    $report['failed']++;
                    $report['issues'][] = 'Mapping '.$mapping->id.': calendar could not be refreshed; previous dated snapshot retained.';
                }
                $source['synced_at'] = now()->toIso8601String();
                $metadata['hostex'] = $source;
                $mapping->forceFill(['metadata' => $metadata, 'external_url' => HostexData::imageUrl($source['url'] ?? null) ?? $mapping->external_url])->save();
                $photos = $this->apply($mapping);
                $report['photos'] += $photos['photos'];
                $report['failed'] += $photos['failed'];
                $report['issues'] = array_merge($report['issues'], $photos['issues']);
                $report['updated']++;
            } catch (Throwable) {
                $report['failed']++;
                $report['issues'][] = 'Mapping '.$mapping->id.': property details could not be saved; retry Pull.';
            }
        }

        return $report;
    }

    /** Source-managed fields update the actual property; operations and overrides survive. */
    public function apply(ChannelListing $mapping): array
    {
        if ($mapping->property_id === null) {
            return ['photos' => 0, 'failed' => 0, 'issues' => []];
        }

        return DB::transaction(function () use ($mapping): array {
            $property = Property::query()->where('organization_id', $mapping->organization_id)->whereKey($mapping->property_id)->lockForUpdate()->firstOrFail();
            $metadata = $mapping->metadata ?? [];
            $source = $metadata['hostex'] ?? [];
            $settings = $property->settings ?? [];
            $previous = $settings['hostex'] ?? [];
            if (isset($previous['mapping_id']) && $previous['mapping_id'] !== $mapping->id) {
                throw new \RuntimeException('Several source mappings target this property. Choose one source before importing its details.');
            }
            $overrides = $settings['hostex_overrides'] ?? [];
            $applied = $previous['applied'] ?? [];
            $values = ['name' => $mapping->external_name];
            foreach (['address_line_1', 'latitude', 'longitude'] as $field) {
                $values[$field] = $metadata[$field] ?? null;
            }
            $optional = is_array($source['listing_metadata'] ?? null) ? $source['listing_metadata'] : [];
            $values = array_replace($values, app(HostexPropertyFields::class)->addressValues($values['address_line_1']));
            $values = array_replace($values, app(HostexPropertyFields::class)->values($optional));
            foreach (['latitude', 'longitude'] as $coordinate) {
                if ($values[$coordinate] === null && is_numeric($optional[$coordinate] ?? null)) {
                    $values[$coordinate] = (float) $optional[$coordinate];
                }
            }
            $zone = $values['timezone'] ?? app(LocationTimezone::class)->resolve($values['latitude'], $values['longitude']);
            $origin = $settings['timezone_origin'] ?? null;
            if ($origin === 'manual' || ($origin === null && ! isset($applied['timezone'])
                && $property->timezone !== $property->organization?->timezone)) {
                $overrides[] = 'timezone';
            }
            if ($zone !== null && ! in_array('timezone', $overrides, true)) {
                $values['timezone'] = $zone;
            } else {
                unset($values['timezone']);
            }
            $rules = $source['price_rules'] ?? [];
            $sourceCurrency = HostexData::currency($rules['listing_currency'] ?? null);
            $monetary = ['base_rate' => 'base_price', 'cleaning_fee' => 'cleaning_fee', 'security_deposit' => 'security_deposit', 'extra_guest_fee' => 'extra_guest_fee'];
            if (isset($applied['currency']) && $property->currency !== $applied['currency']) {
                $overrides[] = 'currency';
            }
            $currencyCanChange = ! in_array('currency', $overrides, true);
            if ($sourceCurrency !== null && $sourceCurrency !== $property->currency) {
                // Archived listings keep their own prices and denomination;
                // they must not block an unpriced property's first import.
                $hasLocalPrices = $property->listings()->where('status', '!=', 'archived')->where(fn ($q) => $q->where('base_rate', '!=', 0)->orWhere('cleaning_fee', '!=', 0)->orWhere('extra_guest_fee', '!=', 0))->exists()
                    || $property->units()->where('base_rate', '!=', 0)->exists()
                    || $property->unitTypes()->where('base_rate', '!=', 0)->exists()
                    || PricingRule::query()->active()
                        // Shared rules are evaluated in each property's currency.
                        // Only amounts scoped to this property are local overrides.
                        ->where(fn ($q) => $q->where('property_id', $property->id)
                            ->orWhereIn('listing_id', $property->listings()->where('status', '!=', 'archived')->select('id'))
                            ->orWhereIn('unit_type_id', $property->unitTypes()->select('id')))
                        ->where(fn ($q) => $q->where(fn ($q) => $q->whereIn('adjustment_type', ['set', 'increase_fixed', 'decrease_fixed'])->where('adjustment_value', '!=', 0))
                            ->orWhere('floor_rate', '!=', 0)->orWhere('ceiling_rate', '!=', 0))->exists();
                $currencyCanChange = $currencyCanChange && ! $hasLocalPrices;
            }
            foreach ($monetary as $field => $key) {
                // Never relabel locally entered amounts in another currency.
                if ((int) $property->{$field} !== 0 && (! isset($rules[$key]) || in_array($field, $overrides, true)
                    || (isset($applied[$field]) && (string) $property->getRawOriginal($field) !== (string) $applied[$field]))) {
                    $currencyCanChange = false;
                }
            }
            if ($sourceCurrency !== null && ($sourceCurrency === $property->currency || $currencyCanChange)) {
                $values['currency'] = $sourceCurrency;
                foreach ($monetary as $field => $key) {
                    $values[$field] = HostexData::amount($rules[$key] ?? null, $sourceCurrency)['amount'] ?? null;
                }
            }
            foreach (['check_in_time' => 'check_in_start_time', 'check_in_until' => 'check_in_end_time', 'check_out_time' => 'check_out_before'] as $field => $key) {
                if (isset($rules[$key]) && is_numeric($rules[$key]) && $rules[$key] >= 0 && $rules[$key] <= 23) {
                    $values[$field] = sprintf('%02d:00:00', $rules[$key]);
                }
            }
            if (isset($rules['instant_booking']) && is_bool($rules['instant_booking'])) {
                $values['instant_book'] = $rules['instant_booking'];
            }
            foreach (['minimum_nights' => 'minimum_stay', 'maximum_nights' => 'maximum_stay', 'extra_guest_after' => 'max_guests'] as $field => $key) {
                if (isset($rules[$key]) && is_numeric($rules[$key])) {
                    $values[$field] = (int) $rules[$key];
                }
            }
            if (isset($values['currency']) && $values['currency'] !== $property->currency) {
                // An archived listing with its own currency must not inherit
                // newly imported fees in a different denomination later.
                foreach ($property->listings()->where('status', 'archived')->where('currency', '!=', $values['currency'])->get() as $archived) {
                    foreach (['base_rate', 'cleaning_fee', 'extra_guest_fee'] as $field) {
                        if ($archived->{$field} === null) {
                            $archived->{$field} = $property->{$field};
                        }
                    }
                    $archived->save();
                }
            }
            foreach ($values as $field => $value) {
                if ($value === null || $value === '' || in_array($field, $overrides, true)) {
                    continue;
                }
                // A local edit after the last import is an intentional override.
                if (array_key_exists($field, $applied) && (string) $property->getRawOriginal($field) !== (string) $applied[$field]) {
                    $overrides[] = $field;

                    continue;
                }
                $property->{$field} = $value;
            }
            $property->save();
            // Listings inherit the property denomination. Only listings without
            // locally priced overrides can follow a denomination change.
            $property->listings()->where(fn ($q) => $q->whereNull('base_rate')->orWhere('base_rate', 0))
                ->where(fn ($q) => $q->whereNull('cleaning_fee')->orWhere('cleaning_fee', 0))
                ->where(fn ($q) => $q->whereNull('extra_guest_fee')->orWhere('extra_guest_fee', 0))
                ->update(['currency' => $property->currency]);
            foreach ($values as $field => $value) {
                if ($value !== null && ! in_array($field, $overrides, true)) {
                    $applied[$field] = $property->getRawOriginal($field);
                }
            }
            $settings['hostex_overrides'] = array_values(array_unique($overrides));
            $settings['hostex'] = $source + ['mapping_id' => $mapping->id, 'property_id' => $mapping->external_listing_id];
            $settings['hostex']['applied'] = $applied;
            $settings['hostex']['imported_amenity_ids'] = $previous['imported_amenity_ids'] ?? [];
            if (isset($values['timezone']) && ! in_array('timezone', $overrides, true)) {
                $settings['timezone_origin'] = isset($optional['timezone']) ? 'hostex' : 'coordinates';
            }
            $this->amenities($property, $optional, $settings);
            $requiredSourceFields = ['description', 'bedrooms', 'beds', 'bathrooms', 'max_occupancy', 'house_rules'];
            $settings['hostex']['missing_fields'] = array_values(array_filter($requiredSourceFields, fn ($field) => ! array_key_exists($field, $applied)));
            if (($settings['hostex']['amenities_status'] ?? 'missing') !== 'imported') {
                $settings['hostex']['missing_fields'][] = 'amenities';
            }
            if ($zone === null && ! in_array('timezone', $overrides, true)) {
                $settings['hostex']['missing_fields'][] = 'timezone';
            }
            $settings['hostex']['limitations'] = [
                'Calendar prices and base prices are distinct. Neither is a confirmed reservation quote.',
                'Unspecified listing fields retain their local values; Hostex metadata varies by connection.',
            ];
            if ($sourceCurrency !== null && $sourceCurrency !== $property->currency) {
                $settings['hostex']['limitations'][] = 'Local pricing remains in '.$property->currency.' to preserve existing overrides. Source prices are in '.$sourceCurrency.'; no currency conversion was made.';
            }
            // Saved only when the imported data actually changed, or once a day
            // to keep the visible "refreshed" date current. Every import stamps
            // fresh sync times into this snapshot, and saving it regardless
            // rewrote (and audited) the whole property, 366 days of calendar
            // included, on every pull.
            if ($this->settingsChanged($property->settings ?? [], $settings)) {
                $property->forceFill(['settings' => $settings])->save();
            }
            $photoReport = ['failed' => 0, 'issues' => []];
            if ($sourceCurrency !== null && $sourceCurrency !== $property->currency) {
                $photoReport['failed']++;
                $photoReport['issues'][] = 'Mapping '.$mapping->id.': source pricing in '.$sourceCurrency.' could not replace local pricing in '.$property->currency.'. Review this property\'s currency, fees and scoped pricing overrides.';
            }
            $photoReport['photos'] = $this->photos($mapping, $source, $photoReport);

            return $photoReport;
        });
    }

    private function amenities(Property $property, array $metadata, array &$settings): void
    {
        $source = $metadata['amenities'] ?? $metadata['amenity_list'] ?? null;
        $settings['hostex']['amenities_status'] = $source === null ? 'missing' : 'unsupported';
        if (! is_array($source) || ! array_is_list($source)) {
            return;
        }
        $normalize = static fn (string $value): string => preg_replace('/[^a-z0-9]/', '', mb_strtolower($value));
        $catalogue = Amenity::query()->availableTo($property->organization_id)->get();
        $ids = [];
        $unmatched = 0;
        foreach ($source as $entry) {
            if (is_array($entry) && array_key_exists('available', $entry) && ! is_bool($entry['available'])) {
                $unmatched++;

                continue;
            }
            if (is_array($entry) && ($entry['available'] ?? true) === false) {
                continue;
            }
            $name = is_string($entry) ? $entry : (is_array($entry) ? ($entry['key'] ?? $entry['name'] ?? null) : null);
            if (! is_string($name) || $normalize($name) === '') {
                $unmatched++;

                continue;
            }
            // Verified Hostex enum names differ from our catalogue vocabulary.
            $aliases = [
                'WIRELESS_INTERNET' => 'wifi', 'ROOM_DARKENING_SHADES' => 'room_darkening_blinds',
                'DISHES_AND_SILVERWARE' => 'dishes_and_cutlery', 'SMOKE_DETECTOR' => 'smoke_alarm',
                'CARBON_MONOXIDE_DETECTOR' => 'carbon_monoxide_alarm', 'JACUZZI' => 'hot_tub',
                'WASHER' => 'washing_machine', 'LUGGAGE_DROPOFF_ALLOWED' => 'luggage_drop_off',
                'HOT_WATER_KETTLE' => 'kettle', 'BED_LINENS' => 'bed_linen',
            ];
            $extras = [
                'BBQ_AREA' => ['Barbecue area', 'outdoor'],
                'EXERCISE_EQUIPMENT' => ['Exercise equipment', 'outdoor'],
                'BODY_SOAP' => ['Body soap', 'bathroom'], 'SHOWER_GEL' => ['Shower gel', 'bathroom'],
                'CONDITIONER' => ['Conditioner', 'bathroom'], 'ALFRESCO_DINING' => ['Outdoor dining area', 'outdoor'],
                'PLAYGROUND' => ['Playground', 'family'], 'LOCK_ON_BEDROOM_DOOR' => ['Lock on bedroom door', 'safety'],
                'OUTDOOR_SEATING' => ['Outdoor seating', 'outdoor'],
                'PAID_PARKING_ON_PREMISES' => ['Paid parking on premises', 'parking'],
                'PORTABLE_FANS' => ['Portable fans', 'essentials'], 'PRIVATE_ENTRANCE' => ['Private entrance', 'accessibility'],
                'WARDROBE_OR_CLOSET' => ['Wardrobe or closet', 'essentials'],
                'PATIO_OR_BELCONY' => ['Patio or balcony', 'outdoor'], 'POOL_TABLE' => ['Pool table', 'entertainment'],
                'THEME_ROOM' => ['Theme room', 'essentials'],
            ];
            if (isset($extras[$name])) {
                [$label, $category] = $extras[$name];
                $amenity = Amenity::query()->firstOrCreate([
                    'organization_id' => $property->organization_id, 'key' => 'hostex_'.strtolower($name),
                ], ['name' => $label, 'category' => $category]);
                $ids[] = $amenity->id;

                continue;
            }
            $name = $aliases[$name] ?? $name;
            $matches = $catalogue->filter(fn ($amenity) => $normalize($amenity->key) === $normalize($name) || $normalize($amenity->name) === $normalize($name));
            if ($matches->count() === 1) {
                $ids[] = $matches->first()->id;
            } else {
                $unmatched++;
            }
        }
        $current = $property->amenities()->pluck('amenities.id')->sort()->values()->all();
        $previous = $property->settings['hostex']['applied']['amenity_ids'] ?? null;
        if ($previous !== null && $current !== $previous) {
            $settings['hostex_overrides'][] = 'amenities';
        }
        $settings['hostex']['amenities_status'] = $unmatched > 0 ? 'partial' : 'imported';
        $settings['hostex']['unmatched_amenities'] = $unmatched;
        if (in_array('amenities', $settings['hostex_overrides'], true)) {
            return;
        }
        $oldImported = $property->settings['hostex']['imported_amenity_ids'] ?? [];
        // Unsupported entries cannot safely be interpreted as removals.
        $retained = $unmatched > 0 ? $current : array_diff($current, $oldImported);
        $next = array_values(array_unique([...$retained, ...$ids]));
        sort($next);
        $property->amenities()->sync($next);
        $settings['hostex']['applied']['amenity_ids'] = $next;
        $settings['hostex']['imported_amenity_ids'] = array_values(array_unique($ids));
    }

    private function photos(ChannelListing $mapping, array $source, array &$report): int
    {
        if ($mapping->property_id === null) {
            return 0;
        }
        $pictures = $source['listing_metadata']['house_picture_list'] ?? [];
        if (is_array($pictures) && $pictures !== [] && count(array_filter($pictures, fn ($picture) => is_array($picture) && is_numeric($picture['order'] ?? null))) === count($pictures)) {
            usort($pictures, fn ($a, $b) => $a['order'] <=> $b['order']);
        }
        $urls = array_merge([$source['cover'] ?? null], is_array($pictures) ? $pictures : []);
        $count = 0;
        $seen = [];
        $unsupported = [];
        foreach ($urls as $position => $picture) {
            if ($picture === null) {
                continue;
            }
            if (is_string($picture) && strlen($picture) <= 65536 && str_starts_with(trim($picture), '{')) {
                $decoded = json_decode($picture, true, 8);
                $picture = is_array($decoded) ? $decoded : $picture;
            }
            $url = HostexData::pictureUrl($picture);
            if ($url === null) {
                $shape = is_array($picture) ? 'structured metadata' : get_debug_type($picture);
                $unsupported[$shape] = ($unsupported[$shape] ?? 0) + 1;

                continue;
            }
            $parts = parse_url($url);
            parse_str($parts['query'] ?? '', $query);
            // Preserve image-identifying parameters, while signed URL renewal
            // must not create a second photo of the same asset.
            $query = array_filter($query, fn ($key) => ! preg_match('/^(token|expires?|signature|x-amz-.+|x-goog-.+)$/i', (string) $key), ARRAY_FILTER_USE_KEY);
            ksort($query);
            $key = hash('sha256', strtolower($parts['host'] ?? '').($parts['path'] ?? '').'?'.http_build_query($query));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $photo = PropertyPhoto::query()->firstOrNew(['channel_listing_id' => $mapping->id, 'source_key' => $key]);
            $caption = is_array($picture) && isset($picture['original_url']) ? HostexData::text($picture['caption'] ?? null) : null;
            // When the cover is absent or unreadable, use the first usable photo.
            $isCover = $count === 0;
            if ($isCover) {
                PropertyPhoto::query()->where('channel_listing_id', $mapping->id)->where('source_key', '!=', $key)
                    ->update(['is_cover' => false]);
            }
            $photo->forceFill([
                'organization_id' => $mapping->organization_id, 'property_id' => $mapping->property_id,
                'disk' => 'external', 'path' => $key, 'external_url' => $url, 'position' => $position,
                'caption' => $photo->exists ? $photo->caption : ($caption === null ? null : mb_substr($caption, 0, 255)),
                'is_cover' => $isCover && ! PropertyPhoto::query()->where('property_id', $mapping->property_id)->whereNull('channel_listing_id')->where('is_cover', true)->exists(),
            ])->save();
            $count++;
        }
        foreach ($unsupported as $shape => $number) {
            $report['failed'] += $number;
            $report['issues'][] = 'Mapping '.$mapping->id.': '.$number.' image entries could not be read ('.$shape.'). A unique public HTTPS image URL is required.';
        }

        return $count;
    }

    /**
     * Whether a new settings snapshot is worth writing.
     *
     * True when anything other than the sync timestamps differs, or when the
     * stored snapshot's sync time is more than a day old.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function settingsChanged(array $before, array $after): bool
    {
        if ($this->withoutSyncStamps($before) != $this->withoutSyncStamps($after)) {
            return true;
        }

        $stamp = $before['hostex']['synced_at'] ?? null;

        if (! is_string($stamp)) {
            return true;
        }

        try {
            return CarbonImmutable::parse($stamp)->lt(now()->subDay());
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function withoutSyncStamps(array $settings): array
    {
        unset(
            $settings['hostex']['synced_at'],
            $settings['hostex']['calendar_coverage']['synced_at'],
            $settings['hostex']['availability_coverage']['synced_at'],
        );

        return $settings;
    }
}
