<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;

/**
 * Owns fixed Checkout paths and their native localized URLs.
 * Configured customer-facing Page destinations keep their own Kirby URLs.
 *
 * @internal
 */
final class CheckoutUrlBuilder
{
    public const SUBMISSION_PATH = 'stripe-checkout/checkout';

    public const SUCCESS_PATH = 'stripe-checkout/success';

    public const CANCEL_PATH = 'stripe-checkout/cancel';

    public const RETURN_PATH = 'stripe-checkout/return';

    private const ORDER_QUERY_KEY = '_stripe_checkout_order';

    private const SESSION_ID_QUERY_KEY = 'session_id';

    private const SESSION_ID_PLACEHOLDER = '{CHECKOUT_SESSION_ID}';

    public function __construct(private readonly App $kirby) {}

    public function submissionUrl(?string $languageCode): string
    {
        return $this->localizedUrl(self::SUBMISSION_PATH, $languageCode);
    }

    public function successUrl(SessionRequestContext $context): string
    {
        return $this->resultUrl(path: self::SUCCESS_PATH, context: $context, includeSessionId: true);
    }

    public function cancelUrl(SessionRequestContext $context): string
    {
        return $this->resultUrl(path: self::CANCEL_PATH, context: $context, includeSessionId: false);
    }

    public function returnUrl(SessionRequestContext $context): string
    {
        return $this->resultUrl(path: self::RETURN_PATH, context: $context, includeSessionId: true);
    }

    private function resultUrl(string $path, SessionRequestContext $context, bool $includeSessionId): string
    {
        $url = $this->localizedUrl($path, $context->languageCode())
            . '?' . self::ORDER_QUERY_KEY . '=' . rawurlencode($context->order()->pageUuid());

        if ($includeSessionId) {
            // Stripe replaces this exact unencoded placeholder after creating the Session, so it must not pass through URL encoding.
            // https://docs.stripe.com/payments/checkout/custom-success-page?payment-ui=stripe-hosted
            $url .= '&' . self::SESSION_ID_QUERY_KEY . '=' . self::SESSION_ID_PLACEHOLDER;
        }

        return $url;
    }

    private function localizedUrl(string $path, ?string $languageCode): string
    {
        if ($this->kirby->multilang()) {
            // A saved attempt's language may have been removed since creation.
            $languageCode = $this->kirby->language($languageCode)?->code()
                ?? $this->kirby->defaultLanguage()?->code();
        } else {
            $languageCode = null;
        }

        return rtrim($this->kirby->site()->url($languageCode), '/') . '/' . $path;
    }
}
