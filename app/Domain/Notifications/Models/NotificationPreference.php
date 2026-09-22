<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which channels a user wants a given notification type on.
 *
 * Absence of a row means the platform default applies, so a new notification
 * type does not require writing a preference row for every existing user.
 */
class NotificationPreference extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'user_id', 'notification_type',
        'in_app', 'email', 'sms', 'push',
    ];

    protected function casts(): array
    {
        return [
            'in_app' => 'boolean',
            'email' => 'boolean',
            'sms' => 'boolean',
            'push' => 'boolean',
        ];
    }

    protected $attributes = [
        'in_app' => true,
        'email' => true,
        'sms' => false,
        'push' => false,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The channels this preference enables.
     *
     * @return list<string>
     */
    public function enabledChannels(): array
    {
        return array_keys(array_filter([
            'in_app' => $this->in_app,
            'email' => $this->email,
            'sms' => $this->sms,
            'push' => $this->push,
        ]));
    }
}
