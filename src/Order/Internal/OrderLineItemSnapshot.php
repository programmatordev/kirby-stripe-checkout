<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Tax\TaxCode;
use Throwable;

/** @internal Frozen initiating order line item; never re-resolves a product or retains Kirby Files. */
final readonly class OrderLineItemSnapshot
{
    /** @param array<string, mixed> $data Canonical persistence projection, kept separate from typed access. */
    private function __construct(
        private array $data,
        private Product $product,
        private Money $price,
        private Money $subtotal,
        private ?string $stripeProductId,
        private int $providerPriceAmount,
        private int $providerSubtotalAmount,
    ) {}

    /** Snapshotting consumes the same completed resolution as shipping; no provider lookup. */
    public static function fromCheckoutLineItem(CheckoutLineItem $lineItem): self
    {
        $registry = new StripeCurrencyRegistry();
        $price = $lineItem->price();
        $subtotal = $lineItem->subtotal();

        return self::fromArray([
            'reference' => $lineItem->productReference(),
            'quantity' => $lineItem->quantity(),
            'variantId' => $lineItem->variantId(),
            'name' => $lineItem->name(),
            'description' => $lineItem->description(),
            'images' => $lineItem->imageUrls(),
            'sku' => $lineItem->sku(),
            'requiresShipping' => $lineItem->requiresShipping(),
            'options' => array_map(static fn(SelectedOption $option): array => [
                'optionId' => $option->optionId(),
                'optionName' => $option->optionName(),
                'valueId' => $option->valueId(),
                'valueName' => $option->valueName(),
            ], $lineItem->options()),
            'metadata' => $lineItem->metadata(),
            'priceSource' => $lineItem->priceSource()->value,
            'stripePriceId' => $lineItem->stripePriceId(),
            'stripeProductId' => $lineItem->stripeProductId(),
            // Freeze the effective local classification alongside the initiating price.
            // Retrying an exact request must not re-read edited content.
            'taxCode' => $lineItem->taxCode()?->id(),
            'currency' => $price->getCurrency()->getCurrencyCode(),
            'price' => (string) $price->getAmount(),
            'subtotal' => (string) $subtotal->getAmount(),
            // Preserve provider units alongside decimal amounts: Stripe's exponent is not necessarily the currency's ISO/Brick minor-unit exponent.
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

            $keys = ['reference', 'quantity', 'variantId', 'name', 'description', 'images', 'sku', 'requiresShipping', 'options', 'metadata', 'priceSource', 'stripePriceId', 'stripeProductId', 'taxCode', 'currency', 'price', 'subtotal', 'providerAmounts'];
            // Omitted classification delegates to Stripe's product preset.
            $data['taxCode'] ??= null;
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
            // Rebuild price identity from saved evidence; even Stripe-priced lines retain their frozen amounts without a catalogue lookup.
            $priceDefinition = match ($data['priceSource']) {
                PriceSource::Kirby->value => new Price($price),
                PriceSource::Stripe->value => new StripePriceReference(OrderData::string($data['stripePriceId'])),
                default => throw new OrderDataException(),
            };

            if ($data['priceSource'] === PriceSource::Kirby->value && ($data['stripePriceId'] !== null || $data['stripeProductId'] !== null)) {
                throw new OrderDataException();
            }

            $stripeProductId = $data['stripeProductId'] === null ? null : OrderData::nonEmptyString($data['stripeProductId']);

            // Stripe-priced items use the provider's product classification; local tax overrides belong only to Kirby-priced items.
            if ($data['priceSource'] === PriceSource::Stripe->value && $data['taxCode'] !== null) {
                throw new OrderDataException();
            }

            // Reuse product invariants rather than maintain a second options/image/SKU validator.
            // Stored tax IDs remain opaque here; catalogue membership is checked when preparing a new Checkout request, not on reads.
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
                taxCode: $data['taxCode'] === null ? null : new TaxCode(OrderData::string($data['taxCode'])),
            );
            // Initiating subtotals exclude later provider discounts and taxes, so they must match the frozen unit price and quantity.
            $subtotal = $price->multipliedBy($request->quantity());
            $providedSubtotal = $registry->toMoney($registry->fromDecimal(OrderData::text($data['subtotal']), $currency));

            if ($subtotal->isEqualTo($providedSubtotal) === false) {
                throw new OrderDataException();
            }

            $providerPriceAmount = $registry->fromMoney($price)->minorAmount();
            $providerSubtotalAmount = $registry->fromMoney($subtotal)->minorAmount();

            if ($data['providerAmounts'] !== [
                'price' => $providerPriceAmount,
                'subtotal' => $providerSubtotalAmount,
            ]) {
                throw new OrderDataException();
            }

            $data['price'] = (string) $price->getAmount();
            $data['subtotal'] = (string) $subtotal->getAmount();
            $data['description'] = $product->description();
            $data['sku'] = $product->sku();

            // This rebuilt Product contains no Kirby File handle, so typed access cannot retain live content.
            return new self(
                data: $data,
                product: $product,
                price: $price,
                subtotal: $subtotal,
                stripeProductId: $stripeProductId,
                providerPriceAmount: $providerPriceAmount,
                providerSubtotalAmount: $providerSubtotalAmount,
            );
        } catch (Throwable) {
            throw new OrderDataException();
        }
    }

    public function productReference(): string
    {
        return $this->product->request()->reference();
    }

    public function quantity(): int
    {
        return $this->product->request()->quantity();
    }

    public function variantId(): ?string
    {
        return $this->product->variantId();
    }

    public function name(): string
    {
        return $this->product->name();
    }

    public function description(): ?string
    {
        return $this->product->description();
    }

    /** @return list<string> */
    public function imageUrls(): array
    {
        return $this->product->imageUrls();
    }

    public function sku(): ?string
    {
        return $this->product->sku();
    }

    public function requiresShipping(): bool
    {
        return $this->product->requiresShipping();
    }

    /** @return list<SelectedOption> */
    public function options(): array
    {
        return $this->product->selectedOptions();
    }

    /** @return array<string, bool|int|string> */
    public function metadata(): array
    {
        return $this->product->metadata();
    }

    public function priceSource(): PriceSource
    {
        return $this->product->priceSource();
    }

    public function stripePriceId(): ?string
    {
        $price = $this->product->price();

        return $price instanceof StripePriceReference ? $price->priceId() : null;
    }

    public function stripeProductId(): ?string
    {
        return $this->stripeProductId;
    }

    public function taxCode(): ?TaxCode
    {
        return $this->product->taxCode();
    }

    public function currency(): string
    {
        return $this->price->getCurrency()->getCurrencyCode();
    }

    public function price(): Money
    {
        return $this->price;
    }

    public function subtotal(): Money
    {
        return $this->subtotal;
    }

    /** Stripe's units can differ from the currency's ISO minor-unit exponent. */
    public function providerPriceAmount(): int
    {
        return $this->providerPriceAmount;
    }

    public function providerSubtotalAmount(): int
    {
        return $this->providerSubtotalAmount;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
