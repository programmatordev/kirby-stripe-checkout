<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Internal;

use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Exception\ProductException;
use ProgrammatorDev\StripeCheckout\Product\Exception\ProductPriceSourceMismatchException;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductErrorCode;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\ProductResolverInterface;
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
    ): Product {
        try {
            $product = $this->resolver->resolve($request, $context);
        } catch (ProductException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new InvalidProductException(ProductErrorCode::RESOLVER_FAILED, $error);
        }

        if ($product->priceSource() !== $context->priceSource()) {
            throw new ProductPriceSourceMismatchException();
        }

        // A resolver may canonicalize only the lookup reference; quantity and
        // customer selections must remain exactly as submitted.
        if (
            $product->request()->quantity() !== $request->quantity()
            || $product->request()->selectedOptions() !== $request->selectedOptions()
        ) {
            throw new InvalidProductException(ProductErrorCode::RESOLVER_CHANGED_REQUEST);
        }

        // Stripe references are checked against the currency when they are
        // retrieved fresh; inline values can be checked at this boundary.
        if (
            $product->price() instanceof Price
            && $product->price()->price()->getCurrency()->getCurrencyCode() !== $context->settings()->currency()
        ) {
            throw new InvalidProductException(ProductErrorCode::CURRENCY_MISMATCH);
        }

        return $product;
    }
}
