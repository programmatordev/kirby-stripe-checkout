<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use Kirby\Cms\App;
use LogicException;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPageStore;
use ProgrammatorDev\StripeCheckout\Plugin\PluginMetadata;
use Stripe\Checkout\Session;

/** Builds the protected standard Stripe Checkout Session request. */
final class SessionRequestBuilder
{
    private const INTEGRATION_IDENTIFIER_PREFIX = 'kirby_stripe_checkout_';

    private const ORDER_QUERY_KEY = '_stripe_checkout_order';

    private const SESSION_ID_QUERY_KEY = 'session_id';

    private const SESSION_ID_PLACEHOLDER = '{CHECKOUT_SESSION_ID}';

    private const STRIPE_METADATA_OWNER = 'kirby_stripe_checkout_owner';

    private const STRIPE_METADATA_ORDER = 'kirby_stripe_checkout_order';

    private const STRIPE_METADATA_LINE = 'kirby_stripe_checkout_line';

    public function __construct(private readonly App $kirby) {}

    public function build(SessionRequestContext $context): SessionRequest
    {
        $order = $context->order();

        // Keep the same order correlation on both Stripe resources because
        // later lifecycle events may expose either the Session or PaymentIntent.
        $correlation = [
            self::STRIPE_METADATA_OWNER => PluginMetadata::NAME,
            self::STRIPE_METADATA_ORDER => $order->pageUuid(),
        ];
        $parameters = [
            'client_reference_id' => $order->pageUuid(),
            'currency' => strtolower($order->currency()),
            'expires_at' => $context->expiresAt()->getTimestamp(),
            'integration_identifier' => $this->integrationIdentifier(),
            'line_items' => $this->lineItems($context),
            'locale' => $context->locale(),
            'metadata' => $correlation,
            'mode' => Session::MODE_PAYMENT,
            'payment_intent_data' => [
                'metadata' => $correlation,
            ],
            'ui_mode' => match ($context->uiMode()) {
                UiMode::Hosted => Session::UI_MODE_HOSTED_PAGE,
                UiMode::Embedded => Session::UI_MODE_EMBEDDED_PAGE,
            },
        ];

        if ($context->uiMode() === UiMode::Hosted) {
            $parameters['cancel_url'] = $this->routeUrl('cancel', $context);
            $parameters['success_url'] = $this->routeUrl('success', $context, true);
        } else {
            $parameters['redirect_on_completion'] = Session::REDIRECT_ON_COMPLETION_ALWAYS;
            $parameters['return_url'] = $this->routeUrl('return', $context, true);
        }

        return new SessionRequest($parameters);
    }

    /** @return list<array<string, mixed>> */
    private function lineItems(SessionRequestContext $context): array
    {
        $order = $context->order();
        $lineItems = [];

        foreach ($order->lineItems() as $index => $lineItem) {
            // The immutable order UUID and snapshot position identify the line
            // without depending on product content that can change later.
            $metadata = [
                self::STRIPE_METADATA_OWNER => PluginMetadata::NAME,
                self::STRIPE_METADATA_ORDER => $order->pageUuid(),
                self::STRIPE_METADATA_LINE => hash('sha256', $order->pageUuid() . "\0" . $index),
            ];
            $requestLine = [
                'metadata' => $metadata,
                'quantity' => $lineItem['quantity'],
            ];

            if ($lineItem['priceSource'] === PriceSource::Stripe->value) {
                $requestLine['price'] = $lineItem['stripePriceId'];
            } elseif ($lineItem['priceSource'] === PriceSource::Kirby->value) {
                $requestLine['price_data'] = $this->inlinePrice($lineItem);
            } else {
                throw new LogicException('The order contains an unsupported price source.');
            }

            $lineItems[] = $requestLine;
        }

        return $lineItems;
    }

    /**
     * @param array<string, mixed> $lineItem
     * @return array<string, mixed>
     */
    private function inlinePrice(array $lineItem): array
    {
        $providerAmounts = $lineItem['providerAmounts'];
        $currency = $lineItem['currency'];

        if (
            is_array($providerAmounts) === false
            || is_int($providerAmounts['price'] ?? null) === false
            || is_string($currency) === false
        ) {
            throw new LogicException('The order contains invalid provider price units.');
        }

        $productData = [
            'name' => $lineItem['name'],
        ];

        if (is_string($lineItem['description'])) {
            $productData['description'] = $lineItem['description'];
        }

        if (is_array($lineItem['images']) && $lineItem['images'] !== []) {
            $productData['images'] = $lineItem['images'];
        }

        return [
            'currency' => strtolower($currency),
            'product_data' => $productData,
            'unit_amount' => $providerAmounts['price'],
        ];
    }

    private function integrationIdentifier(): string
    {
        $pageUuid = (new StripeCheckoutPageStore($this->kirby))
            ->initialize()
            ->uuid()
            ->toString();

        $hash = hash('sha256', $pageUuid, true);
        $suffix = '';

        for ($index = 0; $index < 8; $index++) {
            $suffix .= chr(97 + ord($hash[$index]) % 26);
        }

        // The settings Page UUID is randomly generated once per installation;
        // deriving the suffix from it keeps Stripe's identifier stable.
        return self::INTEGRATION_IDENTIFIER_PREFIX . $suffix;
    }

    private function routeUrl(
        string $route,
        SessionRequestContext $context,
        bool $includeSessionId = false,
    ): string {
        $languageCode = $context->languageCode();

        if ($this->kirby->multilang()) {
            $languageCode = $this->kirby->language($languageCode)?->code()
                ?? $this->kirby->defaultLanguage()?->code();
        } else {
            $languageCode = null;
        }

        $url = rtrim($this->kirby->site()->url($languageCode), '/')
            . '/stripe-checkout/' . $route
            . '?' . self::ORDER_QUERY_KEY . '=' . rawurlencode($context->order()->pageUuid());

        if ($includeSessionId) {
            // Stripe replaces this exact unencoded placeholder after creating
            // the Session, so it must not pass through URL encoding.
            $url .= '&' . self::SESSION_ID_QUERY_KEY . '=' . self::SESSION_ID_PLACEHOLDER;
        }

        return $url;
    }
}
