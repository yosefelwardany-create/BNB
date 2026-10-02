<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Webhooks;

use App\Domain\Channels\Jobs\ProcessChannelWebhookEvent;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelWebhookEvent;
use App\Domain\Integrations\Registries\ChannelAdapterRegistry;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Where a channel tells us something changed.
 *
 * Unauthenticated in the usual sense — there is no session and no API key,
 * because the caller is Hostex rather than a person. The account is named in the
 * URL and the request is **verified by the adapter**, each channel signing
 * differently, which is why there is no shared middleware doing it.
 *
 * Three things this does and nothing more:
 *
 *  1. **Verify.** An unverifiable request is discarded without being recorded.
 *     Writing it down first would let anybody who found the URL fill the table.
 *  2. **Record.** Verified events are written exactly as they arrived, before
 *     anything is interpreted, so a payload we misread is still inspectable.
 *  3. **Answer immediately.** The work happens on a queue. A sender treats a
 *     slow response as a failure and retries, so doing the work inline turns one
 *     booking into several.
 *
 * It always answers 200 to a verified event, including one it has seen before.
 * A duplicate is the sender doing its job, not an error, and telling it
 * otherwise invites exactly the retry storm the de-duplication exists to absorb.
 */
class ChannelWebhookController extends Controller
{
    public function __construct(
        private readonly ChannelAdapterRegistry $adapters,
        private readonly TenantContext $tenancy,
    ) {}

    public function __invoke(Request $request, string $account): JsonResponse
    {
        $connection = $this->tenancy->withoutScope(
            fn (): ?ChannelAccount => ChannelAccount::query()
                ->withoutGlobalScope('organization')
                ->whereKey($account)
                ->first(),
        );

        if ($connection === null) {
            // The same answer as a failed signature, deliberately: telling a
            // caller which account ids exist is telling them what to guess at.
            return response()->json(['message' => 'Not acceptable.'], 404);
        }

        $envelope = $this->adapters
            ->make($connection->channel)
            ->parseWebhook($connection, $request->getContent(), $request->headers->all());

        if ($envelope === null) {
            return response()->json(['message' => 'Not acceptable.'], 404);
        }

        $event = $this->tenancy->withoutScope(function () use ($connection, $envelope): ?ChannelWebhookEvent {
            /*
             * Recorded once, under two deliveries arriving at the same moment.
             *
             * The unique index is what actually makes that safe — a check
             * followed by an insert races — so the insert runs in its own
             * transaction and a violation rolls back only that. Postgres aborts
             * a whole transaction on a failed statement, so catching the
             * violation outside a nested one would poison everything after it.
             */
            try {
                return DB::transaction(fn (): ChannelWebhookEvent => ChannelWebhookEvent::create([
                    'organization_id' => $connection->organization_id,
                    'channel_account_id' => $connection->getKey(),
                    'type' => $envelope->type,
                    'provider_event_id' => $envelope->providerEventId,
                    'payload' => $envelope->data,
                    'received_at' => $envelope->occurredAt === null
                        ? CarbonImmutable::now()
                        : CarbonImmutable::instance($envelope->occurredAt),
                ]));
            } catch (UniqueConstraintViolationException) {
                return null;
            }
        });

        if ($event === null) {
            // Seen before. A redelivery is the sender doing its job, so it is
            // answered plainly rather than as an error.
            return response()->json(['status' => 'duplicate'], 200);
        }

        ProcessChannelWebhookEvent::dispatch($event->getKey());

        return response()->json(['status' => 'accepted'], 202);
    }
}
