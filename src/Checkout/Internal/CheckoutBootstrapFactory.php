<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use DateTimeImmutable;
use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartStoreInterface;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutBootstrap;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Configuration\Configuration;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationErrorCode;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;

/**
 * Issues private form data using native browser facts, without resolving a purchase.
 *
 * @internal
 */
final class CheckoutBootstrapFactory
{
    public function __construct(
        private readonly App $kirby,
        private readonly Configuration $configuration,
        private readonly SessionRequestContextFactory $requestContextFactory,
        private readonly CheckoutUrlBuilder $checkoutUrlBuilder,
        private readonly CartStoreInterface $cartStore,
        private readonly BrowserAttemptStore $attemptStore,
    ) {}

    public function create(CheckoutSource $checkoutSource): CheckoutBootstrap
    {
        $this->requireReadyConfiguration();

        if ($checkoutSource === CheckoutSource::Cart && $this->configuration->cartEnabled() === false) {
            throw new CheckoutInputException(CheckoutErrorCode::CART_DISABLED);
        }

        $uiMode = $this->configuration->settings()->uiMode();
        $languageCode = $this->kirby->language()?->code();
        // Only a GET URL is a safe browser return target; do not read body or Referer destinations.
        $requestUrl = $this->kirby->request()->method() === 'GET'
            ? $this->kirby->request()->url()->toString()
            : null;
        $initiatingUrl = $this->requestContextFactory->initiatingUrl($languageCode, $requestUrl);
        // Capture saved input without invoking product or shipping resolvers just to render a form.
        $cart = $checkoutSource === CheckoutSource::Cart
            ? $this->cartStore->read()
            : null;
        $csrf = (string) $this->kirby->csrf();
        $context = BrowserAttemptContext::capture(
            checkoutSource: $checkoutSource,
            uiMode: $uiMode,
            languageCode: $languageCode,
            userUuid: $this->kirby->user()?->uuid()->toString(),
            csrf: $csrf,
            cart: $cart,
        );
        $attempt = $this->attemptStore->issue(
            context: $context,
            initiatingUrl: $initiatingUrl,
            issuedAt: new DateTimeImmutable(),
        );
        return new CheckoutBootstrap(
            uiMode: $uiMode,
            checkoutSource: $checkoutSource,
            actionUrl: $this->checkoutUrlBuilder->submissionUrl($languageCode),
            csrf: $csrf,
            attemptToken: $attempt->token(),
            cartRevision: $cart?->revision(),
            publishableKey: $uiMode === UiMode::Embedded ? $this->configuration->stripe()->publishableKey() : null,
        );
    }

    private function requireReadyConfiguration(): void
    {
        if ($this->configuration->settings()->currency() === null) {
            throw new ConfigurationException(ConfigurationErrorCode::REQUIRED_MISSING, 'settings.currency');
        }

        $stripe = $this->configuration->stripe();

        if ($stripe->hasSecretKey() === false) {
            throw new ConfigurationException(ConfigurationErrorCode::CREDENTIAL_MISSING, 'stripe.secretKey');
        }

        if ($stripe->hasWebhookSecret() === false) {
            throw new ConfigurationException(ConfigurationErrorCode::CREDENTIAL_MISSING, 'stripe.webhookSecret');
        }

        if ($this->configuration->settings()->uiMode() === UiMode::Embedded && $stripe->hasPublishableKey() === false) {
            throw new ConfigurationException(ConfigurationErrorCode::CREDENTIAL_MISSING, 'stripe.publishableKey');
        }
    }
}
