<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Policies;

/**
 * See {@see PricingPolicy}: everything that decides what a night costs shares
 * one gate, because "may this person change what guests are charged" is one
 * decision rather than five.
 */
class PricingRulePolicy extends PricingPolicy {}
