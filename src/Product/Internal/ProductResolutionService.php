<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Internal;

use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Exception\ProductException;
use ProgrammatorDev\StripeCheckout\Product\Exception\ProductPriceSourceMismatchException;
use ProgrammatorDev\StripeCheckout\Product\InlinePrice;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\ProductResolverInterface;
use ProgrammatorDev\StripeCheckout\Product\ProductSelection;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;
use Throwable;

/**
 * Enforces resolver invariants shared by built-in and custom resolvers.
 *
 * @internal
 */
final class ProductResolutionService
{
    public function __construct(private readonly ProductResolverInterface $resolver) {}

    public function resolve(
        ProductSelection $selection,
        ProductResolutionContext $context,
    ): ResolvedProduct {
        try {
            $resolved = $this->resolver->resolve($selection, $context);
        } catch (ProductException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new InvalidProductException('product.resolver_failed', $error);
        }

        if ($resolved->priceSource() !== $context->priceSource()) {
            throw new ProductPriceSourceMismatchException();
        }

        if (
            $resolved->selection()->quantity() !== $selection->quantity()
            || $resolved->selection()->choices() !== $selection->choices()
        ) {
            throw new InvalidProductException('product.resolver_changed_selection');
        }

        if (
            $resolved->price() instanceof InlinePrice
            && $resolved->price()->unitPrice()->getCurrency()->getCurrencyCode() !== $context->settings()->currency()
        ) {
            throw new InvalidProductException('product.currency_mismatch');
        }

        return $resolved;
    }
}
