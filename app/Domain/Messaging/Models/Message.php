<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a thread.
 *
 * `is_ai_generated` and `approved_by_id` exist so a manager can always tell
 * what a person wrote and what a model drafted — and, where a draft was sent,
 * who approved it. Nothing generated is sent without that being recorded.
 */
class Message extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const INBOUND = 'inbound';

    public const OUTBOUND = 'outbound';

    public const INTERNAL = 'internal';

    protected $fillable = [
        'organization_id', 'conversation_id', 'direction', 'transport', 'channel',
        'body', 'body_html', 'subject',
        'author_type', 'user_id', 'author_name', 'is_internal_note',
        'status', 'sent_at', 'delivered_at', 'read_at', 'failed_at', 'failure_reason',
        'external_message_id', 'template_id', 'automation_rule_id',
        'is_ai_generated', 'ai_provider', 'approved_by_id',
        'language', 'attachments', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_internal_note' => 'boolean',
            'is_ai_generated' => 'boolean',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'attachments' => 'array',
            'metadata' => 'array',
        ];
    }

    protected $attributes = [
        'status' => 'sent',
        'is_internal_note' => false,
        'is_ai_generated' => false,
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'template_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function scopeDeliverable(Builder $query): Builder
    {
        return $query->where('is_internal_note', false)
            ->where('direction', self::OUTBOUND);
    }

    public function scopeFromGuest(Builder $query): Builder
    {
        return $query->where('direction', self::INBOUND);
    }

    public function isFromGuest(): bool
    {
        return $this->direction === self::INBOUND;
    }

    public function hasFailed(): bool
    {
        return in_array($this->status, ['failed', 'bounced'], true);
    }

    public function preview(int $length = 160): string
    {
        return \Illuminate\Support\Str::limit(trim(strip_tags($this->body)), $length);
    }
}
