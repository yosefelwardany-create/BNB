<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Integrations\Contracts\AIProviderInterface;
use App\Domain\Integrations\Contracts\LockProviderInterface;
use App\Domain\Integrations\Contracts\PaymentProviderInterface;
use App\Domain\Integrations\Registries\AIProviderRegistry;
use App\Domain\Integrations\Registries\ChannelAdapterRegistry;
use App\Domain\Integrations\Registries\LockProviderRegistry;
use App\Domain\Integrations\Registries\PaymentProviderRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the integration abstractions.
 *
 * Every external capability the product needs — taking a payment, driving a
 * smart lock, talking to an OTA, calling a language model — is expressed as an
 * interface with a registry of named implementations. The implementation used
 * is a per-organization configuration value, never a hard-coded class.
 *
 * The bundled `mock` implementations are genuine local implementations used in
 * development and tests. They are always reported to the user as what they are
 * and are never presented as a live connection to a third party.
 */
class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentProviderRegistry::class);
        $this->app->singleton(ChannelAdapterRegistry::class);
        $this->app->singleton(LockProviderRegistry::class);
        $this->app->singleton(AIProviderRegistry::class);

        // Resolving the bare interface yields the platform default, which is
        // what background jobs without an organization context use.
        $this->app->bind(
            PaymentProviderInterface::class,
            fn ($app) => $app->make(PaymentProviderRegistry::class)->default(),
        );

        $this->app->bind(
            LockProviderInterface::class,
            fn ($app) => $app->make(LockProviderRegistry::class)->default(),
        );

        $this->app->bind(
            AIProviderInterface::class,
            fn ($app) => $app->make(AIProviderRegistry::class)->default(),
        );
    }
}
