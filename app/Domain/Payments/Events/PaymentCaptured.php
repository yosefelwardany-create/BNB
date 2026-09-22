<?php

declare(strict_types=1);

namespace App\Domain\Payments\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Payments\Models\Payment;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Money has actually been taken.
 *
 * Carries the amount of *this* capture as well as the payment's running total,
 * because a two-instalment booking captures twice against one payment and a
 * consumer that only saw the total could not tell the second from a
 * redelivery of the first.
 */
class PaymentCaptured extends AbstractDomainEvent
{
    public const NAME = 'payment.captured';

    public function __construct(
        public readonly Payment $payment,
        public readonly Money $amount,
    ) {
        parent::__construct();
    }

    public function subject(): ?Model
    {
        return $this->payment;
    }

    public function payload(): array
    {
        return PaymentPayload::build($this->payment) + [
            'captured_now' => $this->amount->minorUnits,
        ];
    }

    public function idempotencyKey(): ?string
    {
        return sprintf(
            'payment.captured:%s:%d',
            $this->payment->getKey(),
            $this->payment->captured_amount,
        );
    }
}
