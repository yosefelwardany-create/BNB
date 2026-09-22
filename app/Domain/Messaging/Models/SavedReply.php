<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A canned reply an agent inserts by hand.
 *
 * Distinct from a template: templates are what automation sends, saved replies
 * are what a person reaches for mid-conversation. Keeping them apart stops an
 * agent's shorthand from accidentally becoming an automated guest message.
 */
class SavedReply extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'name', 'shortcut', 'body', 'category', 'created_by_id',
    ];

    protected $attributes = ['times_used' => 0];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function recordUse(): void
    {
        $this->increment('times_used');
    }
}
