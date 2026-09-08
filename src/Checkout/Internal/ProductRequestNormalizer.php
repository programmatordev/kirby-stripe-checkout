<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use Closure;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;

/**
 * Shares reference normalization and checked merging between cart and buy-now input.
 *
 * @internal The callback is the guarded product-resolution boundary for the operation.
 */
final class ProductRequestNormalizer
{
    public const MAX_ENTRIES = 100;

    /** @param Closure(ProductRequest): Product $resolveProduct */
    public function __construct(private readonly Closure $resolveProduct) {}

    public function normalize(ProductRequest $request): ProductRequest
    {
        return ($this->resolveProduct)($request)->request();
    }

    public function merge(ProductRequest $existing, ProductRequest $incoming): ProductRequest
    {
        if (ProductRequestData::equivalent($existing, $incoming) === false) {
            throw new CheckoutInputException('selection.invalid');
        }

        // Individually valid additions can exceed a store's limit once merged,
        // so the resolver must also accept the resulting quantity.
        return $this->withQuantity(
            $existing,
            ProductRequestData::addQuantities($existing->quantity(), $incoming->quantity()),
        );
    }

    public function withQuantity(ProductRequest $existing, int $quantity): ProductRequest
    {
        $request = $this->normalize(new ProductRequest(
            $existing->reference(),
            $quantity,
            $existing->selectedOptions(),
        ));

        // A persisted canonical reference must remain stable; changing it here
        // could silently turn an update into a second equivalent cart entry.
        if (ProductRequestData::equivalent($existing, $request) === false) {
            throw new CheckoutInputException('selection.invalid');
        }

        return $request;
    }

    /** @return non-empty-list<ProductRequest> */
    public function direct(mixed $items): array
    {
        if (is_array($items) === false || array_is_list($items) === false || $items === []) {
            throw new CheckoutInputException('selection.invalid');
        }

        // Bound submitted work before resolution, even if duplicates would merge.
        if (count($items) > self::MAX_ENTRIES) {
            throw new CheckoutInputException('selection.line_limit_exceeded');
        }

        // Parse the complete body before running any project resolver.
        $requests = array_map(ProductRequestData::parse(...), $items);
        $normalizedRequests = [];
        $totalQuantity = 0;

        foreach ($requests as $request) {
            $totalQuantity = $totalQuantity === 0
                ? $request->quantity()
                : ProductRequestData::addQuantities($totalQuantity, $request->quantity());
            $request = $this->normalize($request);

            foreach ($normalizedRequests as $index => $existing) {
                if (ProductRequestData::equivalent($existing, $request)) {
                    $normalizedRequests[$index] = $this->merge($existing, $request);
                    continue 2;
                }
            }

            $normalizedRequests[] = $request;
        }

        return array_values($normalizedRequests);
    }
}
