<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use Closure;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;

/**
 * Shares reference normalization and checked merging between cart and buy-now input.
 *
 * @internal The callback is the guarded product-resolution boundary for the operation.
 */
final class SelectionCanonicalizer
{
    public const MAX_ENTRIES = 100;

    /** @param Closure(ProductRequest): ResolvedProduct $resolveProduct */
    public function __construct(private readonly Closure $resolveProduct) {}

    public function resolve(ProductRequest $request): ProductRequest
    {
        return ($this->resolveProduct)($request)->request();
    }

    public function merge(ProductRequest $existing, ProductRequest $incoming): ProductRequest
    {
        if (SelectionData::equivalent($existing, $incoming) === false) {
            throw new CheckoutInputException('selection.invalid');
        }

        // Individually valid additions can exceed a store's limit once merged,
        // so the resolver must also accept the resulting quantity.
        return $this->withQuantity(
            $existing,
            SelectionData::addQuantities($existing->quantity(), $incoming->quantity()),
        );
    }

    public function withQuantity(ProductRequest $existing, int $quantity): ProductRequest
    {
        $resolved = $this->resolve(new ProductRequest(
            $existing->reference(),
            $quantity,
            $existing->selectedOptions(),
        ));

        // A persisted canonical reference must remain stable; changing it here
        // could silently turn an update into a second equivalent cart entry.
        if (SelectionData::equivalent($existing, $resolved) === false) {
            throw new CheckoutInputException('selection.invalid');
        }

        return $resolved;
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
        $requests = array_map(SelectionData::parse(...), $items);
        $canonical = [];
        $totalQuantity = 0;

        foreach ($requests as $request) {
            $totalQuantity = $totalQuantity === 0
                ? $request->quantity()
                : SelectionData::addQuantities($totalQuantity, $request->quantity());
            $request = $this->resolve($request);

            foreach ($canonical as $index => $existing) {
                if (SelectionData::equivalent($existing, $request)) {
                    $canonical[$index] = $this->merge($existing, $request);
                    continue 2;
                }
            }

            $canonical[] = $request;
        }

        return array_values($canonical);
    }
}
