<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a task's checklist.
 *
 * A failed item is the seed of follow-up work: an inspection that finds a
 * broken blind raises a maintenance ticket linked back to the item, so the
 * finding and the fix stay connected.
 */
class TaskChecklistItem extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const PENDING = 'pending';

    public const PASSED = 'passed';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $fillable = [
        'organization_id', 'task_id', 'section', 'label', 'position',
        'status', 'requires_photo', 'notes', 'severity',
        'completed_by_id', 'completed_at', 'follow_up_task_id',
    ];

    protected function casts(): array
    {
        return [
            'requires_photo' => 'boolean',
            'completed_at' => 'immutable_datetime',
        ];
    }

    protected $attributes = [
        'status' => self::PENDING,
        'requires_photo' => false,
        'position' => 0,
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }

    public function followUpTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'follow_up_task_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(TaskPhoto::class, 'checklist_item_id');
    }

    public function isDone(): bool
    {
        return $this->status !== self::PENDING;
    }

    /**
     * Whether the item can be marked done, given its photo requirement.
     */
    public function canComplete(): bool
    {
        if (! $this->requires_photo) {
            return true;
        }

        return $this->photos()->exists();
    }
}
