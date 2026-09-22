<?php

declare(strict_types=1);

namespace App\Domain\Users\Support;

/**
 * The authoritative catalogue of permissions in the platform.
 *
 * Permissions are stored in the database so roles can reference them, but this
 * class is the source of truth: `php artisan permissions:sync` reconciles the
 * table against it. Adding a permission means adding it here.
 *
 * Naming convention: `<resource>.<action>`.
 */
final class PermissionRegistry
{
    public const VIEW = 'view';

    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const DELETE = 'delete';

    /**
     * Groups of permissions, keyed by the module they belong to.
     *
     * @return array<string, array<string, string>> module => [permission => description]
     */
    public static function groups(): array
    {
        return [
            'Organization' => [
                'organization.view' => 'View organization profile and settings',
                'organization.update' => 'Update organization profile and settings',
                'organization.billing' => 'Manage subscription and billing details',
            ],
            'Users & access' => [
                'users.view' => 'View team members',
                'users.invite' => 'Invite new team members',
                'users.update' => 'Update team members',
                'users.deactivate' => 'Deactivate or reactivate team members',
                'roles.view' => 'View roles and permissions',
                'roles.manage' => 'Create and modify roles',
                'audit.view' => 'View the audit log',
            ],
            'Portfolios & properties' => [
                'portfolios.view' => 'View portfolios',
                'portfolios.manage' => 'Create and modify portfolios',
                'properties.view' => 'View properties',
                'properties.create' => 'Create properties',
                'properties.update' => 'Update properties',
                'properties.delete' => 'Archive properties',
                'units.view' => 'View units',
                'units.create' => 'Create units',
                'units.update' => 'Update units',
                'units.delete' => 'Archive units',
                'listings.view' => 'View listings',
                'listings.create' => 'Create listings',
                'listings.update' => 'Update listings',
                'listings.delete' => 'Archive listings',
                'listings.publish' => 'Publish and unpublish listings',
            ],
            'Guests & owners' => [
                'guests.view' => 'View guest profiles',
                'guests.create' => 'Create guest profiles',
                'guests.update' => 'Update guest profiles',
                'guests.merge' => 'Merge duplicate guest profiles',
                'guests.export' => 'Export guest data',
                'owners.view' => 'View owners',
                'owners.create' => 'Create owners',
                'owners.update' => 'Update owners',
                'owners.portal' => 'Manage owner portal access',
            ],
            'Reservations' => [
                'reservations.view' => 'View reservations',
                'reservations.create' => 'Create reservations',
                'reservations.update' => 'Update reservations',
                'reservations.cancel' => 'Cancel reservations',
                'reservations.reinstate' => 'Reinstate cancelled reservations',
                'reservations.checkin' => 'Check guests in and out',
                'reservations.override_availability' => 'Override availability restrictions',
                'reservations.export' => 'Export reservation data',
                'calendar.view' => 'View the multi-calendar',
                'calendar.update' => 'Modify availability from the calendar',
            ],
            'Pricing & revenue' => [
                'pricing.view' => 'View rates and pricing rules',
                'pricing.update' => 'Update rates and pricing rules',
                'rate_plans.manage' => 'Manage rate plans',
                'promotions.manage' => 'Manage promotions and coupons',
                'revenue.view' => 'View revenue analytics',
            ],
            'Operations' => [
                'tasks.view' => 'View operational tasks',
                'tasks.create' => 'Create operational tasks',
                'tasks.update' => 'Update operational tasks',
                'tasks.assign' => 'Assign tasks to staff',
                'tasks.complete' => 'Complete tasks',
                'tasks.delete' => 'Cancel tasks',
                'tasks.view_own' => 'View tasks assigned to me',
                'checklists.manage' => 'Manage checklist templates',
                'inspections.manage' => 'Perform and manage inspections',
                'vendors.manage' => 'Manage vendors and technicians',
                // Team membership decides which work a person can see, so
                // editing teams is an access decision as well as a rota one.
                'teams.view' => 'View staff teams',
                'teams.manage' => 'Create and modify staff teams',
                'recurrences.manage' => 'Manage recurring work schedules',
            ],
            'Messaging' => [
                'messages.view' => 'View conversations',
                'messages.send' => 'Send messages',
                'messages.assign' => 'Assign conversations',
                'templates.manage' => 'Manage message templates',
                'automations.view' => 'View automation rules',
                'automations.manage' => 'Create and modify automation rules',
            ],
            'Channels' => [
                'channels.view' => 'View channel connections',
                'channels.manage' => 'Connect and configure channels',
                'channels.sync' => 'Trigger channel synchronisation',
                'channels.map' => 'Map listings to channel listings',
            ],
            'Financials' => [
                'financials.view' => 'View financial records',
                'financials.create' => 'Create financial records',
                'financials.update' => 'Update financial records',
                'financials.export' => 'Export financial data',
                'payments.view' => 'View payments',
                'payments.charge' => 'Capture payments and issue charges',
                'payments.refund' => 'Issue refunds',
                'expenses.manage' => 'Manage expenses and vendor bills',
                'invoices.manage' => 'Manage invoices',
                'ledger.view' => 'View the accounting ledger',
                'ledger.post' => 'Post manual journal entries',
                'taxes.manage' => 'Manage tax rules',
            ],
            'Owner accounting' => [
                'owner_statements.view' => 'View owner statements',
                'owner_statements.generate' => 'Generate owner statements',
                'owner_statements.approve' => 'Approve owner statements',
                'owner_statements.send' => 'Send owner statements',
                'owner_payouts.manage' => 'Record and manage owner payouts',
            ],
            'Reviews' => [
                'reviews.view' => 'View reviews',
                'reviews.respond' => 'Respond to reviews',
            ],
            'Reporting' => [
                'reports.view' => 'View reports',
                'reports.export' => 'Export reports',
                'reports.schedule' => 'Schedule recurring reports',
                'analytics.view' => 'View analytics dashboards',
            ],
            'Platform & integrations' => [
                'integrations.view' => 'View integrations',
                'integrations.manage' => 'Connect and configure integrations',
                'api_keys.manage' => 'Manage API keys',
                'webhooks.manage' => 'Manage outbound webhooks',
                'locks.view' => 'View smart locks and access codes',
                'locks.manage' => 'Issue and revoke access codes',
                'documents.view' => 'View documents',
                'documents.manage' => 'Upload and manage documents',
                'imports.manage' => 'Run data imports',
                'website.manage' => 'Manage the booking website',
                'upsells.manage' => 'Manage upsell products',
                'custom_fields.manage' => 'Manage custom fields and tags',
            ],
        ];
    }

    /**
     * Every permission name in the catalogue.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $names = [];

        foreach (self::groups() as $permissions) {
            foreach (array_keys($permissions) as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return array<string, string> permission => description
     */
    public static function flat(): array
    {
        return array_merge(...array_values(self::groups()));
    }

    /**
     * @return array<string, string> permission => module
     */
    public static function modules(): array
    {
        $map = [];

        foreach (self::groups() as $module => $permissions) {
            foreach (array_keys($permissions) as $name) {
                $map[$name] = $module;
            }
        }

        return $map;
    }

    public static function exists(string $permission): bool
    {
        return array_key_exists($permission, self::flat());
    }
}
