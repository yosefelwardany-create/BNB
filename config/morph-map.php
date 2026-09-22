<?php

declare(strict_types=1);

/**
 * Stable aliases for polymorphic relations.
 *
 * Polymorphic columns (audit_logs.auditable_type, documents.documentable_type,
 * conversations.subject_type, ...) store these short names rather than
 * fully-qualified class names. That keeps the data independent of the PHP
 * namespace layout, which matters because each domain here is designed so it
 * can later be extracted into its own service.
 *
 * Never rename a key without a data migration.
 */
return [
    // Organization & access
    'organization' => App\Domain\Organization\Models\Organization::class,
    'user' => App\Domain\Users\Models\User::class,
    'membership' => App\Domain\Users\Models\Membership::class,
    'role' => App\Domain\Users\Models\Role::class,
    'permission' => App\Domain\Users\Models\Permission::class,
    'invitation' => App\Domain\Users\Models\Invitation::class,

    // Platform
    'tag' => App\Domain\Platform\Models\Tag::class,
    'custom_field' => App\Domain\Platform\Models\CustomField::class,
    'custom_field_value' => App\Domain\Platform\Models\CustomFieldValue::class,
    'domain_event' => App\Domain\Events\Models\DomainEvent::class,
    'audit_log' => App\Domain\Audit\Models\AuditLog::class,
    'idempotency_key' => App\Domain\Platform\Models\IdempotencyKey::class,

    // Properties
    'portfolio' => App\Domain\Properties\Models\Portfolio::class,
    'property' => App\Domain\Properties\Models\Property::class,
    'complex' => App\Domain\Properties\Models\Complex::class,
    'unit' => App\Domain\Properties\Models\Unit::class,
    'unit_type' => App\Domain\Properties\Models\UnitType::class,
    'amenity' => App\Domain\Properties\Models\Amenity::class,
    'property_photo' => App\Domain\Properties\Models\PropertyPhoto::class,
    'property_room' => App\Domain\Properties\Models\PropertyRoom::class,
    'cancellation_policy' => App\Domain\Properties\Models\CancellationPolicy::class,

    // Listings
    'listing' => App\Domain\Listings\Models\Listing::class,
    'listing_photo' => App\Domain\Listings\Models\ListingPhoto::class,
    'listing_version' => App\Domain\Listings\Models\ListingVersion::class,

    // People
    'guest' => App\Domain\Guests\Models\Guest::class,
    'owner' => App\Domain\Owners\Models\Owner::class,
    'management_agreement' => App\Domain\Owners\Models\ManagementAgreement::class,

    // Reservations
    'reservation' => App\Domain\Reservations\Models\Reservation::class,
    'reservation_night' => App\Domain\Reservations\Models\ReservationNight::class,
    'reservation_charge' => App\Domain\Reservations\Models\ReservationCharge::class,
    'calendar_block' => App\Domain\Availability\Models\CalendarBlock::class,

    // Pricing
    'rate_plan' => App\Domain\Pricing\Models\RatePlan::class,
    'pricing_rule' => App\Domain\Pricing\Models\PricingRule::class,
    'promotion' => App\Domain\Pricing\Models\Promotion::class,
    'tax_rule' => App\Domain\Pricing\Models\TaxRule::class,
    'fee_rule' => App\Domain\Pricing\Models\FeeRule::class,

    // Operations
    'task' => App\Domain\Operations\Models\Task::class,
    'checklist_template' => App\Domain\Operations\Models\ChecklistTemplate::class,
    'task_checklist_item' => App\Domain\Operations\Models\TaskChecklistItem::class,
    'inspection' => App\Domain\Operations\Models\Inspection::class,
    'vendor' => App\Domain\Operations\Models\Vendor::class,
    'team' => App\Domain\Operations\Models\Team::class,

    // Messaging
    'conversation' => App\Domain\Messaging\Models\Conversation::class,
    'message' => App\Domain\Messaging\Models\Message::class,
    'message_template' => App\Domain\Messaging\Models\MessageTemplate::class,
    'automation_rule' => App\Domain\Automation\Models\AutomationRule::class,
    'automation_run' => App\Domain\Automation\Models\AutomationRun::class,

    // Channels
    'channel_account' => App\Domain\Channels\Models\ChannelAccount::class,
    'channel_listing' => App\Domain\Channels\Models\ChannelListing::class,
    'sync_job' => App\Domain\Channels\Models\SyncJob::class,

    // Finance
    'payment' => App\Domain\Payments\Models\Payment::class,
    'refund' => App\Domain\Payments\Models\Refund::class,
    'payment_schedule' => App\Domain\Payments\Models\PaymentSchedule::class,
    'invoice' => App\Domain\Accounting\Models\Invoice::class,
    'expense' => App\Domain\Accounting\Models\Expense::class,
    'journal_entry' => App\Domain\Accounting\Models\JournalEntry::class,
    'journal_line' => App\Domain\Accounting\Models\JournalLine::class,
    'document_sequence' => App\Domain\Platform\Models\DocumentSequence::class,
    'ledger_account' => App\Domain\Accounting\Models\LedgerAccount::class,
    'owner_statement' => App\Domain\OwnerAccounting\Models\OwnerStatement::class,
    'owner_payout' => App\Domain\OwnerAccounting\Models\OwnerPayout::class,

    // Reviews, documents & integrations
    'review' => App\Domain\Reviews\Models\Review::class,
    'document' => App\Domain\Documents\Models\Document::class,
    'integration_connection' => App\Domain\Integrations\Models\IntegrationConnection::class,
    'webhook_endpoint' => App\Domain\Webhooks\Models\WebhookEndpoint::class,
    'webhook_delivery' => App\Domain\Webhooks\Models\WebhookDelivery::class,
    'api_key' => App\Domain\Api\Models\ApiKey::class,
    'smart_lock' => App\Domain\Locks\Models\SmartLock::class,
    'access_code' => App\Domain\Locks\Models\AccessCode::class,
    'upsell_product' => App\Domain\Upsells\Models\UpsellProduct::class,
    'upsell_order' => App\Domain\Upsells\Models\UpsellOrder::class,
    'import_batch' => App\Domain\Imports\Models\ImportBatch::class,
    'saved_report' => App\Domain\Reports\Models\SavedReport::class,
    'website_page' => App\Domain\Website\Models\WebsitePage::class,
];
