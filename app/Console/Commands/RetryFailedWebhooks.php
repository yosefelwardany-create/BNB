<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organization\Models\Organization;
use App\Domain\Webhooks\Services\WebhookDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Re-queues webhook attempts whose backoff has elapsed.
 *
 * The retry schedule lives in the delivery rows rather than in the queue,
 * which is what makes it survive a worker restart, a redeploy, or a queue
 * being drained. A backoff held only as a delayed job is lost the moment
 * anything clears the queue, and the events that go missing are exactly the
 * ones that failed.
 */
class RetryFailedWebhooks extends Command
{
    protected $signature = 'webhooks:retry-due {--organization= : Restrict to one organization}';

    protected $description = 'Re-queue webhook deliveries whose retry time has arrived';

    public function handle(WebhookDispatcher $dispatcher, TenantContext $tenancy): int
    {
        $organizations = $tenancy->withoutScope(function (): Collection {
            $query = Organization::query();

            if ($this->option('organization') !== null) {
                $query->whereKey($this->option('organization'));
            }

            return $query->get();
        });

        $retried = 0;

        foreach ($organizations as $organization) {
            try {
                $result = $tenancy->runAs($organization, fn (): array => $dispatcher->retryDue());
            } catch (\Throwable $exception) {
                // One tenant's broken endpoint must not stop every other
                // tenant's retries.
                $this->components->error(sprintf('%s: %s', $organization->name, $exception->getMessage()));

                continue;
            }

            $retried += $result['retried'];
        }

        $this->components->info(sprintf('Webhooks: %d deliveries re-queued.', $retried));

        return self::SUCCESS;
    }
}
