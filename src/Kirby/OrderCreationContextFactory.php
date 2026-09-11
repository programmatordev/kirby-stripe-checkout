<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\App;
use Kirby\Uuid\Uuid;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderNumberFormatter;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;

/** Generates the native order identity before request correlation is constructed. */
final class OrderCreationContextFactory
{
    public function __construct(private readonly App $kirby) {}

    /** @param list<OrderLineItemSnapshot> $lineItems */
    public function create(
        array $lineItems,
        string $currency,
        CheckoutSource $source,
        ?string $cartRevision,
        ?string $userUuid,
        ?string $languageCode,
        UiMode $uiMode,
    ): OrderCreationContext {
        /** @var array<string, mixed> $options */
        $options = $this->kirby->options();
        $formatter = (new ConfigurationResolver())->orderNumberFormatter($options);
        $uuid = Uuid::generate();

        return new OrderCreationContext(
            uuid: $uuid,
            orderNumber: (new OrderNumberFormatter($formatter))->format($uuid),
            sourceType: $source,
            cartRevision: $cartRevision,
            userUuid: $userUuid,
            languageCode: $languageCode,
            uiMode: $uiMode,
            currency: $currency,
            lineItems: $lineItems,
        );
    }
}
