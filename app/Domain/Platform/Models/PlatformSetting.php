<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use App\Domain\Users\Models\User;
use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Platform configuration a person can change without a deploy.
 *
 * Only settings that genuinely need changing at runtime live here. Anything
 * that belongs in `config/` and an environment variable stays there: a secret
 * in a database row that an admin console can display is a secret with a new
 * way to leak, and a value that only changes when the code changes has no
 * business being editable.
 *
 * The key is the primary key, so a setting cannot exist twice. This is one of
 * the few models in the codebase that is not a BaseModel — it has no ULID
 * because its name is its identity.
 */
class PlatformSetting extends Model
{
    use Auditable, HasUlids;

    protected $table = 'platform_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $fillable = ['key', 'value', 'description'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    /**
     * ULIDs are generated for models that need them; this one's key is its
     * name, so nothing is generated.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return [];
    }
}
