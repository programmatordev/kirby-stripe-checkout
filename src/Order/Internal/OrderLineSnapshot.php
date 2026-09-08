<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use Throwable;

/** @internal Frozen initiating line; never re-resolves a product or retains Kirby Files. */
final readonly class OrderLineSnapshot
{
    /** @param array<string, mixed> $data */
    private function __construct(private array $data, private Money $subtotal) {}

    /** Stripe-backed prices must already be resolved by the caller; snapshotting performs no lookup. */
    public static function fromProduct(Product $product, Money $price, ?string $stripeProductId = null): self
    {
        $registry = new StripeCurrencyRegistry();
        $subtotal = $price->multipliedBy($product->request()->quantity());

        if ($product->price() instanceof Price && $product->price()->unitPrice()->isEqualTo($price) === false) {
            throw new OrderDataException();
        }

        return self::fromArray([
            'reference' => $product->request()->reference(),
            'quantity' => $product->request()->quantity(),
            'variantId' => $product->variantId(),
            'name' => $product->name(),
            'description' => $product->description(),
            'images' => $product->imageUrls(),
            'sku' => $product->sku(),
            'requiresShipping' => $product->requiresShipping(),
            'options' => array_map(static fn(SelectedOption $option): array => [
                'optionId' => $option->optionId(),
                'optionName' => $option->optionName(),
                'valueId' => $option->valueId(),
                'valueName' => $option->valueName(),
            ], $product->selectedOptions()),
            'metadata' => $product->metadata(),
            'priceSource' => $product->priceSource()->value,
            'stripePriceId' => $product->price() instanceof StripePriceReference ? $product->price()->priceId() : null,
            'stripeProductId' => $stripeProductId,
            'currency' => $price->getCurrency()->getCurrencyCode(),
            'price' => (string) $price->getAmount(),
            'subtotal' => (string) $subtotal->getAmount(),
            // Preserve provider units alongside decimal amounts: Stripe's exponent
            // is not necessarily the currency's ISO/Brick minor-unit exponent.
            'providerAmounts' => [
                'price' => $registry->fromMoney($price)->minorAmount(),
                'subtotal' => $registry->fromMoney($subtotal)->minorAmount(),
            ],
        ]);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        try {
            $data = OrderData::map($data);

            $keys = ['reference', 'quantity', 'variantId', 'name', 'description', 'images', 'sku', 'requiresShipping', 'options', 'metadata', 'priceSource', 'stripePriceId', 'stripeProductId', 'currency', 'price', 'subtotal', 'providerAmounts'];
            OrderData::validateAllowedKeys($data, $keys);
            OrderData::validateRequiredKeys($data, $keys);

            if (is_array($data['options']) === false || array_is_list($data['options']) === false) {
                throw new OrderDataException();
            }

            $options = [];
            $selection = [];
            $optionKeys = ['optionId', 'optionName', 'valueId', 'valueName'];

            foreach ($data['options'] as $option) {
                if (is_array($option) === false) {
                    throw new OrderDataException();
                }

                OrderData::validateAllowedKeys($option, $optionKeys);
                OrderData::validateRequiredKeys($option, $optionKeys);
                $selectedOption = new SelectedOption(
                    OrderData::text($option['optionId']),
                    OrderData::text($option['optionName']),
                    OrderData::text($option['valueId']),
                    OrderData::text($option['valueName']),
                );
                $options[] = $selectedOption;
                $selection[$selectedOption->optionId()] = $selectedOption->valueId();
            }

            $registry = new StripeCurrencyRegistry();
            $currency = OrderData::text($data['currency']);
            $price = $registry->toMoney($registry->fromDecimal(OrderData::text($data['price']), $currency));
            $request = new ProductRequest(OrderData::text($data['reference']), OrderData::integer($data['quantity']), $selection);
            $priceDefinition = match ($data['priceSource']) {
                'kirby' => new Price($price),
                'stripe' => new StripePriceReference(OrderData::text($data['stripePriceId'])),
                default => throw new OrderDataException(),
            };

            if (
                $data['priceSource'] === 'kirby' && ($data['stripePriceId'] !== null || $data['stripeProductId'] !== null)
                || $data['stripeProductId'] !== null && (is_string($data['stripeProductId']) === false || preg_match('/\Aprod_[A-Za-z0-9]+\z/', $data['stripeProductId']) !== 1)
            ) {
                throw new OrderDataException();
            }

            // Reuse product invariants rather than maintain a second options/image/SKU validator.
            $product = new Product(
                $request,
                OrderData::text($data['name']),
                OrderData::boolean($data['requiresShipping']),
                $priceDefinition,
                $options,
                OrderData::nullableString($data['description']),
                OrderData::list($data['images']),
                OrderData::nullableString($data['sku']),
                OrderData::map($data['metadata']),
                OrderData::nullableString($data['variantId']),
            );
            $subtotal = $price->multipliedBy($request->quantity());
            $providedSubtotal = $registry->toMoney($registry->fromDecimal(OrderData::text($data['subtotal']), $currency));

            if ($subtotal->isEqualTo($providedSubtotal) === false || $data['providerAmounts'] !== [
                'price' => $registry->fromMoney($price)->minorAmount(),
                'subtotal' => $registry->fromMoney($subtotal)->minorAmount(),
            ]) {
                throw new OrderDataException();
            }

            $data['price'] = (string) $price->getAmount();
            $data['subtotal'] = (string) $subtotal->getAmount();
            $data['description'] = $product->description();
            $data['sku'] = $product->sku();

            return new self($data, $subtotal);
        } catch (Throwable) {
            throw new OrderDataException();
        }
    }

    public function subtotal(): Money
    {
        return $this->subtotal;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
