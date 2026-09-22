<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Registries;

use App\Domain\Integrations\Contracts\AIProviderInterface;
use App\Domain\Integrations\Providers\AI\EchoAIProvider;
use App\Domain\Integrations\Providers\AI\NullAIProvider;

/**
 * @extends ProviderRegistry<AIProviderInterface>
 */
class AIProviderRegistry extends ProviderRegistry
{
    protected function registerDefaults(): void
    {
        // `null` declines every request, which is the correct behaviour for an
        // organization that has not opted into AI assistance.
        $this->register('null', NullAIProvider::class);

        // `echo` produces deterministic local output so the feature can be
        // developed and tested without an external dependency.
        $this->register('echo', EchoAIProvider::class);
    }

    protected function defaultKey(): string
    {
        return (string) config('pms.providers.ai', 'null');
    }

    protected static function providerNoun(): string
    {
        return 'AI provider';
    }
}
