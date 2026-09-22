<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Evidence attached to a task.
 *
 * `stage` matters commercially: before-and-after photographs of a turnover are
 * what settle a damage dispute with a guest or an owner.
 */
class TaskPhoto extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'task_id', 'checklist_item_id',
        'disk', 'path', 'mime_type', 'size_bytes', 'caption', 'stage', 'uploaded_by_id',
    ];

    protected $attributes = ['disk' => 'local'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(TaskChecklistItem::class, 'checklist_item_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function url(int $minutes = 60): ?string
    {
        try {
            return Storage::disk($this->disk)->temporaryUrl($this->path, now()->addMinutes($minutes));
        } catch (\Throwable) {
            return route('api.v1.tasks.photos.show', ['photo' => $this->getKey()]);
        }
    }
}
