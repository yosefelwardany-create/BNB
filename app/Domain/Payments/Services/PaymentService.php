<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Accounting\Services\PaymentPostingRules;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Integrations\Contracts\PaymentProviderInterface;
use App\Domain\Integrations\DataObjects\PaymentAuthorizationRequest;
use App\Domain\Integrations\DataObjects\PaymentCaptureRequest;
use App\Domain\Integrations\DataObjects\PaymentResult;
use App\Domain\Integrations\DataObjects\RefundRequest;
use App\Domain\Integrations\Registries\PaymentProviderRegistry;
use App\Domain\Payments\Enums\PaymentKind;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Events\PaymentCaptured;
use App\Domain\Payments\Events\PaymentFailed;
use App\Domain\Payments\Events\PaymentRefunded;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Platform\Services\SequenceGenerator;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Taking money, giving it back, and keeping the ledger in step.
 *
 * The shape of this service follows from one fact: the processor and the
 * platform are separate systems that can disagree. So every operation here
 * does three things in a fixed order — check what *we* believe is allowed, ask
 * the processor, then record what the processor actually said. Never the other
 * way round, and never only one of them.
 *
 * **Idempotency is per operation, not per request.** The key is derived from
 * the payment and the amount, so a retried capture returns the original rather
 * than taking the money twice. This is the single most important property in
 * the file: everything else here is recoverable, and a double charge is not.
 *
 * **Limits are enforced before the processor is called.** A refund larger than
 * its capture is refused by us, by the processor, and by a database CHECK
 * constraint. Three independent defences, because this is where the money is.
 *
 * **Ledger posting happens after the processor confirms.** An entry for a
 * capture that was declined would be a lie, and one written before the answer
 * came back is a guess.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentProviderRegistry $providers,
        private readonly PaymentPostingRules $posting,
        private readonly SequenceGenerator $sequences,
        private readonly TenantContext $tenancy,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Hold funds without taking them.
     *
     * Posts nothing to the ledger: an authorisation moves no money, and
     * recognising it would overstate cash by every unarrived booking.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function authorize(
        Money $amount,
        ?Reservation $reservation = null,
        array $attributes = [],
    ): Payment {
        $payment = $this->record($amount, $reservation, $attributes);

        $provider = $this->providerFor($payment);

        $result = $provider->authorize(new PaymentAuthorizationRequest(
            amount: $amount,
            idempotencyKey: $this->idempotencyKey($payment, 'authorize', $amount),
            instrumentToken: $payment->instrument_token,
            customerReference: $reservation?->guest_id,
            description: $payment->description,
            metadata: ['payment_id' => $payment->getKey()],
        ));

        return $this->applyResult($payment, $result, PaymentStatus::Authorized);
    }

    /**
     * Take funds.
     *
     * Where no prior authorisation exists this charges directly, which is what
     * a cash payment, a bank transfer or a channel-collected booking looks
     * like once recorded.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function capture(
        Payment $payment,
        ?Money $amount = null,
        array $attributes = [],
    ): Payment {
        $amount ??= $payment->capturableAmount();

        if (! $payment->status->isOpen() && ! $payment->status->isCaptured()) {
            throw PaymentException::notCapturable($payment->reference, $payment->status->value);
        }

        // Nothing left on the hold. Either this is a retry of a capture that
        // already succeeded — a timeout, a redelivered job, an operator who
        // pressed the button twice — or a mistake. Returning the payment is
        // correct for the first and harmless for the second; throwing would
        // turn an ordinary retry into a support ticket, and the alternative
        // (calling the processor again) is how a guest gets charged twice.
        if ($payment->capturableAmount()->isZero() && $payment->status->isCaptured()) {
            return $payment;
        }

        // Checked here as well as by the processor and the database. A capture
        // beyond the authorisation is the mistake that takes a guest's money
        // without their agreement.
        if ($amount->minorUnits > $payment->capturableAmount()->minorUnits) {
            throw PaymentException::exceedsAuthorization($payment->reference);
        }

        if ($amount->isZero()) {
            return $payment;
        }

        $provider = $this->providerFor($payment);

        $result = $provider->capture(new PaymentCaptureRequest(
            amount: $amount,
            // Derived from the payment and the amount, so a retry — a timeout,
            // a queue redelivery, an impatient operator — returns the original
            // capture rather than taking the money a second time.
            idempotencyKey: $this->idempotencyKey($payment, 'capture', $amount),
            authorizationReference: $payment->provider_reference,
            instrumentToken: $payment->instrument_token,
            description: $payment->description,
            metadata: ['payment_id' => $payment->getKey()],
        ));

        if ($result->isFailure()) {
            return $this->applyResult($payment, $result, PaymentStatus::Failed);
        }

        return DB::transaction(function () use ($payment, $amount, $result, $attributes): Payment {
            $fee = $this->feeFrom($result, $attributes, $amount->currency);

            $captured = $payment->capturedAmount()->add($amount);

            $payment->forceFill([
                'status' => PaymentStatus::Captured->value,
                'captured_amount' => $captured->minorUnits,
                'fee_amount' => $payment->feeAmount()->add($fee)->minorUnits,
                'captured_at' => $payment->captured_at ?? now(),
                // Deliberately *not* overwritten with the capture's own
                // reference. `provider_reference` is the authorization — the
                // thing later captures and refunds are made against — and
                // replacing it with a capture id severs that link, so a second
                // instalment finds nothing to draw on and a refund has nothing
                // to return. Captures are recorded alongside it instead.
                'provider_reference' => $payment->provider_reference ?? $result->providerReference,
                'provider_status' => $result->status,
                'is_simulated' => ! $this->providerFor($payment)->isLive(),
                'metadata' => array_merge($payment->metadata ?? [], [
                    'captures' => array_values(array_filter(array_merge(
                        $payment->metadata['captures'] ?? [],
                        [$result->providerReference],
                    ))),
                ]),
            ])->save();

            // Only now that the processor has confirmed. An entry written
            // before the answer came back is a guess.
            $this->posting->captured($payment->fresh(), $amount, $fee);

            $this->applyToReservation($payment);

            $this->audit->record(
                action: 'payment.captured',
                subject: $payment,
                newValues: ['amount' => $amount->minorUnits, 'fee' => $fee->minorUnits],
                description: sprintf('Captured %s on %s', $amount->toDecimal(), $payment->reference),
            );

            PaymentCaptured::dispatch($payment->fresh(), $amount);

            return $payment->fresh();
        });
    }

    /**
     * Take money without a separate authorisation step.
     *
     * The common path for cash, bank transfers and anything a channel
     * collected: there was never a hold to capture, only a fact to record.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function charge(
        Money $amount,
        ?Reservation $reservation = null,
        array $attributes = [],
    ): Payment {
        $payment = $this->record($amount, $reservation, $attributes);

        return $this->capture($payment, $amount, $attributes);
    }

    /**
     * Record money that moved without us.
     *
     * A channel that collects from the guest itself, a bank transfer somebody
     * reconciled off a statement, cash handed over at the door: the money has
     * already moved, and there is no processor to ask.
     *
     * Distinct from {@see charge()} because routing this through a payment
     * provider would be a lie in both directions — it would simulate a capture
     * that never happened, and it would fail outright for a "provider" like
     * Airbnb that is a distribution channel rather than a processor.
     *
     * The ledger entry is posted exactly as for a real capture, so revenue is
     * recognised; `is_collected_by_us` decides whether it lands as cash or as
     * a receivable from whoever is holding it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordExternalPayment(
        Money $amount,
        ?Reservation $reservation = null,
        array $attributes = [],
    ): Payment {
        $payment = $this->record($amount, $reservation, $attributes + [
            'provider' => null,
            'method' => 'external',
        ]);

        $fee = isset($attributes['fee_amount'])
            ? Money::of((int) $attributes['fee_amount'], $amount->currency)
            : Money::zero($amount->currency);

        return DB::transaction(function () use ($payment, $amount, $fee, $reservation): Payment {
            $payment->forceFill([
                'status' => PaymentStatus::Captured->value,
                'captured_amount' => $amount->minorUnits,
                'fee_amount' => $fee->minorUnits,
                'captured_at' => now(),
                // Nothing was simulated: the money really moved, it simply
                // moved somewhere we were not.
                'is_simulated' => false,
                'provider_status' => 'external',
            ])->save();

            $this->posting->captured($payment->fresh(), $amount, $fee);

            $reservation?->recalculateTotals();

            PaymentCaptured::dispatch($payment->fresh(), $amount);

            return $payment->fresh();
        });
    }

    /**
     * Give money back.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function refund(
        Payment $payment,
        ?Money $amount = null,
        ?string $reason = null,
        array $attributes = [],
    ): Refund {
        $amount ??= $payment->refundableAmount();

        if ($amount->isZero()) {
            throw new PaymentException('There is nothing left to refund on this payment.', 'nothing_to_refund');
        }

        // Refused by us, by the processor, and by a CHECK constraint. Three
        // independent defences, because a refund beyond the capture gives away
        // money that was never taken.
        if ($amount->minorUnits > $payment->refundableAmount()->minorUnits) {
            throw PaymentException::exceedsCapture($payment->reference);
        }

        $organization = $this->tenancy->organizationOrFail();

        $refund = Refund::query()->create([
            'organization_id' => $organization->getKey(),
            'payment_id' => $payment->getKey(),
            'reservation_id' => $payment->reservation_id,
            'reference' => $this->sequences->next($organization->getKey(), SequenceGenerator::PAYOUT, 'REF', 6),
            'amount' => $amount->minorUnits,
            'currency' => $amount->currency,
            'status' => 'pending',
            'reason' => $reason,
            'notes' => $attributes['notes'] ?? null,
            'provider' => $payment->provider,
            'created_by_id' => auth()->id(),
        ]);

        $provider = $this->providerFor($payment);

        $result = $provider->refund(new RefundRequest(
            paymentReference: (string) $payment->provider_reference,
            amount: $amount,
            idempotencyKey: $this->idempotencyKey($payment, 'refund:'.$refund->getKey(), $amount),
            reason: $reason,
            metadata: ['refund_id' => $refund->getKey()],
        ));

        if ($result->isFailure()) {
            $refund->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_message' => $result->failureMessage,
            ])->save();

            throw PaymentException::declined(
                $result->failureCode ?? 'refund_failed',
                $result->failureMessage ?? 'The refund was declined.',
            );
        }

        return DB::transaction(function () use ($refund, $payment, $amount, $reason, $result, $provider): Refund {
            $refund->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'provider_reference' => $result->providerReference,
                'is_simulated' => ! $provider->isLive(),
            ])->save();

            $refunded = $payment->refundedAmount()->add($amount);

            $payment->forceFill([
                'refunded_amount' => $refunded->minorUnits,
                'status' => $refunded->equals($payment->capturedAmount())
                    ? PaymentStatus::Refunded->value
                    : PaymentStatus::PartiallyRefunded->value,
            ])->save();

            $this->posting->refunded($refund->fresh());

            $this->applyToReservation($payment->fresh());

            $this->audit->record(
                action: 'payment.refunded',
                subject: $payment,
                newValues: ['amount' => $amount->minorUnits, 'reason' => $reason],
                description: sprintf('Refunded %s on %s', $amount->toDecimal(), $payment->reference),
            );

            PaymentRefunded::dispatch($refund->fresh());

            return $refund->fresh();
        });
    }

    /**
     * Release an authorisation that will not be taken.
     */
    public function void(Payment $payment, ?string $reason = null): Payment
    {
        if (! $payment->status->isOpen()) {
            throw new PaymentException(
                sprintf('Payment %s is %s and cannot be voided.', $payment->reference, $payment->status->value),
                'not_voidable',
            );
        }

        $result = $this->providerFor($payment)->void(
            (string) $payment->provider_reference,
            $this->idempotencyKey($payment, 'void', $payment->amount()),
        );

        if ($result->isFailure()) {
            return $this->applyResult($payment, $result, PaymentStatus::Failed);
        }

        $payment->forceFill([
            'status' => PaymentStatus::Voided->value,
            'voided_at' => now(),
            'provider_status' => $result->status,
            'metadata' => array_merge($payment->metadata ?? [], ['void_reason' => $reason]),
        ])->save();

        // Nothing is posted: an authorisation that was never captured moved no
        // money, so there is nothing in the ledger to undo.
        return $payment->fresh();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Create the platform's record of an intended movement.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function record(Money $amount, ?Reservation $reservation, array $attributes): Payment
    {
        $organization = $this->tenancy->organizationOrFail();

        $kind = $attributes['kind'] ?? PaymentKind::Booking;
        $kind = $kind instanceof PaymentKind ? $kind : PaymentKind::from((string) $kind);

        $payment = new Payment;

        $payment->fill(collect($attributes)->only((new Payment)->getFillable())->all());

        $payment->organization_id = $organization->getKey();
        $payment->reference = $this->sequences->next($organization->getKey(), SequenceGenerator::INVOICE, 'PAY', 6);
        $payment->kind = $kind;
        $payment->amount = $amount->minorUnits;
        $payment->currency = $amount->currency;
        $payment->base_currency = $organization->base_currency;
        $payment->reservation_id = $reservation?->getKey();
        $payment->guest_id = $reservation?->guest_id;
        $payment->property_id = $reservation?->property_id;
        $payment->created_by_id = auth()->id();

        // A null provider is meaningful: money that moved without us has no
        // processor, and asking the registry for one named after a
        // distribution channel would fail outright.
        $provider = $attributes['provider'] ?? $this->providers->default()->key();

        $payment->provider = $provider;
        $payment->is_simulated = $provider !== null && ! $this->providers->make($provider)->isLive();

        $payment->save();

        return $payment;
    }

    /**
     * Write a provider's answer onto the payment.
     */
    private function applyResult(Payment $payment, PaymentResult $result, PaymentStatus $onSuccess): Payment
    {
        if ($result->isFailure()) {
            $payment->forceFill([
                'status' => PaymentStatus::Failed->value,
                'failed_at' => now(),
                'failure_code' => $result->failureCode,
                'failure_message' => $result->failureMessage,
                'provider_status' => $result->status,
            ])->save();

            PaymentFailed::dispatch($payment->fresh());

            throw PaymentException::declined(
                $result->failureCode ?? 'declined',
                $result->failureMessage ?? 'The payment was declined.',
                // A network problem is worth retrying; a declined card is not,
                // and retrying one is how a guest ends up with six holds.
                $result->failureCode === 'processing_error',
            );
        }

        // A provider may need the guest to complete 3-D Secure. That is not a
        // failure and must not be recorded as one: the payment is waiting.
        $status = $result->status === 'requires_action'
            ? PaymentStatus::RequiresAction
            : $onSuccess;

        $payment->forceFill([
            'status' => $status->value,
            'provider_reference' => $result->providerReference,
            'provider_status' => $result->status,
            'authorized_at' => $status === PaymentStatus::Authorized ? now() : $payment->authorized_at,
            'instrument_last4' => $result->instrumentLast4 ?? $payment->instrument_last4,
            'instrument_brand' => $result->instrumentBrand ?? $payment->instrument_brand,
            'metadata' => array_merge(
                $payment->metadata ?? [],
                array_filter(['redirect_url' => $result->redirectUrl]),
            ),
        ])->save();

        return $payment->fresh();
    }

    /**
     * Keep the reservation's paid total in step.
     *
     * Recomputed from the payments rather than incremented, so a correction to
     * one payment cannot leave the booking's balance permanently wrong.
     */
    private function applyToReservation(Payment $payment): void
    {
        $payment->reservation?->recalculateTotals();
    }

    private function providerFor(Payment $payment): PaymentProviderInterface
    {
        return $payment->provider === null
            ? $this->providers->default()
            : $this->providers->make($payment->provider);
    }

    /**
     * A stable key for one operation on one payment.
     *
     * The amount is part of it so that a *different* capture on the same
     * authorisation — two instalments against one hold — is correctly treated
     * as a separate operation rather than deduplicated into the first.
     */
    private function idempotencyKey(Payment $payment, string $operation, Money $amount): string
    {
        return Str::limit(sprintf(
            '%s:%s:%d',
            $payment->getKey(),
            $operation,
            $amount->minorUnits,
        ), 180, '');
    }

    /**
     * What the processor kept.
     *
     * Taken from the provider's own answer where it reports one, and from the
     * caller otherwise. Never estimated: a guessed fee makes every net figure
     * in the product subtly wrong.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function feeFrom(PaymentResult $result, array $attributes, string $currency): Money
    {
        $reported = $result->raw['fee'] ?? $attributes['fee_amount'] ?? null;

        return $reported === null
            ? Money::zero($currency)
            : Money::of((int) $reported, $currency);
    }
}
