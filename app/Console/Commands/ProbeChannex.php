<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Integrations\Exceptions\ChannexRequestException;
use App\Domain\Integrations\Providers\Channels\ChannexChannelAdapter;
use App\Domain\Integrations\Support\ChannexClient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Asks a real Channex account what its data actually looks like.
 *
 * The adapter was written from Channex's published documentation, which is
 * better than guessing and still not the same as the thing itself. Documented
 * shapes go stale, optional fields turn out to be absent, and an id that reads
 * like a UUID in an example is occasionally something else.
 *
 * So this prints what the account really returns — the keys on each record and
 * the three identifiers a push needs — before anybody runs a certification
 * scenario and wonders why a 422 came back. Read-only throughout; it changes
 * nothing on either side.
 *
 * Works before any connection exists in the product. First contact is the
 * point: pass a key through CHANNEX_STAGING_API_KEY and it will talk to
 * staging without a `channel_accounts` row, which is the state you are in the
 * day the credentials arrive.
 */
class ProbeChannex extends Command
{
    protected $signature = 'channex:probe
        {--account= : A connected Channex account, when more than one exists}
        {--property= : Restrict the room type and rate plan listing to one property}';

    protected $description = 'Read a Channex account and report what its API returns.';

    public function handle(TenantContext $tenancy): int
    {
        $client = $this->client($tenancy);

        if ($client === null) {
            return self::FAILURE;
        }

        $this->components->info('Reading the Channex catalogue. Nothing is written.');

        $filter = $this->option('property') === null
            ? []
            : ['filter' => ['property_id' => $this->option('property')]];

        foreach ([
            'properties' => [],
            'room_types' => $filter,
            'rate_plans' => $filter,
        ] as $resource => $query) {
            if (! $this->report($client, $resource, $query)) {
                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->components->info(
            'A mapping needs three of these: the room type id as external_listing_id, '
            .'and the property id and rate plan id under metadata.channex.'
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function report(ChannexClient $client, string $resource, array $query): bool
    {
        try {
            $body = $client->get($resource, $query + ['pagination' => ['limit' => 10]]);
        } catch (ChannexRequestException $e) {
            $this->newLine();
            $this->error(sprintf('%s could not be read: %s', $resource, $e->getMessage()));

            // A bad key fails every resource the same way, so there is no
            // point walking the rest of the catalogue to say so three times.
            return ! $e->isRateLimit() && $e->errorCode !== 401;
        }

        $rows = is_array($body['data'] ?? null) ? $body['data'] : [];
        $total = $body['meta']['total'] ?? null;

        $this->newLine();
        $this->line(sprintf(
            '<fg=cyan>%s</> — %d returned%s',
            $resource,
            count($rows),
            is_int($total) ? sprintf(' of %d', $total) : '',
        ));

        if ($rows === []) {
            $this->line('  <fg=yellow>nothing yet; create it in the Channex admin first</>');

            return true;
        }

        // Read exactly as the adapter reads them, so what is printed here is
        // what an import will see — parents included, which Channex returns
        // under `relationships` rather than among the attributes.
        foreach (ChannexClient::records($body) as $record) {
            $this->line(sprintf(
                '  %s  %s',
                $record['id'] ?? '(no id)',
                $record['title'] ?? $record['name'] ?? '(untitled)',
            ));

            // The parents, which are what make a mapping addressable and are
            // the thing most likely to be missing when a push is refused;
            // then the settings that decide what a push may contain.
            foreach (['property_id', 'room_type_id', 'currency', 'min_stay_type', 'sell_mode', 'rate_mode'] as $key) {
                if (is_scalar($record[$key] ?? null)) {
                    $this->line(sprintf('      %-14s %s', $key, (string) $record[$key]));
                }
            }
        }

        // The field names themselves, since this is what the adapter reads by.
        $first = is_array($rows[0]) ? $rows[0] : [];
        $attributes = is_array($first['attributes'] ?? null) ? $first['attributes'] : [];
        $relationships = is_array($first['relationships'] ?? null) ? $first['relationships'] : [];

        if ($attributes !== []) {
            $this->line('  <fg=gray>keys: '.implode(', ', array_keys($attributes)).'</>');
        }

        if ($relationships !== []) {
            $this->line('  <fg=gray>relationships: '.implode(', ', array_keys($relationships)).'</>');
        }

        return true;
    }

    /**
     * A client built from a connected account, or from a key in the
     * environment when no connection exists yet.
     */
    private function client(TenantContext $tenancy): ?ChannexClient
    {
        $accounts = $tenancy->withoutScope(fn () => ChannelAccount::query()
            ->withoutGlobalScope('organization')
            ->where('channel', 'channex')
            ->when($this->option('account'), fn ($query, $id) => $query->whereKey($id))
            ->with('organization')
            ->get());

        if ($accounts->count() > 1) {
            /*
             * Several companies share this deployment, each with its own
             * Channex account and its own properties. Picking the first would
             * print one customer's inventory to whoever ran the command, so an
             * ambiguous call is refused and the choices are named.
             */
            $this->error('More than one Channex connection exists. Name the one to probe with --account=');
            $this->newLine();

            foreach ($accounts as $candidate) {
                $this->line(sprintf(
                    '  %s  %s (%s)',
                    $candidate->getKey(),
                    $candidate->name,
                    $candidate->organization?->name ?? 'unknown company',
                ));
            }

            return null;
        }

        $account = $accounts->first();

        if ($account !== null) {
            $key = ChannexChannelAdapter::apiKey($account);

            if ($key === '') {
                $this->error('That Channex connection has no API key stored.');

                return null;
            }

            $this->line(sprintf('Using the connection <fg=cyan>%s</>.', $account->name));

            return new ChannexClient($key, $account->credential('base_url'));
        }

        // No connection in the product yet, which is the normal state on the
        // day staging credentials arrive.
        $key = (string) env('CHANNEX_STAGING_API_KEY', '');

        if ($key === '') {
            $this->error('No Channex connection found, and CHANNEX_STAGING_API_KEY is not set.');
            $this->newLine();
            $this->line('Either add a Channex connection under Channels, or put the staging key in');
            $this->line('the environment as CHANNEX_STAGING_API_KEY and run this again.');

            return null;
        }

        $this->line(sprintf(
            'No connection stored; using CHANNEX_STAGING_API_KEY against <fg=cyan>%s</>.',
            (string) config('pms.channels.channex.base_url'),
        ));

        return new ChannexClient($key);
    }
}
