<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use Closure;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\SelectionErrorCode;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;

/**
 * Shares reference normalization and checked merging between cart and buy-now input.
 * Cart mutations expose canonical requests for session storage;
 * direct input retains Products for the current Checkout operation.
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
        return $this->resolveMergedProduct($existing, $incoming)->request();
    }

    public function withQuantity(ProductRequest $existing, int $quantity): ProductRequest
    {
        return $this->resolveWithQuantity($existing, $quantity)->request();
    }

    /** @return non-empty-list<Product> */
    public function normalizeDirectInput(mixed $items): array
    {
        return $this->normalizeDirectRequests(ProductRequestData::parseList($items));
    }

    /**
     * @param non-empty-list<ProductRequest> $requests Parsed at the owning input boundary.
     * @return non-empty-list<Product>
     */
    public function normalizeDirectRequests(array $requests): array
    {
        $products = [];
        $totalQuantity = 0;

        foreach ($requests as $request) {
            $totalQuantity = $totalQuantity === 0
                ? $request->quantity()
                : ProductRequestData::addQuantities($totalQuantity, $request->quantity());
            // Resolve each submitted locator before comparison so aliases can merge into the same product/options.
            $product = ($this->resolveProduct)($request);

            foreach ($products as $index => $existing) {
                if (ProductRequestData::sameItem($existing->request(), $product->request())) {
                    $products[$index] = $this->resolveMergedProduct($existing->request(), $product->request());
                    continue 2;
                }
            }

            $products[] = $product;
        }

        // Retain the facts accepted for each final quantity so Checkout does not resolve them again.
        return array_values($products);
    }

    private function resolveMergedProduct(ProductRequest $existing, ProductRequest $incoming): Product
    {
        if (ProductRequestData::sameItem($existing, $incoming) === false) {
            throw new CheckoutInputException(SelectionErrorCode::INVALID);
        }

        // Individually valid additions can exceed a store's limit once merged,
        // so the resolver must also accept the resulting quantity.
        return $this->resolveWithQuantity(
            $existing,
            ProductRequestData::addQuantities($existing->quantity(), $incoming->quantity()),
        );
    }

    private function resolveWithQuantity(ProductRequest $existing, int $quantity): Product
    {
        $product = ($this->resolveProduct)(new ProductRequest(
            $existing->reference(),
            $quantity,
            $existing->selectedOptions(),
        ));

        // A canonical reference must remain stable; changing it here could silently turn a quantity update into another product.
        if (ProductRequestData::sameItem($existing, $product->request()) === false) {
            throw new CheckoutInputException(SelectionErrorCode::INVALID);
        }

        return $product;
    }
}
