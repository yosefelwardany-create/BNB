<?php

declare(strict_types=1);

namespace App\Domain\Owners\Listeners;

use App\Domain\Owners\Services\ClientAccounts;
use App\Domain\Properties\Events\PropertyCreated;
use Illuminate\Support\Facades\Log;

/**
 * A new property belongs to the client account holder unless somebody says
 * otherwise.
 *
 * Synchronous, so the ownership row is written in the same transaction as the
 * property. Wrapped, so that a problem attributing a property can never fail
 * creating it: the backfill command repairs anything this misses.
 */
class AttachPropertyToClientAccount
{
    public function __construct(private readonly ClientAccounts $clients) {}

    public function handle(PropertyCreated $event): void
    {
        try {
            $this->clients->attachProperty($event->property);
        } catch (\Throwable $exception) {
            Log::warning('Could not attribute a new property to the client account holder.', [
                'property_id' => $event->property->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
