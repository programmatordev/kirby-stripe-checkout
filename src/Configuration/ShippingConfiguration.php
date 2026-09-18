<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use Closure;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingResolverInterface;

/** Carries the validated PHP-only replacement shipping resolver. */
final readonly class ShippingConfiguration
{
    public function __construct(
        private ShippingResolverInterface|Closure|null $resolver,
    ) {}

    public function resolver(): ShippingResolverInterface|Closure|null
    {
        return $this->resolver;
    }
}
