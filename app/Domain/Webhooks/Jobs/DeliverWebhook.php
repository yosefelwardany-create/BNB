<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Jobs;

use App\Domain\Organization\Models\Organization;
use App\Domain\Webhooks\Models\WebhookDelivery;
use App\Domain\Webhooks\Services\WebhookDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one recorded delivery attempt.
 *
 * `$tries` is 1 on purpose, and it is the most important line in this file.
 * Retries are the dispatcher's job, not the queue's: the dispatcher knows the
 * endpoint's own attempt limit, its backoff, and the difference between a
 * receiver that is down and one that has rejected the request. Letting the
 * queue retry as well would produce two independent retry schedules on top of
 * each other — the same event delivered a dozen times to an endpoint that
 * asked for six.
 *
 * The delivery id travels rather than the model, because by the time this runs
 * a serialised model could be hours stale.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** See the class docblock: the dispatcher owns retries, not the queue. */
    public int $tries = 1;

    public function __construct(
        public readonly string $deliveryId,
        public readonly ?string $organizationId = null,
    ) {
        $this->onQueue(config('pms.queues.webhooks', 'webhooks'));
    }

    public function handle(WebhookDispatcher $dispatcher, TenantContext $tenancy): void
    {
        $delivery = $tenancy->withoutScope(
            fn (): ?WebhookDelivery => WebhookDelivery::query()
                ->withoutGlobalScope('organization')
                ->with('endpoint')
                ->find($this->deliveryId),
        );

        if ($delivery === null || $delivery->status !== WebhookDelivery::PENDING) {
            // Already settled: this job was delivered twice, or a sweep sent
            // it first. Sending again would deliver the event twice.
            return;
        }

        $organization = $tenancy->withoutScope(
            fn (): ?Organization => Organization::query()->find($delivery->organization_id),
        );

        if ($organization === null) {
            return;
        }

        $tenancy->runAs($organization, fn () => $dispatcher->send($delivery));
    }

    /**
     * A job that died outright still settles its delivery, so a crashed worker
     * leaves a row that says what happened rather than one stuck on pending
     * forever.
     */
    public function failed(\Throwable $exception): void
    {
        WebhookDelivery::query()
            ->withoutGlobalScope('organization')
            ->whereKey($this->deliveryId)
            ->where('status', WebhookDelivery::PENDING)
            ->update([
                'status' => WebhookDelivery::FAILED,
                'error_message' => mb_substr($exception->getMessage(), 0, 250),
                'completed_at' => now(),
            ]);
    }
}
