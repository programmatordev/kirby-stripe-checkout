<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order;

use Brick\Money\Money;
use Kirby\Uuid\Uri;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\ProductRequestNormalizer;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;

/**
 * Immutable purchase facts prepared before the Order Page is created.
 * The caller supplies a Kirby-generated identifier;
 * this value neither creates nor looks up a Page and contains no writable Page or raw attempt token.
 */
final readonly class OrderCreationContext
{
    private Money $subtotal;

    private string $pageUuid;

    /** @var list<OrderLineItemSnapshot> */
    private array $lineItems;

    private bool $requiresShipping;

    /** @param array<array-key, OrderLineItemSnapshot> $lineItems */
    public function __construct(
        private string $uuid,
        private string $orderNumber,
        private CheckoutSource $checkoutSource,
        private ?string $cartRevision,
        private ?string $userUuid,
        private ?string $languageCode,
        private UiMode $uiMode,
        private string $currency,
        array $lineItems,
    ) {
        // Snapshot the native content ID, not a live Page UUID object: its methods can populate caches or generate missing IDs.
        // Only the public reference needs a scheme, which Kirby's URI value builds without those side effects.
        $reference = new Uri([
            'scheme' => 'page',
            'host' => OrderData::text($uuid),
        ]);
        $this->pageUuid = OrderData::uuid($reference->toString());
        OrderData::text($orderNumber, 80);

        if ($userUuid !== null) {
            OrderData::uuid($userUuid, 'user');
        }

        if ($languageCode !== null) {
            OrderData::text($languageCode, 255);
        }

        if ($cartRevision !== null) {
            OrderData::text($cartRevision);
        }

        if (($checkoutSource === CheckoutSource::Cart) !== ($cartRevision !== null)) {
            throw new OrderDataException();
        }

        if (array_is_list($lineItems) === false || $lineItems === []) {
            throw new OrderDataException();
        }

        if (count($lineItems) > ProductRequestNormalizer::MAX_ENTRIES) {
            throw new OrderDataException();
        }

        $registry = new StripeCurrencyRegistry();
        $subtotal = $registry->toMoney($registry->fromDecimal('0', $currency));
        $priceSource = null;
        $requiresShipping = false;

        // Each line owns its product and amount invariants; this context enforces consistency across the purchase.
        foreach ($lineItems as $lineItem) {
            if ($lineItem->currency() !== $currency) {
                throw new OrderDataException();
            }

            if ($priceSource !== null && $priceSource !== $lineItem->priceSource()) {
                throw new OrderDataException();
            }

            $priceSource = $lineItem->priceSource();
            $subtotal = $subtotal->plus($lineItem->subtotal());
            $requiresShipping = $requiresShipping || $lineItem->requiresShipping();
        }

        // Individually valid line amounts can sum beyond the snapshot's provider-unit integer range.
        $registry->fromMoney($subtotal);
        $this->subtotal = $subtotal;
        $this->lineItems = $lineItems;
        $this->requiresShipping = $requiresShipping;
    }

    /** Identifier to persist unchanged in the Page's native uuid field; not a UUID object. */
    public function uuid(): string
    {
        return $this->uuid;
    }

    /** Prospective page:// reference; its presence does not mean the Page exists. */
    public function pageUuid(): string
    {
        return $this->pageUuid;
    }

    public function orderNumber(): string
    {
        return $this->orderNumber;
    }

    public function checkoutSource(): CheckoutSource
    {
        return $this->checkoutSource;
    }

    public function cartRevision(): ?string
    {
        return $this->cartRevision;
    }

    public function userUuid(): ?string
    {
        return $this->userUuid;
    }

    public function languageCode(): ?string
    {
        return $this->languageCode;
    }

    public function uiMode(): UiMode
    {
        return $this->uiMode;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function subtotal(): Money
    {
        return $this->subtotal;
    }

    /** @return list<OrderLineItemSnapshot> */
    public function lineItems(): array
    {
        return $this->lineItems;
    }

    public function requiresShipping(): bool
    {
        return $this->requiresShipping;
    }
}
