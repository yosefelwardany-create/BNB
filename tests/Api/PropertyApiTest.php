<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Domain\Properties\Models\Property;
use App\Domain\Users\Support\RoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Access credentials — door codes, Wi-Fi passwords — travel on a single
 * property, to people whose role needs them, and never on a list.
 */
class PropertyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_property_list_leaves_out_access_credentials(): void
    {
        $organization = $this->createOrganization();

        $property = Property::factory()->active()->create([
            'organization_id' => $organization->getKey(),
        ]);
        $property->forceFill(['door_code' => '4821#', 'wifi_password' => 'correct-horse'])->save();

        $manager = $this->createUser($organization, [RoleRegistry::PROPERTY_MANAGER]);

        $this->actingAsUser($manager, $organization)
            ->getJson('/api/v1/properties')
            ->assertOk()
            ->assertJsonPath('data.0.id', $property->getKey())
            ->assertJsonMissingPath('data.0.access');
    }

    public function test_a_single_property_carries_them_for_a_manager(): void
    {
        $organization = $this->createOrganization();

        $property = Property::factory()->active()->create([
            'organization_id' => $organization->getKey(),
        ]);
        $property->forceFill(['door_code' => '4821#', 'wifi_password' => 'correct-horse'])->save();

        $manager = $this->createUser($organization, [RoleRegistry::PROPERTY_MANAGER]);

        $this->actingAsUser($manager, $organization)
            ->getJson('/api/v1/properties/'.$property->getKey())
            ->assertOk()
            ->assertJsonPath('data.access.door_code', '4821#')
            ->assertJsonPath('data.access.wifi_password', 'correct-horse');
    }
}
