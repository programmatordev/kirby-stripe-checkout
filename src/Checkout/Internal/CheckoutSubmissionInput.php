<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;

/**
 * Parsed browser choices, before product resolution or purchase binding.
 *
 * @internal
 */
final readonly class CheckoutSubmissionInput
{
    private const INVALID_STORED_INPUT_MESSAGE = 'Invalid stored Checkout submission input.';

    /** @param list<ProductRequest> $items Empty for Cart; non-empty for Direct. */
    public function __construct(
        private CheckoutSource $checkoutSource,
        private AttemptToken $attemptToken,
        private ?string $cartRevision,
        private array $items,
        private ?string $shippingCountry,
        private bool $json,
    ) {}

    public function checkoutSource(): CheckoutSource
    {
        return $this->checkoutSource;
    }

    public function attemptToken(): AttemptToken
    {
        return $this->attemptToken;
    }

    public function cartRevision(): ?string
    {
        return $this->cartRevision;
    }

    /** @return list<ProductRequest> */
    public function items(): array
    {
        return $this->items;
    }

    public function shippingCountry(): ?string
    {
        return $this->shippingCountry;
    }

    public function isJson(): bool
    {
        return $this->json;
    }

    /**
     * @return array{
     *     source: string,
     *     attemptToken: string,
     *     revision?: string,
     *     items?: list<array{reference: string, quantity: int, options?: array<string, string>}>,
     *     shippingCountry?: string
     * }
     */
    public function toFormData(): array
    {
        $data = [
            'source' => $this->checkoutSource->value,
            'attemptToken' => $this->attemptToken->value(),
        ];

        if ($this->checkoutSource === CheckoutSource::Cart) {
            if ($this->cartRevision === null) {
                throw new \LogicException('Cart Checkout input requires a revision.');
            }

            $data['revision'] = $this->cartRevision;

            return $data;
        }

        $data['items'] = array_map(static function (ProductRequest $item): array {
            $data = [
                'reference' => $item->reference(),
                'quantity' => $item->quantity(),
            ];
            $options = $item->selectedOptions();

            if ($options !== []) {
                $data['options'] = $options;
            }

            return $data;
        }, $this->items);

        if ($this->shippingCountry !== null) {
            $data['shippingCountry'] = $this->shippingCountry;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function toSessionData(): array
    {
        return [
            'source' => $this->checkoutSource->value,
            'attemptToken' => $this->attemptToken->value(),
            'cartRevision' => $this->cartRevision,
            'items' => array_map(ProductRequestData::toArray(...), $this->items),
            'shippingCountry' => $this->shippingCountry,
        ];
    }

    public static function fromSessionData(mixed $data): self
    {
        if (
            is_array($data) === false
            || array_diff(array_keys($data), ['source', 'attemptToken', 'cartRevision', 'items', 'shippingCountry']) !== []
        ) {
            throw new \InvalidArgumentException(self::INVALID_STORED_INPUT_MESSAGE);
        }

        $checkoutSource = is_string($data['source'] ?? null)
            ? CheckoutSource::tryFrom($data['source'])
            : null;
        $attemptToken = $data['attemptToken'] ?? null;
        $cartRevision = $data['cartRevision'] ?? null;
        $shippingCountry = $data['shippingCountry'] ?? null;

        if ($checkoutSource === null || is_string($attemptToken) === false) {
            throw new \InvalidArgumentException(self::INVALID_STORED_INPUT_MESSAGE);
        }

        if ($cartRevision !== null && is_string($cartRevision) === false) {
            throw new \InvalidArgumentException(self::INVALID_STORED_INPUT_MESSAGE);
        }

        if ($shippingCountry !== null && is_string($shippingCountry) === false) {
            throw new \InvalidArgumentException(self::INVALID_STORED_INPUT_MESSAGE);
        }

        $storedItems = $data['items'] ?? null;

        if ($checkoutSource === CheckoutSource::Cart && ($cartRevision === null || $storedItems !== [] || $shippingCountry !== null)) {
            throw new \InvalidArgumentException(self::INVALID_STORED_INPUT_MESSAGE);
        }

        if ($checkoutSource === CheckoutSource::Direct && $cartRevision !== null) {
            throw new \InvalidArgumentException(self::INVALID_STORED_INPUT_MESSAGE);
        }

        $items = $checkoutSource === CheckoutSource::Direct
            ? ProductRequestData::parseList($storedItems)
            : [];

        return new self(
            checkoutSource: $checkoutSource,
            attemptToken: new AttemptToken($attemptToken),
            cartRevision: $cartRevision,
            items: $items,
            shippingCountry: $shippingCountry,
            json: false,
        );
    }
}
