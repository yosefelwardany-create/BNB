<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Registries;

use App\Domain\Integrations\Contracts\PaymentProviderInterface;
use App\Domain\Integrations\Providers\Payments\MockPaymentProvider;

/**
 * @extends ProviderRegistry<PaymentProviderInterface>
 */
class PaymentProviderRegistry extends ProviderRegistry
{
    protected function registerDefaults(): void
    {
        // A complete, working local processor: it authorises, captures,
        // refunds and emits webhooks, and it is always presented in the
        // interface as a simulated processor rather than a live connection.
        $this->register('mock', MockPaymentProvider::class);
    }

    protected function defaultKey(): string
    {
        return (string) config('pms.providers.payments', 'mock');
    }

    protected static function providerNoun(): string
    {
        return 'payment provider';
    }
}
