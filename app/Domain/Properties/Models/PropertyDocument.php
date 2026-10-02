<?php

declare(strict_types=1);

namespace App\Domain\Properties\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document a property's agent reads before it answers.
 *
 * The house manual, the FAQ, whatever the people who know the place keep. Stored
 * as a snapshot with the time it was taken: the document lives wherever its
 * authors maintain it, and this is a copy that goes stale, which is why nothing
 * here is presented without a date.
 */
class PropertyDocument extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const KIND_KNOWLEDGE_BASE = 'knowledge_base';

    public const KIND_FAQ = 'faq';

    public const KIND_HOUSE_MANUAL = 'house_manual';

    /** Never fetched. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_OK = 'ok';

    /** The address did not answer. */
    public const STATUS_UNREACHABLE = 'unreachable';

    /** It answered, and said no — almost always a document that is not shared. */
    public const STATUS_FORBIDDEN = 'forbidden';

    /** Fetched, and there was nothing in it. */
    public const STATUS_EMPTY = 'empty';

    /** Habitat will not fetch this address. */
    public const STATUS_REFUSED = 'refused';

    protected $fillable = [
        'organization_id', 'property_id', 'kind', 'title', 'url',
        'content', 'content_bytes', 'was_truncated', 'content_hash',
        'is_guest_safe', 'status', 'failure', 'fetched_at', 'checked_at',
    ];

    protected $attributes = [
        'kind' => self::KIND_KNOWLEDGE_BASE,
        'status' => self::STATUS_PENDING,
        'is_guest_safe' => false,
        'was_truncated' => false,
        'content_bytes' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_guest_safe' => 'boolean',
            'was_truncated' => 'boolean',
            'content_bytes' => 'integer',
            'fetched_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Whether there is anything here worth putting in front of an agent.
     */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_OK
            && is_string($this->content)
            && trim($this->content) !== '';
    }

    /**
     * What to call this when the agent is told where its knowledge came from.
     */
    public function label(): string
    {
        return $this->title ?: match ($this->kind) {
            self::KIND_FAQ => 'Frequently asked questions',
            self::KIND_HOUSE_MANUAL => 'House manual',
            default => 'Knowledge base',
        };
    }
}
