<?php

declare(strict_types=1);

namespace App\Domain\Platform\Exceptions;

use App\Domain\Platform\Support\PlanFeature;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The plan says no.
 *
 * A 402 rather than the 422 the other domain refusals use, and the difference
 * is deliberate: 422 means "what you sent is wrong", and nothing is wrong with
 * a fifty-first property except that somebody needs to pay for it. A client can
 * tell those apart without reading prose, and an interface can offer an upgrade
 * instead of highlighting a field.
 *
 * Two shapes, distinguished by `$limitKey` being a cap or a feature:
 * a numeric cap that is full, and a feature the plan does not include at all.
 * Both carry the plan's name where there is one, because "limit reached" with
 * no numbers and no plan is the message that generates a support ticket.
 */
class PlanLimitReachedException extends HttpException
{
    private function __construct(
        public readonly string $key,
        public readonly bool $isFeature,
        public readonly ?int $current,
        public readonly ?int $limit,
        public readonly ?string $planName,
        string $message,
    ) {
        parent::__construct(402, $message);
    }

    /**
     * A numeric cap that adding one more would exceed.
     */
    public static function capReached(
        string $limitKey,
        int $current,
        int $limit,
        ?string $planName = null,
    ): self {
        $label = lcfirst(PlanFeature::limits()[$limitKey] ?? $limitKey);

        $message = $planName === null
            ? sprintf('This would exceed the limit on %s (%d of %d in use).', $label, $current, $limit)
            : sprintf(
                'The %s plan allows %d %s and %d are in use. Upgrading raises the limit.',
                $planName,
                $limit,
                $label,
                $current,
            );

        return new self($limitKey, false, $current, $limit, $planName, $message);
    }

    /**
     * A feature the plan does not include.
     */
    public static function featureUnavailable(
        string $feature,
        string $description,
        ?string $planName = null,
    ): self {
        $message = $planName === null
            ? sprintf('This is not available on your current plan. %s', $description)
            : sprintf('The %s plan does not include this. %s', $planName, $description);

        return new self($feature, true, null, null, $planName, $message);
    }
}
