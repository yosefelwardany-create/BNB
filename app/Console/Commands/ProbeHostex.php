<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Integrations\Exceptions\HostexRequestException;
use App\Domain\Integrations\Providers\Channels\HostexChannelAdapter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Asks a real Hostex account what its data actually looks like.
 *
 * This exists because the adapter was written without reach to Hostex's API
 * documentation — the egress policy of the machine it was built on blocks the
 * documentation host — so every field in it is read by name with fallbacks and
 * nothing assumes a key exists.
 *
 * Guessing is not a plan. This is: point it at a connected account, and it
 * prints the keys each endpoint really returns, so the mapping is corrected
 * against the thing itself rather than against a recollection of it. Read-only
 * throughout; it changes nothing on either side.
 *
 *     php artisan hostex:probe
 *     php artisan hostex:probe --account=01m3... --raw
 */
class ProbeHostex extends Command
{
    protected $signature = 'hostex:probe
        {--account= : A specific channel account, when more than one is connected}
        {--raw : Print the nested response structure with all values redacted}';

    protected $description = 'Read a connected Hostex account and report what its API returns.';

    public function handle(TenantContext $tenancy, HostexChannelAdapter $adapter): int
    {
        $accounts = $tenancy->withoutScope(fn () => ChannelAccount::query()
            ->withoutGlobalScope('organization')
            ->where('channel', 'hostex')
            ->when($this->option('account'), fn ($query, $id) => $query->whereKey($id))
            ->with('organization')
            ->get());

        if ($accounts->isEmpty()) {
            $this->error('No Hostex connection found. Add one under Channels first.');

            return self::FAILURE;
        }

        /*
         * Several companies share this deployment, each with its own Hostex and
         * its own properties. Picking the first would print one customer's
         * listings to whoever happened to run the command, so an ambiguous call
         * is refused and the choices are named.
         */
        if ($accounts->count() > 1) {
            $this->error('More than one Hostex connection exists. Name the one to probe with --account=');
            $this->newLine();

            foreach ($accounts as $candidate) {
                $this->line(sprintf(
                    '  %s  %s (%s)',
                    $candidate->getKey(),
                    $candidate->name,
                    $candidate->organization?->name ?? 'unknown company',
                ));
            }

            return self::FAILURE;
        }

        $account = $accounts->first();

        $this->line(sprintf('Probing <info>%s</info> (%s)', $account->name, $account->getKey()));
        $this->newLine();

        foreach ([
            'properties' => [],
            'reservations' => ['limit' => 2],
            'conversations' => ['limit' => 2],
            'reviews' => ['limit' => 2],
        ] as $path => $query) {
            $this->probe($adapter, $account, $path, $query);
        }

        $this->newLine();
        $this->line('Any key above that the adapter does not read is a field it is currently dropping.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function probe(
        HostexChannelAdapter $adapter,
        ChannelAccount $account,
        string $path,
        array $query,
    ): void {
        $this->line("<comment>GET /{$path}</comment>");

        try {
            $response = $adapter->client($account)->get($path, $query);
        } catch (HostexRequestException $e) {
            // Hostex's own words. A 401 here means the token; a 403 usually
            // means the plan does not include the Open API, and saying which
            // beats "the probe failed".
            $this->error('  '.$e->getMessage());
            $this->newLine();

            return;
        }

        $rows = $response[$path] ?? $response['data'] ?? $response;
        $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];

        if ($rows === []) {
            $this->line('  <fg=yellow>No rows.</> Top-level keys: '.$this->keys($response));
            $this->newLine();

            return;
        }

        $this->line(sprintf('  %d row(s). Keys on the first:', count($rows)));
        $this->line('  '.$this->keys($rows[0]));

        if ($this->option('raw')) {
            $this->line('  '.json_encode($this->redactedShape($rows[0]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $this->newLine();
    }

    /**
     * The shape of a row, one level deep.
     *
     * Nested objects are named with their own keys because that is where the
     * guest and the money live, and those are the two the mapping most needs to
     * get right.
     *
     * @param  array<string, mixed>  $row
     */
    private function keys(array $row): string
    {
        $described = [];

        foreach ($row as $key => $value) {
            $described[] = is_array($value) && $value !== [] && ! array_is_list($value)
                ? sprintf('%s{%s}', $key, implode(',', array_keys($value)))
                : (string) $key;
        }

        return implode(', ', $described);
    }

    private function redactedShape(array $row): array
    {
        return array_map(fn ($value) => is_array($value)
            ? $this->redactedShape(array_is_list($value) ? array_slice($value, 0, 1) : $value)
            : '['.get_debug_type($value).']', $row);
    }
}
