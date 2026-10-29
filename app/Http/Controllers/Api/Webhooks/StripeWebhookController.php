<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Webhooks;

use App\Domain\Integrations\Registries\PaymentProviderRegistry;
use App\Domain\Websites\Services\WebsitePayments;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stripe telling Habitat what happened to a payment.
 *
 * Nothing is trusted until the signature checks out against the endpoint's
 * secret; an unsigned or stale request is refused without being recorded.
 * Each event is handled once: its id is recorded in the same transaction as
 * its effects, so a redelivery finds it and does nothing, and a failure
 * leaves no record and is retried by Stripe.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentProviderRegistry $providers, WebsitePayments $payments): JsonResponse
    {
        $secret = (string) config('services.stripe.webhook_secret');

        $event = $secret === ''
            ? null
            : $providers->make('stripe')->parseWebhook($request->getContent(), $request->headers->all(), $secret);

        if ($event === null) {
            return response()->json(['message' => 'The signature was not recognised.'], 400);
        }

        DB::transaction(function () use ($event, $payments): void {
            $first = DB::table('processed_provider_events')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'provider' => 'stripe',
                'event_id' => $event->providerEventId,
                'type' => $event->type,
                'processed_at' => now(),
            ]);

            if ($first === 0) {
                return;
            }

            $payments->handle($event);
        });

        return response()->json(['received' => true]);
    }
}
