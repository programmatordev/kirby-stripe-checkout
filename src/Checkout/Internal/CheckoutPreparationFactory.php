<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderNumberFormatter;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuoteStatus;
use ProgrammatorDev\StripeCheckout\Tax\TaxCode;

/** @internal Freezes one resolved purchase into matching order, shipping and request evidence. */
final readonly class CheckoutPreparationFactory
{
    public function __construct(
        private CheckoutResolver $resolver,
        private OrderNumberFormatter $orderNumbers,
        private SessionRequestBuilder $requestBuilder,
        private SessionRequestCustomizer $requestCustomizer,
    ) {}

    /** The UUID is reserved by the attempt token; preparation neither generates nor persists it. */
    public function create(
        string $uuid,
        CheckoutContext $checkout,
        ShippingContext $shipping,
        ?string $cartRevision = null,
    ): CheckoutPreparation {
        $quote = $this->resolver->resolveShippingQuote($checkout, $shipping);

        if ($quote !== null && $quote->status() !== ShippingQuoteStatus::Available) {
            throw new CheckoutInputException($quote->reasonCode() ?? ShippingErrorCode::UNAVAILABLE);
        }

        $initiatingShipping = $quote === null
            ? null
            : InitiatingShippingSnapshot::fromQuote($checkout, $shipping, $quote);
        $order = new OrderCreationContext(
            uuid: $uuid,
            orderNumber: $this->orderNumbers->format($uuid),
            checkoutSource: $checkout->checkoutSource(),
            cartRevision: $cartRevision,
            userUuid: $checkout->userUuid(),
            languageCode: $checkout->languageCode(),
            uiMode: $checkout->uiMode(),
            currency: $checkout->currency()->getCurrencyCode(),
            lineItems: array_map(OrderLineItemSnapshot::fromCheckoutLineItem(...), $checkout->items()),
        );

        return new CheckoutPreparation($order, $initiatingShipping);
    }

    public function sessionRequest(
        SessionRequestContext $context,
        ?InitiatingShippingSnapshot $initiatingShipping,
    ): SessionRequest {
        foreach ($context->order()->lineItems() as $lineItem) {
            if (is_string($lineItem['taxCode'])) {
                // Frozen local classifications are checked before a new attempt is
                // persisted. Exact saved retries bypass request preparation entirely.
                $this->resolver->validateTaxCode(new TaxCode($lineItem['taxCode']));
            }
        }

        $request = $this->requestBuilder->build($context, $initiatingShipping);

        return $this->requestCustomizer->customize($context, $request);
    }
}
