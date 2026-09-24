<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Registries;

use App\Domain\Integrations\Contracts\ExchangeRateProviderInterface;
use App\Domain\Integrations\Providers\ExchangeRates\StoredRateProvider;

/**
 * @extends ProviderRegistry<ExchangeRateProviderInterface>
 */
class ExchangeRateProviderRegistry extends ProviderRegistry
{
    protected function registerDefaults(): void
    {
        $this->register('stored', StoredRateProvider::class);
    }

    protected function defaultKey(): string
    {
        return (string) config('pms.providers.exchange_rates', 'stored');
    }

    protected static function providerNoun(): string
    {
        return 'exchange rate provider';
    }
}
