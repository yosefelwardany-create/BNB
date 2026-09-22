<?php

declare(strict_types=1);

namespace App\Domain\Users\Support;

/**
 * The system roles every organization starts with.
 *
 * Organizations may create additional roles and may adjust the permissions of
 * a copy of a system role, but the definitions here guarantee that a freshly
 * created organization is immediately usable with a sensible separation of
 * duties.
 */
final class RoleRegistry
{
    public const SUPER_ADMIN = 'super-admin';

    public const ORGANIZATION_ADMIN = 'organization-admin';

    public const PROPERTY_MANAGER = 'property-manager';

    public const OPERATIONS_MANAGER = 'operations-manager';

    public const RESERVATIONS_AGENT = 'reservations-agent';

    public const ACCOUNTANT = 'accountant';

    public const OWNER = 'owner';

    public const CLEANER = 'cleaner';

    public const MAINTENANCE = 'maintenance';

    public const STAFF = 'staff';

    public const READ_ONLY = 'read-only';

    /**
     * Role definitions.
     *
     * `permissions` is either the string `*` (every permission, now and in the
     * future) or an explicit list. Wildcards such as `tasks.*` expand against
     * the permission registry.
     *
     * @return array<string, array{name: string, description: string, permissions: string|list<string>, portal: string}>
     */
    public static function definitions(): array
    {
        return [
            self::SUPER_ADMIN => [
                'name' => 'Super Admin',
                'description' => 'Unrestricted access to every feature, including platform administration.',
                'permissions' => '*',
                'portal' => 'admin',
            ],

            self::ORGANIZATION_ADMIN => [
                'name' => 'Organization Admin',
                'description' => 'Full access to the organization: settings, users, properties, finances and integrations.',
                'permissions' => '*',
                'portal' => 'admin',
            ],

            self::PROPERTY_MANAGER => [
                'name' => 'Property Manager',
                'description' => 'Runs day-to-day property operations, reservations, guests and pricing.',
                'permissions' => [
                    'organization.view',
                    'users.view',
                    'portfolios.view',
                    'properties.*', 'units.*', 'listings.*',
                    'guests.view', 'guests.create', 'guests.update', 'guests.merge',
                    'owners.view', 'owners.update',
                    'reservations.*', 'calendar.*',
                    'pricing.*', 'rate_plans.manage', 'promotions.manage', 'revenue.view',
                    'tasks.*', 'checklists.manage', 'inspections.manage', 'vendors.manage',
                    'messages.*', 'templates.manage', 'automations.view', 'automations.manage',
                    'channels.view', 'channels.sync', 'channels.map',
                    'financials.view', 'payments.view', 'payments.charge',
                    'owner_statements.view',
                    'reviews.*',
                    'reports.view', 'reports.export', 'analytics.view',
                    'documents.view', 'documents.manage',
                    'locks.view', 'locks.manage',
                    'upsells.manage',
                ],
                'portal' => 'admin',
            ],

            self::OPERATIONS_MANAGER => [
                'name' => 'Operations Manager',
                'description' => 'Owns cleaning, maintenance, inspections and staff scheduling.',
                'permissions' => [
                    'properties.view', 'units.view', 'listings.view',
                    'guests.view',
                    'reservations.view', 'reservations.checkin', 'calendar.view',
                    'tasks.*', 'checklists.manage', 'inspections.manage', 'vendors.manage',
                    'messages.view', 'messages.send',
                    'users.view',
                    'expenses.manage',
                    'reports.view', 'reports.export', 'analytics.view',
                    'documents.view', 'documents.manage',
                    'locks.view', 'locks.manage',
                ],
                'portal' => 'admin',
            ],

            self::RESERVATIONS_AGENT => [
                'name' => 'Reservations Agent',
                'description' => 'Handles bookings, guest communication and reservation changes.',
                'permissions' => [
                    'properties.view', 'units.view', 'listings.view',
                    'guests.view', 'guests.create', 'guests.update',
                    'reservations.view', 'reservations.create', 'reservations.update',
                    'reservations.cancel', 'reservations.checkin',
                    'calendar.view', 'calendar.update',
                    'pricing.view',
                    'messages.view', 'messages.send',
                    'payments.view', 'payments.charge',
                    'reviews.view',
                    'tasks.view', 'tasks.create',
                    'upsells.manage',
                    'reports.view',
                ],
                'portal' => 'admin',
            ],

            self::ACCOUNTANT => [
                'name' => 'Accountant',
                'description' => 'Owns the ledger, invoicing, payouts and owner statements.',
                'permissions' => [
                    'organization.view',
                    'properties.view', 'units.view', 'listings.view',
                    'guests.view', 'owners.view', 'owners.update',
                    'reservations.view', 'reservations.export',
                    'financials.*', 'payments.*', 'expenses.manage', 'invoices.manage',
                    'ledger.view', 'ledger.post', 'taxes.manage',
                    'owner_statements.*', 'owner_payouts.manage',
                    'reports.*', 'analytics.view',
                    'documents.view', 'documents.manage',
                    'imports.manage',
                ],
                'portal' => 'admin',
            ],

            self::OWNER => [
                'name' => 'Owner',
                'description' => 'Property owner with read access to their own properties and statements.',
                'permissions' => [
                    'properties.view',
                    'reservations.view',
                    'calendar.view',
                    'owner_statements.view',
                    'financials.view',
                    'reports.view',
                    'documents.view',
                    'reviews.view',
                    'messages.view', 'messages.send',
                ],
                'portal' => 'owner',
            ],

            self::CLEANER => [
                'name' => 'Cleaner',
                'description' => 'Executes cleaning tasks assigned to them.',
                'permissions' => [
                    'properties.view',
                    'units.view',
                    'tasks.view_own', 'tasks.update', 'tasks.complete',
                    'calendar.view',
                    'documents.view',
                ],
                'portal' => 'staff',
            ],

            self::MAINTENANCE => [
                'name' => 'Maintenance',
                'description' => 'Executes maintenance tickets assigned to them.',
                'permissions' => [
                    'properties.view',
                    'units.view',
                    'tasks.view_own', 'tasks.create', 'tasks.update', 'tasks.complete',
                    'calendar.view',
                    'documents.view',
                ],
                'portal' => 'staff',
            ],

            self::STAFF => [
                'name' => 'Staff',
                'description' => 'General staff member with basic operational access.',
                'permissions' => [
                    'properties.view', 'units.view', 'listings.view',
                    'guests.view',
                    'reservations.view', 'calendar.view',
                    'tasks.view', 'tasks.view_own', 'tasks.update', 'tasks.complete',
                    'messages.view',
                    'documents.view',
                ],
                'portal' => 'admin',
            ],

            self::READ_ONLY => [
                'name' => 'Read Only',
                'description' => 'Can see operational data but cannot change anything.',
                'permissions' => [
                    'organization.view',
                    'portfolios.view', 'properties.view', 'units.view', 'listings.view',
                    'guests.view', 'owners.view',
                    'reservations.view', 'calendar.view',
                    'pricing.view', 'revenue.view',
                    'tasks.view',
                    'messages.view',
                    'channels.view',
                    'financials.view', 'payments.view', 'ledger.view',
                    'owner_statements.view',
                    'reviews.view',
                    'reports.view', 'analytics.view',
                    'documents.view',
                    'integrations.view', 'locks.view',
                    'audit.view',
                ],
                'portal' => 'admin',
            ],
        ];
    }

    /**
     * Resolve a role definition's permission list into concrete permission
     * names, expanding `*` and `prefix.*` wildcards.
     *
     * @return list<string>
     */
    public static function permissionsFor(string $slug): array
    {
        $definition = self::definitions()[$slug] ?? null;

        if ($definition === null) {
            return [];
        }

        return self::expand($definition['permissions']);
    }

    /**
     * @param  string|list<string>  $patterns
     * @return list<string>
     */
    public static function expand(string|array $patterns): array
    {
        $catalogue = PermissionRegistry::all();

        if ($patterns === '*') {
            return $catalogue;
        }

        $resolved = [];

        foreach ((array) $patterns as $pattern) {
            if (! str_contains($pattern, '*')) {
                if (in_array($pattern, $catalogue, true)) {
                    $resolved[] = $pattern;
                }

                continue;
            }

            $prefix = rtrim(substr($pattern, 0, strpos($pattern, '*')), '.');

            foreach ($catalogue as $permission) {
                if (str_starts_with($permission, $prefix.'.')) {
                    $resolved[] = $permission;
                }
            }
        }

        return array_values(array_unique($resolved));
    }
}
