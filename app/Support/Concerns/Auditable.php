<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Automatically writes audit entries when a model is created, updated or
 * deleted.
 *
 * Models that need finer control (reservations, statements, payments) call the
 * {@see AuditLogger} directly with a richer description instead of, or in
 * addition to, this trait.
 *
 * @phpstan-require-extends Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model): void {
            if ($model->auditingEnabled()) {
                app(AuditLogger::class)->created($model);
            }
        });

        static::updated(function ($model): void {
            if ($model->auditingEnabled()) {
                app(AuditLogger::class)->updated($model);
            }
        });

        static::deleted(function ($model): void {
            if ($model->auditingEnabled()) {
                app(AuditLogger::class)->deleted($model);
            }
        });
    }

    /**
     * Attributes whose changes are bookkeeping rather than anybody's decision.
     *
     * A model lists them in `$unaudited`. A save that changes only these
     * writes no audit row; a save that changes them alongside something real
     * records the real change only. The trail is for what people (and the
     * system on their behalf) decided, and a sync stamping "last pulled at"
     * every five minutes buried those decisions under thousands of rows.
     *
     * @return list<string>
     */
    public function unauditedAttributes(): array
    {
        return property_exists($this, 'unaudited') ? $this->unaudited : [];
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')->latest('created_at');
    }

    /**
     * Allows a model or an individual operation to opt out — used by bulk
     * imports, which write a single summary entry instead of thousands.
     */
    public function auditingEnabled(): bool
    {
        return ($this->auditingDisabled ?? false) === false;
    }

    /**
     * Run a callback with auditing suppressed on this instance.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutAuditing(\Closure $callback): mixed
    {
        $previous = $this->auditingDisabled ?? false;
        $this->auditingDisabled = true;

        try {
            return $callback();
        } finally {
            $this->auditingDisabled = $previous;
        }
    }
}
