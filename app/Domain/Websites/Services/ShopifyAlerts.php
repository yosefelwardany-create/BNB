<?php

declare(strict_types=1);

namespace App\Domain\Websites\Services;

use App\Domain\Notifications\Services\Notifier;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Mail\Websites\ShopifyPaymentNeedsAttention;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * A payment on a client's Shopify store that Habitat could not simply book:
 * the nights were taken, the booking was cancelled, or the amount was not
 * what was asked. The money is in the client's store, so only a person can
 * put it right, and they are told where they will see it.
 */
class ShopifyAlerts
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * Says why on the booking, in the bell of whoever handles bookings, and
     * by email to the site's contact address.
     */
    public function paymentNeedsAttention(WebsiteCheckout $checkout, string $orderName, string $why): void
    {
        $why = Str::limit($why, 490);

        $checkout->forceFill(['payment_error' => $why])->save();

        $this->tenancy->runAs($checkout->organization, fn () => $this->notifier->notifyPermissionHolders(
            organizationId: $checkout->organization_id,
            permission: 'reservations.update',
            type: 'website.shopify_payment',
            title: sprintf('Shopify order %s needs attention', $orderName),
            body: $why,
            subject: $checkout->reservation_id === null ? null : Reservation::query()->find($checkout->reservation_id),
            url: '/reservations',
            priority: 'high',
            icon: 'alert-triangle',
            data: ['website_checkout_id' => $checkout->getKey(), 'order' => $orderName],
        ));

        $contact = $checkout->website?->setting('contact_email');

        if (is_string($contact) && $contact !== '') {
            Mail::to($contact)->queue(new ShopifyPaymentNeedsAttention($checkout, $orderName, $why));
        }
    }
}
