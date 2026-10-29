<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sites;

use App\Domain\Integrations\Exceptions\StripeRequestException;
use App\Domain\Listings\Models\Listing;
use App\Domain\Pricing\Models\Quote;
use App\Domain\Websites\Exceptions\CheckoutRefused;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Domain\Websites\Services\WebsiteCatalogue;
use App\Domain\Websites\Services\WebsiteCheckoutService;
use App\Domain\Websites\Services\WebsitePayments;
use App\Domain\Websites\Support\SiteFormat;
use App\Domain\Websites\Support\SitePayments;
use App\Http\Controllers\Controller;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * A guest asking a site to hold the stay they were quoted.
 */
class SiteCheckoutController extends Controller
{
    public function __construct(
        private readonly WebsiteCatalogue $catalogue,
        private readonly WebsiteCheckoutService $checkouts,
        private readonly WebsitePayments $payments,
        private readonly TenantContext $tenancy,
    ) {}

    public function store(Request $request, string $site): JsonResponse
    {
        $website = $this->catalogue->live($site);

        // A hold with no way to pay for it only takes a home off the market.
        abort_unless(SitePayments::online(), 404);

        // Asked for by another website through its visitor's browser: each
        // such hold would close real nights in somebody else's name.
        abort_if($request->header('Sec-Fetch-Site') === 'cross-site', 403);

        $validated = $request->validate([
            'quote_id' => ['required', 'ulid'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['required', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:2000'],
            'accept_policies' => ['accepted'],
        ], [
            'accept_policies.accepted' => 'Please accept the house rules and cancellation policy.',
        ]);

        $quote = $this->tenancy->withoutScope(fn (): ?Quote => Quote::query()->find($validated['quote_id']));

        // Only a quote for one of this site's own listings can be held here.
        $listing = $quote === null
            ? null
            : $this->catalogue->listings($website)->first(fn (Listing $listing): bool => $listing->getKey() === $quote->listing_id);

        abort_if($quote === null || $listing === null, 404);

        try {
            $checkout = $this->checkouts->hold($website, $listing, $quote, $validated, $this->fingerprint($request));
        } catch (CheckoutRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], $refused->status);
        }

        $checkout->setRelation('website', $website);

        try {
            $clientSecret = $this->payments->startPayment($checkout);
        } catch (StripeRequestException $e) {
            // Nights held with no way to pay for them help nobody.
            Log::warning('A website payment could not be started; the hold was given back.', [
                'website_checkout_id' => $checkout->getKey(),
                'error' => $e->getMessage(),
            ]);
            $this->checkouts->release($checkout, WebsiteCheckout::STATUS_FAILED);

            return response()->json(['message' => 'The payment could not be started. Please try again in a moment.'], 503);
        }

        return response()->json([
            'status' => $checkout->status,
            'client_secret' => $clientSecret,
            'publishable_key' => (string) config('services.stripe.key'),
            'mode' => $checkout->mode,
            'booking_url' => $checkout->url(),
            'hold_expires_at' => $checkout->hold_expires_at->toIso8601String(),
            'total' => SiteFormat::money(Money::of($checkout->total_amount, $checkout->currency)),
            'due_now' => SiteFormat::money(Money::of($checkout->due_now_amount, $checkout->currency)),
        ], 201);
    }

    /**
     * A fresh payment for what the guest's saved card could not pay, asked
     * for from their booking link.
     */
    public function payBalance(Request $request, string $site, string $token): JsonResponse
    {
        $website = $this->catalogue->live($site);

        abort_unless(SitePayments::online(), 404);
        abort_if($request->header('Sec-Fetch-Site') === 'cross-site', 403);

        $checkout = WebsiteCheckout::query()
            ->where('website_id', $website->getKey())
            ->where('access_token', $token)
            ->first();

        abort_if($checkout === null, 404);
        $checkout->setRelation('website', $website);

        try {
            $clientSecret = $this->payments->startBalancePayment($checkout);
        } catch (CheckoutRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], $refused->status);
        } catch (StripeRequestException $e) {
            Log::warning('A website balance payment could not be started.', [
                'website_checkout_id' => $checkout->getKey(),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'The payment could not be started. Please try again in a moment.'], 503);
        }

        return response()->json([
            'client_secret' => $clientSecret,
            'publishable_key' => (string) config('services.stripe.key'),
            'return_url' => $checkout->url(),
        ]);
    }

    /**
     * Who is asking, as far as limiting holds goes: the visitor's network — the
     * address, or for IPv6 the /64 a household or phone is given — as a keyed
     * hash, so the address itself is never stored.
     *
     * The browser is deliberately not part of it. A script can send a new
     * browser name with every request, and would be a new visitor each time.
     */
    private function fingerprint(Request $request): string
    {
        $address = (string) $request->ip();
        $packed = @inet_pton($address);

        $network = $packed !== false && strlen($packed) === 16
            ? bin2hex(substr($packed, 0, 8)).'::/64'
            : $address;

        return hash_hmac('sha256', 'site-visitor|'.$network, (string) config('app.key'));
    }
}
