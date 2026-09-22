<?php

declare(strict_types=1);

namespace App\Domain\Users\Models;

use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Authentication attempts, successful and failed.
 *
 * Kept separate from the audit log because it is written on unauthenticated
 * requests too and has a different retention profile.
 */
class LoginHistory extends BaseModel
{
    use HasFactory;

    protected $table = 'login_histories';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'organization_id',
        'email',
        'successful',
        'failure_reason',
        'ip_address',
        'user_agent',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
            'context' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
