<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Internal;

use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Exception\ProductException;
use ProgrammatorDev\StripeCheckout\Product\Exception\ProductPriceSourceMismatchException;
use ProgrammatorDev\StripeCheckout\Product\InlinePrice;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\ProductResolverInterface;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;
use Throwable;

/**
 * Enforces the invariants shared by built-in and custom product resolvers.
 *
 * @internal
 */
final class GuardedProductResolver implements ProductResolverInterface
{
    public function __construct(private readonly ProductResolverInterface $resolver) {}

    public function resolve(
        ProductRequest $request,
        ProductResolutionContext $context,
    ): ResolvedProduct {
        try {
            $resolved = $this->resolver->resolve($request, $context);
        } catch (ProductException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new InvalidProductException('product.resolver_failed', $error);
        }

        if ($resolved->priceSource() !== $context->priceSource()) {
            throw new ProductPriceSourceMismatchException();
        }

        // A resolver may canonicalize only the lookup reference; quantity and
        // customer selections must remain exactly as submitted.
        if (
            $resolved->request()->quantity() !== $request->quantity()
            || $resolved->request()->selectedOptions() !== $request->selectedOptions()
        ) {
            throw new InvalidProductException('product.resolver_changed_request');
        }

        // Stripe references are checked against the currency when they are
        // retrieved fresh; inline values can be checked at this boundary.
        if (
            $resolved->price() instanceof InlinePrice
            && $resolved->price()->unitPrice()->getCurrency()->getCurrencyCode() !== $context->settings()->currency()
        ) {
            throw new InvalidProductException('product.currency_mismatch');
        }

        return $resolved;
    }
}
