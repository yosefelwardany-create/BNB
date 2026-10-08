<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Listings\Services\ListingService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\OrganizationProvisioner;
use App\Domain\Owners\Services\ClientAccounts;
use App\Domain\Owners\Services\OwnershipLedger;
use App\Domain\Properties\Models\Amenity;
use App\Domain\Properties\Models\Portfolio;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyPhoto;
use App\Domain\Properties\Services\PropertyService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Stays Hospitality, a prospective client in Cairo, built from their own public
 * booking site (stayshospitality.com, a Hostaway booking engine).
 *
 * The content is in data/stays-hospitality.json and is only what the site
 * shows: names, areas, sizes, rooms, amenities, rules and photos. Photos are
 * links to Hostaway's image server, stored the way channel imports are.
 *
 * What it deliberately does not invent:
 *
 *  - No prices. The site publishes none, so every property stays a draft with
 *    "a base nightly rate is required" as its blocker, which is the readiness
 *    check doing its job. Setting a rate is the step that puts one on sale.
 *  - No street addresses, bookings, guests, reviews or money. The compound
 *    stands in for the address until the client confirms it.
 *  - No login and no channel connection, so nothing is sent anywhere. The
 *    account holder has a placeholder address the platform owner replaces.
 *
 * It builds once, in one transaction. With the account already there it only
 * gives photos to a property that has none, so nothing a person changed is
 * overwritten.
 */
class StaysHospitalitySeeder extends Seeder
{
    public const SLUG = 'stays-hospitality';

    private const NAME = 'Stays Hospitality';

    private const CURRENCY = 'EGP';

    private const TIMEZONE = 'Africa/Cairo';

