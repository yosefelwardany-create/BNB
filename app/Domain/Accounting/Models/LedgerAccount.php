<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\AccountType;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of the chart of accounts.
 *
 * @property string $code
 * @property AccountType $type
 * @property ?string $system_key
 */
class LedgerAccount extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'parent_id',
        'code',
        'name',
        'type',
        'system_key',
        'description',
        'currency',
        'is_active',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    protected $attributes = [
        'is_active' => true,
        'is_system' => false,
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'ledger_account_id');
    }

    public function scopeSystemKey(Builder $query, string $key): Builder
    {
        return $query->where('system_key', $key);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Whether a debit increases this account's balance.
     */
    public function debitIncreases(): bool
    {
        return $this->type->debitIncreases();
    }
}
