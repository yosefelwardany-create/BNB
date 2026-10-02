<?php

declare(strict_types=1);

namespace App\Domain\Channels\Services;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Integrations\Providers\Channels\HostexChannelAdapter;
use App\Domain\Integrations\Support\HostexData;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyPhoto;
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
            'Description, bedrooms, beds, bathrooms, capacity, amenities, timezone and house rules have no stable fields in the documented property/listing response. Local values are retained.',
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
                // These metadata names are explicitly documented, but their values
                // are best-effort. Never infer country codes from country names.
                $cache = is_array($listing['metadata'] ?? null) ? $listing['metadata'] : [];
                foreach (['city', 'country_name', 'longitude', 'latitude', 'house_picture_list'] as $key) {
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
            $values['city'] = HostexData::text($source['listing_metadata']['city'] ?? null);
            $rules = $source['price_rules'] ?? [];
            $sourceCurrency = HostexData::currency($rules['listing_currency'] ?? null);
            $monetary = ['base_rate' => 'base_price', 'cleaning_fee' => 'cleaning_fee', 'security_deposit' => 'security_deposit', 'extra_guest_fee' => 'extra_guest_fee'];
            if (isset($applied['currency']) && $property->currency !== $applied['currency']) {
                $overrides[] = 'currency';
            }
            $currencyCanChange = ! in_array('currency', $overrides, true);
            if ($sourceCurrency !== null && $sourceCurrency !== $property->currency) {
                $hasLocalPrices = $property->listings()->where(fn ($q) => $q->where('base_rate', '!=', 0)->orWhere('cleaning_fee', '!=', 0)->orWhere('extra_guest_fee', '!=', 0))->exists()
                    || $property->units()->where('base_rate', '!=', 0)->exists()
                    || $property->unitTypes()->where('base_rate', '!=', 0)->exists()
                    || PricingRule::query()->active()
                        // Shared rules are evaluated in each property's currency.
                        // Only amounts scoped to this property are local overrides.
                        ->where(fn ($q) => $q->where('property_id', $property->id)
                            ->orWhereIn('listing_id', $property->listings()->select('id'))
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
            $settings['hostex']['limitations'] = [
                'Calendar prices and base prices are distinct. Neither is a confirmed reservation quote.',
                'Unspecified listing fields retain their local values; Hostex metadata varies by connection.',
            ];
            if ($sourceCurrency !== null && $sourceCurrency !== $property->currency) {
                $settings['hostex']['limitations'][] = 'Local pricing remains in '.$property->currency.' to preserve existing overrides. Source prices are in '.$sourceCurrency.'; no currency conversion was made.';
            }
            $property->forceFill(['settings' => $settings])->save();
            $photoReport = ['failed' => 0, 'issues' => []];
            if ($sourceCurrency !== null && $sourceCurrency !== $property->currency) {
                $photoReport['failed']++;
                $photoReport['issues'][] = 'Mapping '.$mapping->id.': source pricing in '.$sourceCurrency.' could not replace local pricing in '.$property->currency.'. Review this property\'s currency, fees and scoped pricing overrides.';
            }
            $photoReport['photos'] = $this->photos($mapping, $source, $photoReport);

            return $photoReport;
        });
    }

    private function photos(ChannelListing $mapping, array $source, array &$report): int
    {
        if ($mapping->property_id === null) {
            return 0;
        }
        $pictures = $source['listing_metadata']['house_picture_list'] ?? [];
        $urls = array_merge([$source['cover'] ?? null], is_array($pictures) ? $pictures : []);
        $count = 0;
        $seen = [];
        $unsupported = [];
        foreach ($urls as $position => $picture) {
            if ($picture === null) {
                continue;
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
            // When the cover is absent or unreadable, use the first usable photo.
            $isCover = $count === 0;
            if ($isCover) {
                PropertyPhoto::query()->where('channel_listing_id', $mapping->id)->where('source_key', '!=', $key)
                    ->update(['is_cover' => false]);
            }
            $photo->forceFill([
                'organization_id' => $mapping->organization_id, 'property_id' => $mapping->property_id,
                'disk' => 'external', 'path' => $key, 'external_url' => $url, 'position' => $position,
                'caption' => $photo->caption,
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
}
