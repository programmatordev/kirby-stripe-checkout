<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

use Brick\Money\Currency;
use Brick\Money\Money;
use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;

/**
 * Immutable purchase facts shared by Cart and direct Checkout operations.
 *
 * This context exists before order identity or persistence and deliberately
 * exposes no mutable Cart, Order Page, provider client, or runtime service.
 */
final readonly class CheckoutContext
{
    /** @var list<CheckoutLineItem> */
    private array $items;

    /** @var list<CheckoutLineItem> */
    private array $shippableItems;

    private Currency $currency;

    private Money $subtotal;

    /** @param array<mixed> $items */
    public function __construct(
        array $items,
        private ?string $languageCode,
        private string $locale,
        private ?string $userUuid,
        private CheckoutSource $checkoutSource,
        private UiMode $uiMode,
    ) {
        if (array_is_list($items) === false || $items === []) {
            throw new InvalidArgumentException('A checkout context requires line items.');
        }

        $currency = null;
        $subtotal = null;
        $shippableItems = [];

        foreach ($items as $item) {
            if ($item instanceof CheckoutLineItem === false) {
                throw new InvalidArgumentException('A checkout context contains an invalid line item.');
            }

            $itemCurrency = $item->price()->getCurrency();

            if (
                $currency !== null
                && $currency->getCurrencyCode() !== $itemCurrency->getCurrencyCode()
            ) {
                throw new InvalidArgumentException('A checkout context cannot mix currencies.');
            }

            $currency ??= $itemCurrency;
            $subtotal = $subtotal === null
                ? $item->subtotal()
                : $subtotal->plus($item->subtotal());

            if ($item->requiresShipping()) {
                $shippableItems[] = $item;
            }
        }

        self::validateOptionalString($this->languageCode, 64);
        self::validateRequiredString($this->locale, 128);
        self::validateOptionalString($this->userUuid, 256);

        $this->items = $items;
        $this->shippableItems = $shippableItems;
        $this->currency = $currency;
        $this->subtotal = $subtotal;
    }

    /** @return list<CheckoutLineItem> */
    public function items(): array
    {
        return $this->items;
    }

    /** @return list<CheckoutLineItem> */
    public function shippableItems(): array
    {
        return $this->shippableItems;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function subtotal(): Money
    {
        return $this->subtotal;
    }

    public function languageCode(): ?string
    {
        return $this->languageCode;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function userUuid(): ?string
    {
        return $this->userUuid;
    }

    public function checkoutSource(): CheckoutSource
    {
        return $this->checkoutSource;
    }

    public function uiMode(): UiMode
    {
        return $this->uiMode;
    }

    private static function validateRequiredString(string $value, int $maximum): void
    {
        if (
            $value === ''
            || trim($value) !== $value
            || strlen($value) > $maximum
            || TextValidator::isSingleLine($value) === false
        ) {
            throw new InvalidArgumentException('A checkout context contains invalid text.');
        }
    }

    private static function validateOptionalString(?string $value, int $maximum): void
    {
        if ($value !== null) {
            self::validateRequiredString($value, $maximum);
        }
    }
}
