<?php

declare(strict_types=1);

namespace App\Domain\Automation\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One execution of an automation rule.
 *
 * Every run is recorded, including the ones that decided to do nothing, with
 * the result of each condition. Automation raises exactly two questions —
 * "why did this guest get that message?" and "why didn't they?" — and neither
 * is answerable without this.
 */
class AutomationRun extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const PENDING = 'pending';

    public const SKIPPED = 'skipped';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $fillable = [
        'organization_id', 'automation_rule_id',
        'subject_type', 'subject_id', 'domain_event_id',
        'status', 'condition_results', 'action_results',
        'skip_reason', 'error',
        'scheduled_for', 'started_at', 'completed_at', 'attempts',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'condition_results' => 'array',
            'action_results' => 'array',
            'scheduled_for' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    protected $attributes = [
        'status' => self::PENDING,
        'attempts' => 0,
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', self::PENDING)
            ->where(fn (Builder $q) => $q->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now()));
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::FAILED);
    }

    public function isRetryable(): bool
    {
        return $this->status === self::FAILED && (int) $this->attempts < 5;
    }

    /**
     * A readable account of what happened, for the run log.
     */
    public function summary(): string
    {
        return match ($this->status) {
            self::SKIPPED => 'Skipped: '.($this->skip_reason ?? 'conditions were not met'),
            self::FAILED => 'Failed: '.($this->error ?? 'unknown error'),
            self::COMPLETED => sprintf('Completed %d action(s)', count($this->action_results ?? [])),
            self::RUNNING => 'Running',
            default => 'Waiting'.($this->scheduled_for !== null
                ? ' until '.$this->scheduled_for->toDayDateTimeString()
                : ''),
        };
    }
}
