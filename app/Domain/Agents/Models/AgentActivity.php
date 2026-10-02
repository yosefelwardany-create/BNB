<?php

declare(strict_types=1);

namespace App\Domain\Agents\Models;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a property's agent did.
 *
 * Separate from `audit_logs`, which records what people changed. This records
 * what the agent did: what it was asked, what it answered, and — the column that
 * matters — whether that answer could have gone anywhere without a person
 * reading it.
 *
 * `is_autonomous` is never inferred from the others. "The agent replied" and
 * "the agent drafted something a person then sent" are different events, and the
 * entire safety story of this feature is about which of the two happened; a
 * reader who has to work it out from three other columns will eventually work it
 * out wrong.
 *
 * Nothing here is ever rewritten. An activity row is what happened, and a
 * correction is a new row.
 */
class AgentActivity extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    /** A question went out to the agent. */
    public const KIND_ASKED = 'asked';

    /** It came back with something. */
    public const KIND_ANSWERED = 'answered';

    /** It produced a draft for a person to read. */
    public const KIND_DRAFTED = 'drafted';

    /** A gate stopped an answer from going on its own. */
    public const KIND_HELD = 'held';

    /** It could not answer, or could not be reached. */
    /**
     * The agent changed something, rather than saying something.
     *
     * Kept apart from `answered` because they are the two questions somebody
     * scanning this log is asking separately: what has it been telling guests,
     * and what has it been doing to my calendar. One list holding both answers
     * neither.
     */
    public const KIND_ACTED = 'acted';

    public const KIND_FAILED = 'failed';

    /** Nobody answered in time. */
    public const KIND_EXPIRED = 'expired';

    protected $fillable = [
        'organization_id', 'property_id', 'agent_ask_id', 'reservation_id',
        'conversation_id', 'actor_id', 'kind', 'summary', 'detail',
        'agent_name', 'is_autonomous', 'occurred_at',
    ];

    protected $attributes = [
        'is_autonomous' => false,
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'is_autonomous' => 'boolean',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function ask(): BelongsTo
    {
        return $this->belongsTo(AgentAsk::class, 'agent_ask_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Only the things the agent did without being watched.
     *
     * The list somebody reads when they want to know what happened while they
     * were asleep.
     */
    public function scopeAutonomous(Builder $query): Builder
    {
        return $query->where('is_autonomous', true);
    }

    /**
     * Write one down.
     *
     * A named constructor rather than `create()` at each call site, because
     * `occurred_at` must be set every time and a nullable timestamp that is
     * sometimes forgotten makes the whole log unsortable.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function record(array $attributes): self
    {
        return self::create($attributes + ['occurred_at' => CarbonImmutable::now()]);
    }
}
