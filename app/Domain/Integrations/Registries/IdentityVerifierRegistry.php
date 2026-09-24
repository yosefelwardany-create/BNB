<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Registries;

use App\Domain\Integrations\Contracts\IdentityVerifierInterface;
use App\Domain\Integrations\Providers\Identity\LocalIdentityVerifier;

/**
 * @extends ProviderRegistry<IdentityVerifierInterface>
 */
class IdentityVerifierRegistry extends ProviderRegistry
{
    protected function registerDefaults(): void
    {
        $this->register('local', LocalIdentityVerifier::class);
    }

    protected function defaultKey(): string
    {
        return (string) config('pms.providers.identity', 'local');
    }

    protected static function providerNoun(): string
    {
        return 'identity verifier';
    }
}
