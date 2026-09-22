<?php

declare(strict_types=1);

namespace App\Domain\Users\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A pending invitation for somebody to join an organization.
 *
 * Only the SHA-256 hash of the invitation token is stored, so a leaked
 * database backup cannot be used to accept invitations.
 *
 * @property string $email
 * @property ?Carbon $accepted_at
 */
class Invitation extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'email',
        'first_name',
        'last_name',
        'job_title',
        'token_hash',
        'invited_by_id',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'invitation_role')->withTimestamps();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
