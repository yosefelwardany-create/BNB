<?php

declare(strict_types=1);

namespace App\Domain\Platform\Support;

/**
 * The features a plan can include.
 *
 * A registry rather than free-form strings, for the same reason permissions are
 * a registry: a feature key that only exists as a typo in one plan's JSON
 * silently grants nothing, and nobody discovers it until a customer asks why
 * they are paying for something that does not appear.
 *
 * Only things worth gating are here. A feature that every plan includes is not
 * a feature, it is the product, and adding it to this list would create a way
 * to accidentally take it away.
 */
final class PlanFeature
{
    public const CHANNELS = 'channels';

    public const OWNER_PORTAL = 'owner_portal';

    public const GUEST_PORTAL = 'guest_portal';

    public const API_ACCESS = 'api_access';

    public const WEBHOOKS = 'webhooks';

    public const AUTOMATION = 'automation';

    public const AI_DRAFTING = 'ai_drafting';

    public const SMART_LOCKS = 'smart_locks';

    public const UPSELLS = 'upsells';

    public const ADVANCED_REPORTING = 'advanced_reporting';

    public const MULTI_CURRENCY = 'multi_currency';

    public const REQUIRE_MFA = 'require_mfa';

    /**
     * Every feature, with a description written for whoever is choosing a plan
     * rather than for whoever implemented it.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            self::CHANNELS => 'Distribute listings to booking channels and import their reservations.',
            self::OWNER_PORTAL => 'Give owners their own login to see statements and performance.',
            self::GUEST_PORTAL => 'Send guests a link to check in online, message and pay a balance.',
            self::API_ACCESS => 'Issue API keys so other systems can read and write.',
            self::WEBHOOKS => 'Send events to an endpoint of your own as they happen.',
            self::AUTOMATION => 'Rules that act on events without somebody watching.',
            self::AI_DRAFTING => 'Draft replies for a person to review before sending.',
            self::SMART_LOCKS => 'Issue door codes for the length of a stay.',
            self::UPSELLS => 'Sell early check-in, transfers and extras.',
            self::ADVANCED_REPORTING => 'Saved reports, scheduled delivery and exports.',
            self::MULTI_CURRENCY => 'Trade in more than one currency and report in your own.',
            self::REQUIRE_MFA => 'Require every member of the organization to use two-factor authentication.',
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * The limits a plan can set, and what each one counts.
     *
     * Paired with the features here because the two are configured together and
     * enforced by the same service.
     *
     * @return array<string, string>
     */
    public static function limits(): array
    {
        return [
            'max_properties' => 'Properties',
            'max_units' => 'Units across all properties',
            'max_listings' => 'Published listings',
            'max_users' => 'People with a seat',
            'max_reservations_per_month' => 'Reservations created in a calendar month',
        ];
    }

    /**
     * @return list<string>
     */
    public static function limitKeys(): array
    {
        return array_keys(self::limits());
    }
}
