<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderNumberFormatter;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;

/** Constructs order facts around an identity reserved before Checkout submission. */
final class OrderCreationContextFactory
{
    public function __construct(private readonly App $kirby) {}

    /**
     * @param string $uuid UUID reserved by the structured Checkout attempt token
     * @param list<OrderLineItemSnapshot> $lineItems
     */
    public function create(
        string $uuid,
        array $lineItems,
        string $currency,
        CheckoutSource $checkoutSource,
        ?string $cartRevision,
        ?string $userUuid,
        ?string $languageCode,
        UiMode $uiMode,
    ): OrderCreationContext {
        /** @var array<string, mixed> $options */
        $options = $this->kirby->options();
        $formatter = (new ConfigurationResolver())->orderNumberFormatter($options);

        return new OrderCreationContext(
            uuid: $uuid,
            orderNumber: (new OrderNumberFormatter($formatter))->format($uuid),
            checkoutSource: $checkoutSource,
            cartRevision: $cartRevision,
            userUuid: $userUuid,
            languageCode: $languageCode,
            uiMode: $uiMode,
            currency: $currency,
            lineItems: $lineItems,
        );
    }
}
