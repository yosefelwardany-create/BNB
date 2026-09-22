<?php

declare(strict_types=1);
use App\Domain\Accounting\Models\Expense;
use App\Domain\Accounting\Models\Invoice;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Api\Models\ApiKey;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Automation\Models\AutomationRule;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Availability\Models\CalendarBlock;
use App\Domain\Availability\Models\CalendarDay;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Models\SyncJob;
use App\Domain\Documents\Models\Document;
use App\Domain\Events\Models\DomainEvent;
use App\Domain\Guests\Models\Guest;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Integrations\Models\IntegrationConnection;
use App\Domain\Listings\Models\Listing;
use App\Domain\Listings\Models\ListingPhoto;
use App\Domain\Listings\Models\ListingVersion;
use App\Domain\Locks\Models\AccessCode;
use App\Domain\Locks\Models\SmartLock;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Messaging\Models\SavedReply;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Operations\Models\ChecklistTemplate;
use App\Domain\Operations\Models\Task;
use App\Domain\Operations\Models\TaskChecklistItem;
use App\Domain\Operations\Models\TaskComment;
use App\Domain\Operations\Models\TaskPhoto;
use App\Domain\Operations\Models\TaskRecurrence;
use App\Domain\Operations\Models\Team;
use App\Domain\Operations\Models\Vendor;
use App\Domain\Organization\Models\Organization;
use App\Domain\OwnerAccounting\Models\OwnerPayout;
use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\Owners\Models\ManagementAgreement;
use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Models\PropertyOwnership;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentSchedule;
use App\Domain\Payments\Models\Refund;
use App\Domain\Platform\Models\CustomField;
use App\Domain\Platform\Models\CustomFieldValue;
use App\Domain\Platform\Models\DocumentSequence;
use App\Domain\Platform\Models\IdempotencyKey;
use App\Domain\Platform\Models\Tag;
use App\Domain\Pricing\Models\FeeRule;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Pricing\Models\Quote;
use App\Domain\Pricing\Models\RatePlan;
use App\Domain\Pricing\Models\TaxRule;
use App\Domain\Properties\Models\Amenity;
use App\Domain\Properties\Models\CancellationPolicy;
use App\Domain\Properties\Models\Complex;
use App\Domain\Properties\Models\Portfolio;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyPhoto;
use App\Domain\Properties\Models\PropertyRoom;
use App\Domain\Properties\Models\Unit;
use App\Domain\Properties\Models\UnitType;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Models\ReservationCharge;
use App\Domain\Reservations\Models\ReservationGuest;
use App\Domain\Reservations\Models\ReservationNight;
use App\Domain\Reservations\Models\ReservationStatusChange;
use App\Domain\Reviews\Models\Review;
use App\Domain\Upsells\Models\UpsellOrder;
use App\Domain\Upsells\Models\UpsellProduct;
use App\Domain\Users\Models\Invitation;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\Permission;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Models\User;
use App\Domain\Webhooks\Models\WebhookDelivery;
use App\Domain\Webhooks\Models\WebhookEndpoint;
use App\Domain\Website\Models\WebsitePage;

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
    'organization' => Organization::class,
    'user' => User::class,
    'membership' => Membership::class,
    'role' => Role::class,
    'permission' => Permission::class,
    'invitation' => Invitation::class,

    // Platform
    'tag' => Tag::class,
    'custom_field' => CustomField::class,
    'custom_field_value' => CustomFieldValue::class,
    'domain_event' => DomainEvent::class,
    'audit_log' => AuditLog::class,
    'idempotency_key' => IdempotencyKey::class,

    // Properties
    'portfolio' => Portfolio::class,
    'property' => Property::class,
    'complex' => Complex::class,
    'unit' => Unit::class,
    'unit_type' => UnitType::class,
    'amenity' => Amenity::class,
    'property_photo' => PropertyPhoto::class,
    'property_room' => PropertyRoom::class,
    'cancellation_policy' => CancellationPolicy::class,

    // Listings
    'listing' => Listing::class,
    'listing_photo' => ListingPhoto::class,
    'listing_version' => ListingVersion::class,

    // People
    'guest' => Guest::class,
    'owner' => Owner::class,
    'management_agreement' => ManagementAgreement::class,

    // Reservations
    'reservation' => Reservation::class,
    'reservation_night' => ReservationNight::class,
    'reservation_charge' => ReservationCharge::class,
    'calendar_block' => CalendarBlock::class,

    // Pricing
    'rate_plan' => RatePlan::class,
    'pricing_rule' => PricingRule::class,
    'promotion' => Promotion::class,
    'tax_rule' => TaxRule::class,
    'fee_rule' => FeeRule::class,

    // Operations
    'task' => Task::class,
    'checklist_template' => ChecklistTemplate::class,
    'task_checklist_item' => TaskChecklistItem::class,
    'vendor' => Vendor::class,
    'team' => Team::class,
    'task_comment' => TaskComment::class,
    'task_photo' => TaskPhoto::class,
    'task_recurrence' => TaskRecurrence::class,

    // Messaging
    'conversation' => Conversation::class,
    'message' => Message::class,
    'message_template' => MessageTemplate::class,
    'automation_rule' => AutomationRule::class,
    'automation_run' => AutomationRun::class,
    'saved_reply' => SavedReply::class,
    'notification' => Notification::class,
    'calendar_day' => CalendarDay::class,
    'quote' => Quote::class,
    'property_ownership' => PropertyOwnership::class,
    'reservation_guest' => ReservationGuest::class,
    'reservation_status_change' => ReservationStatusChange::class,

    // Channels
    'channel_account' => ChannelAccount::class,
    'channel_listing' => ChannelListing::class,
    'sync_job' => SyncJob::class,

    // Finance
    'payment' => Payment::class,
    'refund' => Refund::class,
    'payment_schedule' => PaymentSchedule::class,
    'invoice' => Invoice::class,
    'expense' => Expense::class,
    'journal_entry' => JournalEntry::class,
    'journal_line' => JournalLine::class,
    'document_sequence' => DocumentSequence::class,
    'ledger_account' => LedgerAccount::class,
    'owner_statement' => OwnerStatement::class,
    'owner_payout' => OwnerPayout::class,

    // Reviews, documents & integrations
    'review' => Review::class,
    'document' => Document::class,
    'integration_connection' => IntegrationConnection::class,
    'webhook_endpoint' => WebhookEndpoint::class,
    'webhook_delivery' => WebhookDelivery::class,
    'api_key' => ApiKey::class,
    'smart_lock' => SmartLock::class,
    'access_code' => AccessCode::class,
    'upsell_product' => UpsellProduct::class,
    'upsell_order' => UpsellOrder::class,
    'import_batch' => ImportBatch::class,
    'saved_report' => SavedReport::class,
    'website_page' => WebsitePage::class,
];
