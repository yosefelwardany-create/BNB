<?php

declare(strict_types=1);

namespace App\Domain\Websites\Listeners;

use App\Domain\Payments\Events\PaymentFailed;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Mail\Websites\PayBalance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;

/**
 * An instalment of a website booking could not be taken from the guest's
 * saved card: the guest is emailed their booking link, where they can pay it
 * with a card of their choice.
 *
 * Only a scheduled instalment charged to Stripe counts. A refused capture of
 * a request's authorisation, or a payment staff entered, is not the guest's
 * to fix from an email.
 */
class AskGuestToPayBalance
{
    public function __construct(private readonly TenantContext $tenancy) {}

    public function handle(PaymentFailed $event): void
    {
        $payment = $event->payment;

        if ($payment->provider !== 'stripe' || ! isset($payment->metadata['payment_schedule_id']) || $payment->reservation_id === null) {
            return;
        }

        // The bank asked for the charge to be tried again, and the scheduler
        // will within the hour: the guest has nothing to do yet.
        if ($payment->failure_code === 'processing_error') {
            return;
        }

        $checkout = $this->tenancy->withoutScope(function () use ($payment): ?WebsiteCheckout {
            $reservation = Reservation::query()->find($payment->reservation_id);

            return $reservation?->website_id === null ? null : WebsiteCheckout::query()
                ->where('reservation_id', $reservation->getKey())
                ->where('organization_id', $reservation->organization_id)
                ->latest('id')
                ->first();
        });

        $email = $checkout?->guest['email'] ?? null;

        if ($checkout === null || ! is_string($email) || $email === '') {
            return;
        }

        Mail::to($email)->queue(new PayBalance($checkout, $payment->amount(), $payment->failure_message));
    }
}
