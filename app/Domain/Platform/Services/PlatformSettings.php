<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Platform\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Platform configuration that a person can change without a deploy.
 *
 * Deliberately a short, closed list. The temptation with a settings table is to
 * make everything editable, and the result is a console where somebody can
 * break the application by typing in a text box — so a key that is not declared
 * here cannot be written, and anything belonging in `config/` and an environment
 * variable stays there.
 *
 * No secrets. A credential in a row that an admin console can display is a
 * credential with an extra way to leak, and these values are readable by every
 * platform administrator.
 */
class PlatformSettings
{
    private const CACHE_KEY = 'platform.settings';

    /**
     * The settings that exist, with their type, default and what they do.
     *
     * @return array<string, array{type: string, default: mixed, description: string}>
     */
    public static function definitions(): array
    {
        return [
            'support_email' => [
                'type' => 'string',
                'default' => null,
                'description' => 'Shown to clients as the address to contact for help.',
            ],
            'require_mfa_for_platform_admins' => [
                'type' => 'boolean',
                // Off by default, deliberately, and it is not laziness. A fresh
                // install's first administrator has no second factor yet; with
                // this on they could never reach the console to turn it off, and
                // fixing it would mean editing the database by hand. The console
                // prompts to switch it on once somebody has enrolled.
                'default' => false,
                'description' => 'Require every platform owner to use two-factor '
                    .'authentication, on every client account and the administration screens. '
                    .'Switch this on once your owner accounts have enrolled — anyone without it '
                    .'loses access immediately.',
            ],
        ];
    }

    /**
     * Every setting with its current value, cached.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $stored = PlatformSetting::query()->pluck('value', 'key');
            $values = [];

            foreach (self::definitions() as $key => $definition) {
                $values[$key] = $stored->has($key)
                    // Stored as jsonb, so a scalar comes back wrapped. The
                    // wrapper is an implementation detail of the column, not
                    // part of the value.
                    ? $this->unwrap($stored[$key])
                    : $definition['default'];
            }

            return $values;
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (! array_key_exists($key, self::definitions())) {
            return $default;
        }

        return $this->all()[$key] ?? $default;
    }

    /**
     * Write several settings at once.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function put(array $values, ?string $userId = null): array
    {
        $definitions = self::definitions();

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $definitions)) {
                throw new HttpException(422, sprintf('There is no platform setting named "%s".', $key));
            }

            PlatformSetting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'value' => ['value' => $this->coerce($value, $definitions[$key]['type'], $key)],
                    'description' => $definitions[$key]['description'],
                    'updated_by_id' => $userId,
                ],
            );
        }

        Cache::forget(self::CACHE_KEY);

        app(PlatformAuditLogger::class)->record(
            action: 'platform.settings_changed',
            description: 'Changed '.implode(', ', array_keys($values)).'.',
            context: ['keys' => array_keys($values)],
        );

        return $this->all();
    }

    /**
     * The settings, their values, and what each one is for — the shape the
     * console renders a form from, so a new setting needs no frontend change.
     *
     * @return list<array<string, mixed>>
     */
    public function describe(): array
    {
        $values = $this->all();

        return collect(self::definitions())
            ->map(fn (array $definition, string $key): array => [
                'key' => $key,
                'type' => $definition['type'],
                'value' => $values[$key] ?? null,
                'default' => $definition['default'],
                'description' => $definition['description'],
            ])
            ->values()
            ->all();
    }

    private function unwrap(mixed $stored): mixed
    {
        if (is_array($stored) && array_key_exists('value', $stored)) {
            return $stored['value'];
        }

        // Written before the wrapper existed, or written by hand. Returned as
        // it is rather than discarded.
        return $stored;
    }

    private function coerce(mixed $value, string $type, string $key): mixed
    {
        return match ($type) {
            'boolean' => (bool) $value,
            'integer' => $this->requireInteger($value, $key),
            'string' => $value === null || $value === '' ? null : (string) $value,
            default => $value,
        };
    }

    private function requireInteger(mixed $value, string $key): int
    {
        if (! is_numeric($value)) {
            throw new HttpException(422, sprintf('"%s" must be a number.', $key));
        }

        return (int) $value;
    }
}
