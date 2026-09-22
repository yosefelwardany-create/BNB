<?php

declare(strict_types=1);

namespace App\Domain\Payments\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Payments\Models\Refund;
use Illuminate\Database\Eloquent\Model;

class PaymentRefunded extends AbstractDomainEvent
{
    public const NAME = 'payment.refunded';

    public function __construct(public readonly Refund $refund)
    {
        parent::__construct();
    }

    public function subject(): ?Model
    {
        return $this->refund;
    }

    public function payload(): array
    {
        $payment = $this->refund->payment;

        return [
            'refund_id' => $this->refund->getKey(),
            'reference' => $this->refund->reference,
            'amount' => $this->refund->amount,
            'currency' => $this->refund->currency,
            'reason' => $this->refund->reason,
            'is_simulated' => (bool) $this->refund->is_simulated,
        ] + ($payment === null ? [] : PaymentPayload::build($payment));
    }

    public function idempotencyKey(): ?string
    {
        return sprintf('payment.refunded:%s', $this->refund->getKey());
    }
}
