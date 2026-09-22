<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Users\Models\Permission;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Support\PermissionRegistry;
use App\Domain\Users\Support\RoleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles the `permissions` and system `roles` tables with the code
 * registries.
 *
 * Run on every deploy. Permissions that disappear from the registry are
 * reported but not deleted: removing one silently would strip access from a
 * customer's custom role.
 */
class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync {--prune : Delete permissions that are no longer in the registry}';

    protected $description = 'Synchronise the permission catalogue and system roles with the code registries';

    public function handle(): int
    {
        $created = $this->syncPermissions();
        $roles = $this->syncSystemRoles();

        $this->components->info(sprintf(
            'Permissions: %d created, %d total. System roles: %d synchronised.',
            $created,
            Permission::query()->count(),
            $roles,
        ));

        $this->reportOrphans();

        return self::SUCCESS;
    }

    private function syncPermissions(): int
    {
        $catalogue = PermissionRegistry::flat();
        $modules = PermissionRegistry::modules();

        $existing = Permission::query()->pluck('id', 'name')->all();
        $created = 0;

        foreach ($catalogue as $name => $description) {
            if (isset($existing[$name])) {
                Permission::query()->where('id', $existing[$name])->update([
                    'module' => $modules[$name],
                    'description' => $description,
                ]);

                continue;
            }

            Permission::query()->create([
                'name' => $name,
                'module' => $modules[$name],
                'description' => $description,
            ]);

            $created++;
        }

        return $created;
    }

    private function syncSystemRoles(): int
    {
        $count = 0;

        foreach (RoleRegistry::definitions() as $slug => $definition) {
            DB::transaction(function () use ($slug, $definition, &$count): void {
                /** @var Role $role */
                $role = Role::query()->firstOrNew([
                    'organization_id' => null,
                    'slug' => $slug,
                ]);

                $role->fill([
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'portal' => $definition['portal'],
                    'is_system' => true,
                ])->save();

                $permissionIds = Permission::query()
                    ->whereIn('name', RoleRegistry::expand($definition['permissions']))
                    ->pluck('id')
                    ->all();

                $role->permissions()->sync($permissionIds);

                $count++;
            });
        }

        return $count;
    }

    private function reportOrphans(): void
    {
        $orphans = Permission::query()
            ->whereNotIn('name', PermissionRegistry::all())
            ->pluck('name');

        if ($orphans->isEmpty()) {
            return;
        }

        $this->components->warn(sprintf(
            '%d permission(s) exist in the database but not in the registry: %s',
            $orphans->count(),
            $orphans->implode(', '),
        ));

        if ($this->option('prune')) {
            Permission::query()->whereIn('name', $orphans)->delete();
            $this->components->info('Pruned orphaned permissions.');
        } else {
            $this->components->info('Re-run with --prune to remove them.');
        }
    }
}
