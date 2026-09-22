<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

use App\Support\Money\Money;

/**
 * The normalised outcome of a payment operation.
 *
 * Providers differ wildly in their vocabulary; everything the platform stores
 * or decides on is expressed in these terms.
 */
final class PaymentResult
{
    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_CAPTURED = 'captured';

    public const STATUS_PENDING = 'pending';

    public const STATUS_REQUIRES_ACTION = 'requires_action';

    public const STATUS_FAILED = 'failed';

    public const STATUS_VOIDED = 'voided';

    public const STATUS_REFUNDED = 'refunded';

    /**
     * @param  array<string, mixed>  $raw  The provider's own response, retained for support and reconciliation.
     */
    private function __construct(
        public readonly string $status,
        public readonly ?string $providerReference,
        public readonly ?Money $amount,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureMessage = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $instrumentToken = null,
        public readonly ?string $instrumentLast4 = null,
        public readonly ?string $instrumentBrand = null,
        public readonly array $raw = [],
    ) {}

    public static function authorized(string $reference, Money $amount, array $raw = []): self
    {
        return new self(self::STATUS_AUTHORIZED, $reference, $amount, raw: $raw);
    }

    public static function captured(
        string $reference,
        Money $amount,
        array $raw = [],
        ?string $instrumentToken = null,
        ?string $last4 = null,
        ?string $brand = null,
    ): self {
        return new self(
            status: self::STATUS_CAPTURED,
            providerReference: $reference,
            amount: $amount,
            instrumentToken: $instrumentToken,
            instrumentLast4: $last4,
            instrumentBrand: $brand,
            raw: $raw,
        );
    }

    public static function refunded(string $reference, Money $amount, array $raw = []): self
    {
        return new self(self::STATUS_REFUNDED, $reference, $amount, raw: $raw);
    }

    public static function voided(string $reference, array $raw = []): self
    {
        return new self(self::STATUS_VOIDED, $reference, null, raw: $raw);
    }

    public static function pending(string $reference, ?Money $amount = null, array $raw = []): self
    {
        return new self(self::STATUS_PENDING, $reference, $amount, raw: $raw);
    }

    public static function requiresAction(string $reference, string $redirectUrl, ?Money $amount = null, array $raw = []): self
    {
        return new self(
            status: self::STATUS_REQUIRES_ACTION,
            providerReference: $reference,
            amount: $amount,
            redirectUrl: $redirectUrl,
            raw: $raw,
        );
    }

    public static function failed(string $code, string $message, ?string $reference = null, array $raw = []): self
    {
        return new self(
            status: self::STATUS_FAILED,
            providerReference: $reference,
            amount: null,
            failureCode: $code,
            failureMessage: $message,
            raw: $raw,
        );
    }

    public static function instrumentStored(string $token, ?string $last4, ?string $brand, array $raw = []): self
    {
        return new self(
            status: self::STATUS_CAPTURED,
            providerReference: $token,
            amount: null,
            instrumentToken: $token,
            instrumentLast4: $last4,
            instrumentBrand: $brand,
            raw: $raw,
        );
    }

    public function successful(): bool
    {
        return in_array($this->status, [
            self::STATUS_AUTHORIZED,
            self::STATUS_CAPTURED,
            self::STATUS_REFUNDED,
            self::STATUS_VOIDED,
        ], true);
    }

    /**
     * Named `isFailure` rather than `failed` because `failed()` is the static
     * factory that constructs one.
     */
    public function isFailure(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'provider_reference' => $this->providerReference,
            'amount' => $this->amount?->minorUnits,
            'currency' => $this->amount?->currency,
            'failure_code' => $this->failureCode,
            'failure_message' => $this->failureMessage,
            'redirect_url' => $this->redirectUrl,
            'instrument_last4' => $this->instrumentLast4,
            'instrument_brand' => $this->instrumentBrand,
        ];
    }
}
