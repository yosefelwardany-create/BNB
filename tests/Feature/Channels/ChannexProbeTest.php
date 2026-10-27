<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `channex:probe`, which exists to show what a real account returns.
 *
 * Its first run against staging printed every room type and rate plan with no
 * parent at all, because it looked for them in `attributes` and Channex puts
 * them under `relationships`. A probe that hides the very identifiers a push
 * needs is worse than none, so it reads them the way the adapter does.
 */
class ChannexProbeTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY_UUID = '716305c4-561a-4561-a187-7f5b8aeb5920';

    private const ROOM_TYPE_UUID = '994d1375-dbbd-4072-8724-b2ab32ce781b';

    private const RATE_PLAN_UUID = '445835fb-7956-42ac-9efc-3e6f331f0808';

    public function test_it_prints_the_parents_a_mapping_needs(): void
    {
        $organization = $this->createOrganization();

        $account = ChannelAccount::query()->create([
            'organization_id' => $organization->getKey(),
            'channel' => 'channex',
            'name' => 'Channex — staging',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'credentials' => ['api_key' => 'test-key'],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            '*/properties*' => Http::response($this->page([[
                'type' => 'property',
                'id' => self::PROPERTY_UUID,
                'attributes' => [
                    'id' => self::PROPERTY_UUID,
                    'title' => 'Demo Property',
                    'currency' => 'GBP',
                    'min_stay_type' => 'both',
                ],
                'relationships' => ['users' => ['data' => [['id' => 'u-1', 'type' => 'user']]]],
            ]])),
            '*/room_types*' => Http::response($this->page([[
                'type' => 'room_type',
                'id' => self::ROOM_TYPE_UUID,
                'attributes' => ['id' => self::ROOM_TYPE_UUID, 'title' => 'Demo Double', 'occ_adults' => 2],
                'relationships' => [
                    'property' => ['data' => ['id' => self::PROPERTY_UUID, 'type' => 'property']],
                    'facilities' => ['data' => []],
                ],
            ]])),
            '*/rate_plans*' => Http::response($this->page([[
                'type' => 'rate_plan',
                'id' => self::RATE_PLAN_UUID,
                'attributes' => [
                    'id' => self::RATE_PLAN_UUID,
                    'title' => 'Demo Rate Plan',
                    'currency' => 'GBP',
                    'sell_mode' => 'per_room',
                ],
                'relationships' => [
                    'property' => ['data' => ['id' => self::PROPERTY_UUID, 'type' => 'property']],
                    'room_type' => ['data' => ['id' => self::ROOM_TYPE_UUID, 'type' => 'room_type']],
                ],
            ]])),
        ]);

        $this->artisan('channex:probe')
            // What `channels:full-sync --account=` needs, and nowhere else to read it.
            ->expectsOutputToContain('(id '.$account->getKey().')')
            ->expectsOutputToContain('property_id    '.self::PROPERTY_UUID)
            ->expectsOutputToContain('room_type_id   '.self::ROOM_TYPE_UUID)
            ->expectsOutputToContain('min_stay_type  both')
            ->expectsOutputToContain('relationships: property, facilities')
            ->assertSuccessful();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function page(array $rows): array
    {
        return [
            'data' => $rows,
            'meta' => ['total' => count($rows), 'limit' => 10, 'page' => 1],
        ];
    }
}
