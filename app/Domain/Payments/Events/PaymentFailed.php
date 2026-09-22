<?php

declare(strict_types=1);

namespace App\Domain\Payments\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Model;

/**
 * A payment the processor declined.
 *
 * Worth an event of its own: a failed balance payment three days before
 * arrival is the single most actionable thing that happens in this business,
 * and automation exists to chase it.
 */
class PaymentFailed extends AbstractDomainEvent
{
    public const NAME = 'payment.failed';

    public function __construct(public readonly Payment $payment)
    {
        parent::__construct();
    }

    public function subject(): ?Model
    {
        return $this->payment;
    }

    public function payload(): array
    {
        return PaymentPayload::build($this->payment) + [
            'failure_code' => $this->payment->failure_code,
            'failure_message' => $this->payment->failure_message,
        ];
    }
}
