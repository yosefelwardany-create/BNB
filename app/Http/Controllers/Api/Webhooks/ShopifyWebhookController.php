<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Webhooks;

use App\Domain\Websites\Services\ShopifyPayments;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A client's Shopify store telling Habitat about its orders and the
 * platform's app.
 *
 * Nothing is believed until Shopify's signature over the raw body checks out
 * against the app's secret. Each delivery is acted on once: its id is
 * recorded in the same transaction as its effects, so a redelivery finds it
 * and does nothing, and a failure leaves no record and Shopify retries.
 */
class ShopifyWebhookController extends Controller
{
    public function __invoke(Request $request, ShopifyPayments $shopify): JsonResponse
    {
        $secret = (string) config('services.shopify.client_secret');
        $signature = (string) $request->header('X-Shopify-Hmac-Sha256', '');

        if ($secret === '' || $signature === '' || ! hash_equals(base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true)), $signature)) {
            return response()->json(['message' => 'The signature was not recognised.'], 401);
        }

        $topic = (string) $request->header('X-Shopify-Topic', '');
        $shop = strtolower((string) $request->header('X-Shopify-Shop-Domain', ''));
        $delivery = (string) ($request->header('X-Shopify-Webhook-Id') ?: $request->header('X-Shopify-Event-Id') ?: Str::ulid());
        $payload = json_decode($request->getContent(), true);

        DB::transaction(function () use ($topic, $shop, $delivery, $payload, $shopify): void {
            $first = DB::table('processed_provider_events')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'provider' => 'shopify',
                'event_id' => mb_substr($delivery, 0, 190),
                'type' => mb_substr($topic, 0, 190),
                'processed_at' => now(),
            ]);

            if ($first === 0) {
                return;
            }

            $shopify->handle($topic, $shop, is_array($payload) ? $payload : []);
        });

        return response()->json(['received' => true]);
    }
}
