<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domain\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Clearing the automatic import's old bookkeeping out of the audit trail.
 *
 * The test is mostly about what survives. Only two kinds of row may go, and
 * only when old: system updates to a channel account that touched nothing but
 * import bookkeeping, and system updates to a property that touched nothing
 * but its settings. Everything a person did stays, however old, and so does
 * any system change with real content.
 */
class PruneImportAuditNoiseTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization();
    }

    public function test_it_removes_only_old_import_bookkeeping(): void
    {
        $user = $this->createUser($this->organization);

        $old = now()->subDays(45);
        $recent = now()->subDays(5);

        $noise = [
            $this->row('channel_account.updated', ['last_pull_result' => ['status' => 'completed'], 'last_pull_attempted_at' => 'x'], $old),
            $this->row('property.updated', ['settings' => ['hostex' => ['synced_at' => 'x']]], $old),
        ];

        $kept = [
            // Too recent.
            $this->row('channel_account.updated', ['last_pull_result' => ['status' => 'completed']], $recent),
            // A person did it.
            $this->row('channel_account.updated', ['last_pull_result' => ['status' => 'queued']], $old, $user->getKey()),
            $this->row('property.updated', ['settings' => ['agent' => ['persona' => 'x']]], $old, $user->getKey()),
            // System, but a real change alongside or instead.
            $this->row('channel_account.updated', ['status' => 'error', 'last_error' => 'x'], $old),
            $this->row('property.updated', ['settings' => [], 'name' => 'Renamed'], $old),
            $this->row('property.updated', ['base_rate' => 9000], $old),
            // Something else entirely.
            $this->row('reservation.updated', ['status' => 'cancelled'], $old),
            $this->row('property.updated', null, $old),
        ];

        $this->artisan('audit:prune-import-noise', ['--dry-run' => true])
            ->expectsOutputToContain('2 audit row(s) older than 30 days would be removed')
            ->assertSuccessful();

        // The dry run deleted nothing.
        foreach ([...$noise, ...$kept] as $id) {
            $this->assertDatabaseHas('audit_logs', ['id' => $id]);
        }

        $this->artisan('audit:prune-import-noise')->assertSuccessful();

        foreach ($noise as $id) {
            $this->assertDatabaseMissing('audit_logs', ['id' => $id]);
        }

        foreach ($kept as $id) {
            $this->assertDatabaseHas('audit_logs', ['id' => $id]);
        }
    }

    public function test_the_retention_period_can_be_shortened(): void
    {
        $id = $this->row('channel_account.updated', ['last_synced_at' => 'x'], now()->subDays(10));

        $this->artisan('audit:prune-import-noise')->assertSuccessful();
        $this->assertDatabaseHas('audit_logs', ['id' => $id]);

        $this->artisan('audit:prune-import-noise', ['--days' => 7])->assertSuccessful();
        $this->assertDatabaseMissing('audit_logs', ['id' => $id]);
    }

    /**
     * @param  array<string, mixed>|null  $newValues
     */
    private function row(string $action, ?array $newValues, \DateTimeInterface $at, ?string $userId = null): string
    {
        $id = (string) Str::ulid();

        DB::table('audit_logs')->insert([
            'id' => $id,
            'organization_id' => $this->organization->getKey(),
            'user_id' => $userId,
            'actor_type' => $userId === null ? 'system' : 'user',
            'action' => $action,
            'auditable_type' => Str::before($action, '.'),
            'auditable_id' => (string) Str::ulid(),
            'old_values' => null,
            'new_values' => $newValues === null ? null : json_encode($newValues),
            'created_at' => $at,
        ]);

        return $id;
    }
}
