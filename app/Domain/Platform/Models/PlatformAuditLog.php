<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Models\User;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing the platform operator did.
 *
 * Append-only: there is no update path and no delete path, because a log an
 * operator can edit is not a log. Not tenant-scoped — that is the entire reason
 * this table exists separately from `audit_logs`.
 */
class PlatformAuditLog extends BaseModel
{
    protected $table = 'platform_audit_logs';

    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_user_id', 'actor_email', 'action',
        'organization_id', 'organization_name',
        'subject_type', 'subject_id',
        'description', 'context',
        'ip_address', 'user_agent', 'request_id',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
