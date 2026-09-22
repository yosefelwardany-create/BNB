<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Registries;

use App\Domain\Integrations\Contracts\LockProviderInterface;
use App\Domain\Integrations\Providers\Locks\MockLockProvider;

/**
 * @extends ProviderRegistry<LockProviderInterface>
 */
class LockProviderRegistry extends ProviderRegistry
{
    protected function registerDefaults(): void
    {
        $this->register('mock', MockLockProvider::class);
    }

    protected function defaultKey(): string
    {
        return (string) config('pms.providers.locks', 'mock');
    }

    protected static function providerNoun(): string
    {
        return 'lock provider';
    }
}
