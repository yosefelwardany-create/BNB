<?php

declare(strict_types=1);

namespace App\Domain\Websites\Support;

use App\Domain\Listings\Models\Listing;
use App\Domain\Payments\Models\PaymentSchedule;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;

/**
 * A website booking as its emails describe it.
 *
 * Read from the checkout alone, outside any account: an email is rendered by
 * a queue worker that is signed in nowhere.
 */
final class BookingSummary
{
    /**
     * @return array<string, mixed>
     */
    public static function for(WebsiteCheckout $checkout): array
    {
        return app(TenantContext::class)->withoutScope(function () use ($checkout): array {
            $site = $checkout->website;
            $reservation = $checkout->reservation_id === null ? null : Reservation::query()->find($checkout->reservation_id);
            $listing = $checkout->listing_id === null ? null : Listing::query()->with('property')->find($checkout->listing_id);
            $timezone = $listing?->property?->timezone ?: config('app.timezone');
            $money = fn (int $minor): string => SiteFormat::money(Money::of($minor, $checkout->currency));

            $later = $reservation === null ? [] : PaymentSchedule::query()
                ->where('reservation_id', $reservation->getKey())
                ->whereIn('status', ['pending', 'partially_paid', 'failed', 'overdue'])
                ->orderBy('sequence')
                ->get()
                ->map(fn (PaymentSchedule $instalment): array => [
                    'amount' => $money($instalment->outstandingAmount()->minorUnits),
                    'due_on' => $instalment->due_on->format('j M Y'),
                ])
                ->all();

            return [
                'site' => $site?->name ?? 'our website',
                'site_url' => $site?->url() ?? url('/'),
                'contact_email' => $site?->setting('contact_email'),
                'contact_phone' => $site?->setting('contact_phone'),
                'first_name' => (string) ($checkout->guest['first_name'] ?? 'there'),
                'guest_name' => trim(($checkout->guest['first_name'] ?? '').' '.($checkout->guest['last_name'] ?? '')),
                'guest_email' => $checkout->guest['email'] ?? null,
                'guest_phone' => $checkout->guest['phone'] ?? null,
                'message' => $checkout->guest['message'] ?? null,
                'listing' => $listing?->displayTitle() ?? 'your stay',
                'reference' => $reservation?->confirmation_code,
                'check_in' => $reservation?->check_in_date?->format('D j M Y'),
                'check_out' => $reservation?->check_out_date?->format('D j M Y'),
                'nights' => (int) ($reservation?->nights ?? 0),
                'guests' => (int) (($checkout->guest['adults'] ?? 0) + ($checkout->guest['children'] ?? 0)),
                'total' => $money($checkout->total_amount),
                'due_now' => $money($checkout->due_now_amount),
                'later' => $later,
                'is_request' => $checkout->mode === WebsiteCheckout::MODE_REQUEST,
                'answer_by' => $checkout->status === WebsiteCheckout::STATUS_REQUESTED
                    ? $checkout->hold_expires_at->setTimezone($timezone)->format('H:i \o\n D j M')
                    : null,
                'booking_url' => $site !== null ? $checkout->url() : null,
                'app_url' => url('/app/reservations'),
            ];
        });
    }
}
