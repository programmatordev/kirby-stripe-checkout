<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use Closure;
use ProgrammatorDev\StripeCheckout\Product\ProductResolverInterface;

/**
 * Carries the validated PHP-only product resolver and content-field mapping.
 *
 * @internal
 */
final readonly class ProductConfiguration
{
    public function __construct(
        private ProductResolverInterface|Closure|null $resolver,
        private ProductFields $fields,
    ) {}

    public function resolver(): ProductResolverInterface|Closure|null
    {
        return $this->resolver;
    }

    public function fields(): ProductFields
    {
        return $this->fields;
    }
}
