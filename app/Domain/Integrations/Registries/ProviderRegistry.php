<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Registries;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Base class for the named-implementation registries.
 *
 * Implementations register themselves by key and are resolved lazily, so an
 * organization that uses one payment processor never constructs the others,
 * and a new provider becomes available by registering it in a service
 * provider — nothing in the domain layer changes.
 *
 * @template TProvider of object
 */
abstract class ProviderRegistry
{
    /** @var array<string, class-string<TProvider>|callable(Container): TProvider> */
    protected array $providers = [];

    /** @var array<string, TProvider> */
    private array $resolved = [];

    public function __construct(protected readonly Container $container)
    {
        $this->registerDefaults();
    }

    /**
     * Register the implementations bundled with the platform.
     */
    abstract protected function registerDefaults(): void;

    /**
     * The key used when no organization-specific choice has been made.
     */
    abstract protected function defaultKey(): string;

    /**
     * @param  class-string<TProvider>|callable(Container): TProvider  $provider
     */
    public function register(string $key, string|callable $provider): static
    {
        $this->providers[$key] = $provider;

        return $this;
    }

    /**
     * @return TProvider
     */
    public function make(string $key): object
    {
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        if (! isset($this->providers[$key])) {
            throw new InvalidArgumentException(sprintf(
                'No %s is registered under the key [%s]. Available: %s.',
                static::providerNoun(),
                $key,
                implode(', ', $this->keys()) ?: 'none',
            ));
        }

        $factory = $this->providers[$key];

        return $this->resolved[$key] = is_callable($factory)
            ? $factory($this->container)
            : $this->container->make($factory);
    }

    /**
     * @return TProvider
     */
    public function default(): object
    {
        return $this->make($this->defaultKey());
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Every registered implementation, for settings screens that list the
     * available options.
     *
     * @return array<string, TProvider>
     */
    public function all(): array
    {
        $all = [];

        foreach ($this->keys() as $key) {
            $all[$key] = $this->make($key);
        }

        return $all;
    }

    protected static function providerNoun(): string
    {
        return 'provider';
    }
}
