<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Integrations\Registries\ProviderRegistry;
use App\Domain\Reports\Contracts\ReportDestinationInterface;
use App\Domain\Reports\Destinations\EmailDestination;
use App\Domain\Reports\Destinations\StoredFileDestination;
use App\Domain\Reports\Destinations\WebhookDestination;

/**
 * Where a finished report can go.
 *
 * @extends ProviderRegistry<ReportDestinationInterface>
 */
class ReportDestinationRegistry extends ProviderRegistry
{
    protected function registerDefaults(): void
    {
        // The one every schedule saved before destinations existed uses, and
        // the default for a new one.
        $this->register('email', EmailDestination::class);

        // Genuinely live: no partner agreement and no credential we do not
        // have. It is what makes a scheduled report useful to a system rather
        // than to a person.
        $this->register('webhook', WebhookDestination::class);

        // The answer to "what did this say in March?", which an email nobody
        // kept cannot give.
        $this->register('storage', StoredFileDestination::class);
    }

    protected function defaultKey(): string
    {
        return 'email';
    }

    protected static function providerNoun(): string
    {
        return 'report destination';
    }

    /**
     * The catalogue, for a form that offers them.
     *
     * @return list<array{key: string, name: string, description: string}>
     */
    public function catalogue(): array
    {
        $catalogue = [];

        foreach ($this->all() as $key => $destination) {
            $catalogue[] = [
                'key' => $key,
                'name' => $destination->displayName(),
                'description' => $destination->describe(),
            ];
        }

        return $catalogue;
    }
}
