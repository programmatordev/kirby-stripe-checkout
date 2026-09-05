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
    /**
     * @param array{
     *   name: string,
     *   description: ?string,
     *   images: list<string>,
     *   sku: string,
     *   price: string,
     *   stripePrice: string,
     *   requiresShipping: string,
     *   options: string
     * } $fields
     */
    public function __construct(
        private ProductResolverInterface|Closure|null $resolver,
        private array $fields,
    ) {}

    public function resolver(): ProductResolverInterface|Closure|null
    {
        return $this->resolver;
    }

    /**
     * @return array{
     *   name: string,
     *   description: ?string,
     *   images: list<string>,
     *   sku: string,
     *   price: string,
     *   stripePrice: string,
     *   requiresShipping: string,
     *   options: string
     * }
     */
    public function fields(): array
    {
        return $this->fields;
    }
}
