<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Internal;

use Closure;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\ProductResolverInterface;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;

/**
 * Adapts the PHP configuration Closure to the public resolver contract.
 *
 * @internal
 */
final class ClosureProductResolver implements ProductResolverInterface
{
    /** @var Closure(ProductRequest, ProductResolutionContext): ResolvedProduct */
    private readonly Closure $resolver;

    /** @param Closure(ProductRequest, ProductResolutionContext): ResolvedProduct $resolver */
    public function __construct(Closure $resolver)
    {
        $this->resolver = $resolver;
    }

    public function resolve(
        ProductRequest $request,
        ProductResolutionContext $context,
    ): ResolvedProduct {
        return ($this->resolver)($request, $context);
    }
}