    public function run(): void
    {
        $data = $this->data();
        $tenancy = app(TenantContext::class);

        $existing = $tenancy->withoutScope(
            fn () => Organization::query()->where('slug', self::SLUG)->first(),
        );

        if ($existing !== null) {
            $this->command?->warn('The Stays Hospitality account already exists. Its data was left as it is.');
            $this->addMissingPhotos($existing, $data);

            return;
        }

        $organization = DB::transaction(function () use ($data, $tenancy): Organization {
            $organization = app(OrganizationProvisioner::class)->createOrganization([
                'name' => self::NAME,
                'legal_name' => self::NAME,
                'slug' => self::SLUG,
                'status' => 'active',
                'trial_ends_at' => null,
                'base_currency' => self::CURRENCY,
                'timezone' => self::TIMEZONE,
                'locale' => 'en',
                'country_code' => 'EG',
                'contact_email' => 'stays.hospitality@example.com',
            ]);

            $tenancy->runAs($organization, fn () => $this->build($organization, $data));

            return $organization;
        });

        $this->addMissingPhotos($organization, $data);

        $this->command?->info(sprintf(
            'Stays Hospitality created: %d properties as drafts (no prices on their site, so none is on sale yet).',
            count($data['properties']),
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function build(Organization $organization, array $data): void
    {
        $properties = app(PropertyService::class);
        $listings = app(ListingService::class);
        $since = CarbonImmutable::today(self::TIMEZONE);

        $portfolio = Portfolio::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Cairo',
            'slug' => 'cairo',
            'description' => 'New Cairo compounds and Almaza.',
            'color' => '#B8860B',
            'is_active' => true,
        ]);

        $holder = app(ClientAccounts::class)->ensureAccountHolder($organization, [
            'first_name' => 'Stays',
            'last_name' => 'Hospitality',
            'email' => 'stays.hospitality@example.com',
        ]);
        $holder->forceFill([
            'payout_currency' => self::CURRENCY,
            'statement_frequency' => 'monthly',
            'statement_day' => 1,
        ])->save();
        app(ClientAccounts::class)->ensureAgreement($organization, $holder, $since);

        $rules = implode("\n", $data['house_rules']);

        foreach ($data['properties'] as $definition) {
            $details = $definition['details'] ?? [];
            $notes = $definition['notes'] ?? [];
            $summary = $definition['summary'] ?? $definition['name'].'.';
            $description = trim($summary."\n\n".implode("\n", $details));
            $almaza = $definition['area'] === 'Almaza';
            $guests = (int) $definition['guests'];
            $source = 'https://www.stayshospitality.com/listings/'.$definition['id'];

            $property = $properties->create([
                'portfolio_id' => $portfolio->getKey(),
                'name' => $definition['name'],
                'internal_name' => $definition['name'].' ('.self::NAME.')',
                'reference' => 'SH-'.$definition['id'],
                'property_type' => 'apartment',
                'rental_kind' => 'entire_place',
                'address_line_1' => $definition['compound'] ?? $definition['area'],
                'city' => $almaza ? 'Cairo' : 'New Cairo',
                'state' => 'Cairo Governorate',
                'country_code' => 'EG',
                'neighbourhood' => $almaza ? 'Almaza, Heliopolis' : ($definition['compound'] ?? 'New Cairo'),
                'timezone' => self::TIMEZONE,
                'currency' => self::CURRENCY,
                'bedrooms' => $definition['bedrooms'],
                'bathrooms' => $definition['bathrooms'],
                'beds' => $definition['beds'] ?? $definition['bedrooms'],
                'max_occupancy' => $guests,
                'max_adults' => $guests,
                'max_children' => max(0, $guests - 2),
                'max_infants' => 1,
                'max_pets' => ($definition['pets'] ?? false) ? 1 : 0,
                'size_value' => $definition['size'] ?? null,
                'size_unit' => isset($definition['size']) ? 'sqm' : null,
                'floor' => $definition['floor'] ?? null,
                'summary' => $summary,
                'description' => $description,
                'space_description' => implode("\n", $details) ?: null,
                'house_rules' => trim($rules."\n".implode("\n", $notes)),
                'internal_notes' => trim(
                    'From the client\'s booking site: '.$source."\n"
                    .$data['extras']."\n"
                    .($details === [] ? 'Only the headline figures were collected; full details and photos still to come.' : ''),
                ),
                'check_in_time' => '15:00',
                'check_out_time' => '11:00',
            ], Amenity::query()->whereIn('key', $definition['amenities'] ?? [])->pluck('id')->all());

            $listings->primaryFor($property, [
                'title' => $definition['name'],
                'summary' => $summary,
                'description' => $description,
                'max_occupancy' => $guests,
                'bedrooms' => $definition['bedrooms'],
                'bathrooms' => $definition['bathrooms'],
                'beds' => $definition['beds'] ?? $definition['bedrooms'],
            ], reason: 'Imported from the client\'s booking site');

            app(OwnershipLedger::class)->assign($property, $holder, [
                'ownership_percentage' => 100,
                'is_primary' => true,
                'starts_on' => $since->toDateString(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function addMissingPhotos(Organization $organization, array $data): void
    {
        $added = app(TenantContext::class)->runAs($organization, fn (): int => DB::transaction(function () use ($data): int {
            $added = 0;

            foreach ($data['properties'] as $definition) {
                $property = Property::query()->where('reference', 'SH-'.$definition['id'])->first();

                if ($property === null || PropertyPhoto::query()->where('property_id', $property->getKey())->exists()) {
                    continue;
                }

                foreach ($definition['photos'] as $position => $url) {
                    PropertyPhoto::query()->create([
                        'organization_id' => $property->organization_id,
                        'property_id' => $property->getKey(),
                        'disk' => 'external',
                        'path' => sprintf('stayshospitality:%d:%d', $definition['id'], $position + 1),
                        'external_url' => $url,
                        'caption' => null,
                        'position' => $position + 1,
                        'is_cover' => $position === 0,
                    ]);

                    $added++;
                }
            }

            return $added;
        }));

        if ($added > 0) {
            $this->command?->info(sprintf('Added %d photos to the Stays Hospitality properties.', $added));
        }
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__.'/data/stays-hospitality.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
