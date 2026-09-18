<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

use Brick\Money\Money;
use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;
use Throwable;

/**
 * Projects the trusted resolved product facts available before order creation.
 * This operation value is distinct from the persisted Order line-item snapshot.
 */
final readonly class CheckoutLineItem
{
    /** @var list<SelectedOption> */
    private array $options;

    /** @var array<string, bool|int|string> */
    private array $metadata;

    /**
     * @param array<mixed> $options
     * @param array<mixed, mixed> $metadata
     */
    public function __construct(
        private string $productReference,
        private ?string $variantId,
        private ?string $sku,
        private int $quantity,
        private Money $price,
        private Money $subtotal,
        private bool $requiresShipping,
        array $options = [],
        array $metadata = [],
    ) {
        self::validateString($this->productReference, 2048, false);
        self::validateString($this->variantId, 128, true);
        self::validateString($this->sku, 500, true);

        if ($this->quantity < 1) {
            throw new InvalidArgumentException('A checkout line item requires a positive quantity.');
        }

        try {
            $currencies = new StripeCurrencyRegistry();
            $currencies->fromMoney($this->price);
            $currencies->fromMoney($this->subtotal);
        } catch (Throwable $error) {
            throw new InvalidArgumentException('A checkout line item requires exact non-negative money.', previous: $error);
        }

        if (
            $this->subtotal->getCurrency()->getCurrencyCode()
            !== $this->price->getCurrency()->getCurrencyCode()
            || $this->subtotal->isEqualTo($this->price->multipliedBy($this->quantity)) === false
        ) {
            throw new InvalidArgumentException('A checkout line item subtotal must match its price and quantity.');
        }

        $this->options = self::validateOptions($options);
        $this->metadata = self::validateMetadata($metadata);
    }

    public function productReference(): string
    {
        return $this->productReference;
    }

    public function variantId(): ?string
    {
        return $this->variantId;
    }

    public function sku(): ?string
    {
        return $this->sku;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function price(): Money
    {
        return $this->price;
    }

    public function subtotal(): Money
    {
        return $this->subtotal;
    }

    public function requiresShipping(): bool
    {
        return $this->requiresShipping;
    }

    /** @return list<SelectedOption> */
    public function options(): array
    {
        return $this->options;
    }

    /** @return array<string, bool|int|string> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    private static function validateString(?string $value, int $maximum, bool $nullable): void
    {
        if ($nullable && $value === null) {
            return;
        }

        if (
            $value === null
            || $value === ''
            || trim($value) !== $value
            || strlen($value) > $maximum
            || TextValidator::isSingleLine($value) === false
        ) {
            throw new InvalidArgumentException('A checkout line item contains invalid product data.');
        }
    }

    /**
     * @param array<mixed> $options
     * @return list<SelectedOption>
     */
    private static function validateOptions(array $options): array
    {
        if (array_is_list($options) === false || count($options) > 32) {
            throw new InvalidArgumentException('A checkout line item contains invalid options.');
        }

        $optionIds = [];

        foreach ($options as $option) {
            if ($option instanceof SelectedOption === false || isset($optionIds[$option->optionId()])) {
                throw new InvalidArgumentException('A checkout line item contains invalid options.');
            }

            $optionIds[$option->optionId()] = true;
        }

        /** @var list<SelectedOption> $options */
        return $options;
    }

    /**
     * @param array<mixed, mixed> $metadata
     * @return array<string, bool|int|string>
     */
    private static function validateMetadata(array $metadata): array
    {
        if (count($metadata) > 20) {
            throw new InvalidArgumentException('A checkout line item contains invalid metadata.');
        }

        foreach ($metadata as $key => $value) {
            if (
                is_string($key) === false
                || $key === ''
                || trim($key) !== $key
                || strlen($key) > 128
                || TextValidator::isSingleLine($key) === false
                || is_bool($value) === false && is_int($value) === false && is_string($value) === false
                || is_string($value) && (
                    $value === ''
                    || trim($value) !== $value
                    || strlen($value) > 500
                    || TextValidator::isSingleLine($value) === false
                )
            ) {
                throw new InvalidArgumentException('A checkout line item contains invalid metadata.');
            }
        }

        ksort($metadata);

        return $metadata;
    }
}
