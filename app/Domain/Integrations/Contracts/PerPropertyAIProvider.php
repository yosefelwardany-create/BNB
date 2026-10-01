<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

use App\Domain\Properties\Models\Property;

/**
 * An AI provider whose configuration belongs to one property rather than to the
 * account.
 *
 * Claude is one endpoint and one key for the whole deployment, so it needs
 * nothing from here. A bot named after a property is the opposite: its URL and
 * its token are the property's, and an instance holding another property's
 * endpoint would answer the wrong guest from the wrong flat.
 *
 * `forProperty()` must return a **new** instance. The registry caches what it
 * resolves, so a provider that configured itself in place would leak one
 * property's endpoint into the next request that asked for the same key.
 */
interface PerPropertyAIProvider extends AIProviderInterface
{
    public function forProperty(Property $property): static;
}
