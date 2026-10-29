<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Exceptions;

use RuntimeException;

/**
 * A Stripe call that did not produce a usable answer.
 *
 * The message is Stripe's own sentence where it gave one: for a card error it
 * is written for the cardholder ("Your card has insufficient funds.") and is
 * what the guest should read. `errorCode` and `declineCode` are Stripe's
 * machine codes, for deciding what to do next; `retryable` says whether trying
 * the same request again could work.
 */
class StripeRequestException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $body  What Stripe actually returned, for whoever is debugging it.
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $declineCode = null,
        public readonly ?string $errorType = null,
        public readonly bool $retryable = false,
        public readonly array $body = [],
    ) {
        parent::__construct($message);
    }

    /**
     * A refusal by the card or its bank, as opposed to a fault anywhere else.
     */
    public function isCardError(): bool
    {
        return $this->errorType === 'card_error';
    }
}
