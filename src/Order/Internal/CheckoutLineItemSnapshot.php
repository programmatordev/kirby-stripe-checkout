<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;

/** @internal One returned Checkout line, correlated to its frozen initiating position. */
final readonly class CheckoutLineItemSnapshot
{
    public function __construct(
        private string $stripeLineItemId,
        private int $initiatingIndex,
        private string $stripePriceId,
        private string $stripeProductId,
        private int $quantity,
        private ?string $description,
        private Money $price,
        private Money $subtotal,
        private Money $discount,
        private Money $tax,
        private Money $total,
        private CheckoutDiscountsSnapshot $checkoutDiscounts,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $keys = ['stripeLineItemId', 'initiatingIndex', 'stripePriceId', 'stripeProductId', 'quantity', 'description', 'currency', 'price', 'subtotal', 'discount', 'tax', 'total', 'discounts'];
        OrderData::validateAllowedKeys($data, $keys);
        OrderData::validateRequiredKeys($data, $keys);
        $references = ['stripeLineItemId', 'stripePriceId', 'stripeProductId'];

        foreach ($references as $key) {
            OrderData::nonEmptyString($data[$key]);
        }

        $index = OrderData::integer($data['initiatingIndex']);
        $quantity = OrderData::integer($data['quantity']);

        if ($index < 0 || $quantity < 1) {
            throw new OrderDataException();
        }

        $currency = OrderData::text($data['currency']);
        $registry = new StripeCurrencyRegistry();
        $amount = static fn(string $key): Money => $registry->toMoney($registry->fromDecimal(OrderData::text($data[$key]), $currency));
        $discount = $amount('discount');

        return new self(
            stripeLineItemId: OrderData::string($data['stripeLineItemId']),
            initiatingIndex: $index,
            stripePriceId: OrderData::string($data['stripePriceId']),
            stripeProductId: OrderData::string($data['stripeProductId']),
            quantity: $quantity,
            description: OrderData::nullableString($data['description']),
            price: $amount('price'),
            subtotal: $amount('subtotal'),
            discount: $discount,
            tax: $amount('tax'),
            total: $amount('total'),
            checkoutDiscounts: CheckoutDiscountsSnapshot::available(
                array_map(static fn(mixed $value): DiscountSnapshot => DiscountSnapshot::fromArray(OrderData::map($value)), OrderData::list($data['discounts'])),
                (string) $discount->getAmount(),
            ),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'stripeLineItemId' => $this->stripeLineItemId,
            'initiatingIndex' => $this->initiatingIndex,
            'stripePriceId' => $this->stripePriceId,
            'stripeProductId' => $this->stripeProductId,
            'quantity' => $this->quantity,
            'description' => $this->description,
            'currency' => $this->price->getCurrency()->getCurrencyCode(),
            'price' => (string) $this->price->getAmount(),
            'subtotal' => (string) $this->subtotal->getAmount(),
            'discount' => (string) $this->discount->getAmount(),
            'tax' => (string) $this->tax->getAmount(),
            'total' => (string) $this->total->getAmount(),
            'discounts' => array_map(static fn(DiscountSnapshot $discount): array => $discount->toArray(), $this->discounts()),
        ];
    }

    public function stripeLineItemId(): string
    {
        return $this->stripeLineItemId;
    }

    public function initiatingIndex(): int
    {
        return $this->initiatingIndex;
    }

    public function stripePriceId(): string
    {
        return $this->stripePriceId;
    }

    public function stripeProductId(): string
    {
        return $this->stripeProductId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function price(): Money
    {
        return $this->price;
    }

    public function subtotal(): Money
    {
        return $this->subtotal;
    }

    public function discount(): Money
    {
        return $this->discount;
    }

    public function tax(): Money
    {
        return $this->tax;
    }

    public function total(): Money
    {
        return $this->total;
    }

    /** @return list<DiscountSnapshot> */
    public function discounts(): array
    {
        return $this->checkoutDiscounts->discounts();
    }
}
