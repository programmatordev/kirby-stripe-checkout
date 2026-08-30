<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

/** Resolves untrusted customer input into one validated product snapshot. */
interface ProductResolverInterface
{
    public function resolve(
        ProductRequest $request,
        ProductResolutionContext $context,
    ): ResolvedProduct;
}
