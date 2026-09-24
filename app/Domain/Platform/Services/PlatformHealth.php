<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Integrations\Registries\AIProviderRegistry;
use App\Domain\Integrations\Registries\ChannelAdapterRegistry;
use App\Domain\Integrations\Registries\LockProviderRegistry;
use App\Domain\Integrations\Registries\MessageTransportRegistry;
use App\Domain\Integrations\Registries\PaymentProviderRegistry;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Whether the platform is working, across every tenant.
 *
 * The questions a platform operator actually has at nine in the morning: is
 * anything stuck, is anything failing, and is anything quietly pretending to
 * work. The last one is why the integration section exists — a queue with no
 * backlog and a channel adapter that has never spoken to a channel look
 * identical on every other dashboard.
 */
class PlatformHealth
{
    public function snapshot(): array
    {
        return [
            'queues' => $this->queues(),
            'webhooks' => $this->webhooks(),
            'channels' => $this->channels(),
            'integrations' => $this->integrations(),
            'database' => $this->database(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Work waiting and work that gave up.
     *
     * @return array<string, mixed>
     */
    private function queues(): array
    {
        // The jobs table exists whichever queue driver is configured, but it is
        // only populated by the database driver. Reported with the driver so a
        // depth of nought is not mistaken for "nothing pending" when the real
        // queue is Redis.
        $driver = config('queue.default');

        $failed = DB::table('failed_jobs')->count();

        return [
            'driver' => $driver,
            'database_depth' => $driver === 'database' ? DB::table('jobs')->count() : null,
            'failed_total' => $failed,
            'failed_last_24h' => DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->count(),
            'oldest_failure_at' => DB::table('failed_jobs')->min('failed_at'),
            'depth_visible' => $driver === 'database',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function webhooks(): array
    {
        $byStatus = DB::table('webhook_deliveries')
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($n): int => (int) $n);

        return [
            'window_hours' => 24,
            'by_status' => $byStatus->all(),
            'endpoints_unhealthy' => DB::table('webhook_endpoints')
                ->whereColumn('consecutive_failures', '>=', 'failure_threshold')
                ->count(),
            'endpoints_disabled' => DB::table('webhook_endpoints')
                ->where('status', 'disabled')
                ->count(),
            'retries_waiting' => DB::table('webhook_deliveries')
                ->whereNotNull('next_attempt_at')
                ->where('next_attempt_at', '<=', now())
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function channels(): array
    {
        $jobs = DB::table('sync_jobs')
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('status, count(*) as total, sum(case when is_simulated then 1 else 0 end) as simulated')
            ->groupBy('status')
            ->get();

        return [
            'window_hours' => 24,
            'jobs' => $jobs->mapWithKeys(fn (object $r): array => [
                $r->status => ['total' => (int) $r->total, 'simulated' => (int) $r->simulated],
            ])->all(),
            'accounts_errored' => DB::table('channel_accounts')->where('status', 'error')->count(),
            'listings_behind' => DB::table('channel_listings')
                ->where(fn ($q) => $q->where('availability_dirty', true)->orWhere('rates_dirty', true))
                ->count(),
        ];
    }

    /**
     * Which providers are real, platform-wide.
     *
     * The honesty surface for whoever runs the platform. Every other dashboard
     * in the product tells a tenant that *their* channel is simulated; this
     * tells the operator how much of the platform is.
     *
     * @return array<string, mixed>
     */
    private function integrations(): array
    {
        return [
            'payments' => $this->describeProvider(
                fn () => app(PaymentProviderRegistry::class)->default(),
            ),
            'messaging' => $this->describeProvider(
                fn () => app(MessageTransportRegistry::class)->default(),
            ),
            'locks' => $this->describeProvider(
                fn () => app(LockProviderRegistry::class)->default(),
            ),
            'ai' => $this->describeProvider(
                fn () => app(AIProviderRegistry::class)->default(),
            ),
            'channels' => $this->describeChannels(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeProvider(callable $resolve): array
    {
        try {
            $provider = $resolve();

            return [
                'provider' => $provider->key(),
                'name' => $provider->displayName(),
                'is_live' => $provider->isLive(),
                'simulation_reason' => $provider->isLive() ? null : $provider->simulationReason(),
            ];
        } catch (Throwable $exception) {
            // A misconfigured provider is a health problem, so it is reported
            // as one rather than turning this endpoint into a 500.
            return [
                'provider' => null,
                'name' => null,
                'is_live' => false,
                'simulation_reason' => 'This provider could not be resolved: '.$exception->getMessage(),
            ];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function describeChannels(): array
    {
        $registry = app(ChannelAdapterRegistry::class);
        $connected = DB::table('channel_accounts')
            ->where('status', 'connected')
            ->selectRaw('channel, count(*) as total')
            ->groupBy('channel')
            ->pluck('total', 'channel');

        $out = [];

        foreach (ChannelAdapterRegistry::KNOWN_CHANNELS as $key => $label) {
            try {
                $adapter = $registry->make($key);
                $live = $adapter->isLive();
                $reason = $live ? null : $adapter->simulationReason();
            } catch (Throwable $exception) {
                $live = false;
                $reason = 'This adapter could not be resolved: '.$exception->getMessage();
            }

            $out[] = [
                'channel' => $key,
                'name' => $label,
                'is_live' => $live,
                'simulation_reason' => $reason,
                // How much is riding on it. A simulated adapter with ninety
                // connections is a very different fact from one with none.
                'connected_accounts' => (int) ($connected[$key] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function database(): array
    {
        try {
            $size = DB::selectOne('select pg_database_size(current_database()) as bytes');

            return [
                'reachable' => true,
                'size_bytes' => (int) ($size->bytes ?? 0),
                'pending_migrations' => $this->pendingMigrations(),
            ];
        } catch (Throwable $exception) {
            return ['reachable' => false, 'error' => $exception->getMessage()];
        }
    }

    /**
     * Migrations on disk that this database has not run.
     *
     * Worth surfacing: a deploy that half-finished leaves the code expecting
     * columns the database does not have, and the symptom is a scatter of
     * unrelated 500s rather than anything that names the cause.
     */
    private function pendingMigrations(): int
    {
        $ran = DB::table('migrations')->pluck('migration')->all();
        $files = glob(database_path('migrations/*.php')) ?: [];

        $onDisk = array_map(
            static fn (string $path): string => basename($path, '.php'),
            $files,
        );

        return count(array_diff($onDisk, $ran));
    }
}
