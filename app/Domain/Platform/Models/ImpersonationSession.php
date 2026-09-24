<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Models\User;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record that the platform operator looked inside a customer's account.
 *
 * This model exists so that the answer to "who read my data, when, and why" is
 * a row somebody can be shown, rather than a line in a log file that has to be
 * taken on trust. It is written before the session starts and closed when it
 * ends, and it is never deleted.
 *
 * Not auditable, deliberately: it *is* the audit record. Auditing it would only
 * produce a second copy of the same fact.
 */
class ImpersonationSession extends BaseModel
{
    public const ENDED_REVOKED = 'revoked';

    public const ENDED_EXPIRED = 'expired';

    public const ENDED_COMPLETED = 'completed';

    protected $table = 'impersonation_sessions';

    protected $fillable = [
        'platform_user_id', 'organization_id', 'target_user_id', 'reason',
        'access_token_id', 'started_at', 'expires_at', 'ended_at',
        'ended_reason', 'ip_address', 'user_agent', 'request_count',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'request_count' => 'integer',
            'access_token_id' => 'integer',
        ];
    }

    public function platformUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'platform_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /**
     * Still open and still inside its window.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at')->where('expires_at', '>', now());
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }
}
