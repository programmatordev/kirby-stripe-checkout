<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\RequestErrorCode;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;

/**
 * Owns Checkout's HTTP vocabulary and same-origin policy; domain owners validate commerce.
 *
 * @internal
 */
final class CheckoutSubmissionParser
{
    public static function parse(App $kirby): CheckoutSubmissionInput
    {
        $parser = new CheckoutHttpRequestParser($kirby);
        $body = $parser->body();
        $parser->assertCsrf($body);
        self::assertOrigin($kirby);

        if ($parser->isJson() === false) {
            unset($body['csrf']);
        }

        $source = $body['source'] ?? null;
        $checkoutSource = is_string($source) ? CheckoutSource::tryFrom($source) : null;

        if ($checkoutSource === null) {
            throw new CheckoutInputException(RequestErrorCode::INVALID_BODY);
        }

        $keys = $checkoutSource === CheckoutSource::Cart
            ? ['source', 'attemptToken', 'revision']
            : ['source', 'attemptToken', 'items', 'shippingCountry'];

        if (array_diff(array_keys($body), $keys) !== []) {
            throw new CheckoutInputException(RequestErrorCode::INVALID_BODY);
        }

        $attemptToken = $body['attemptToken'] ?? null;

        if (is_string($attemptToken) === false) {
            throw new CheckoutInputException(RequestErrorCode::INVALID_BODY);
        }

        $cartRevision = null;
        $items = [];
        $shippingCountry = null;

        if ($checkoutSource === CheckoutSource::Cart) {
            $cartRevision = $body['revision'] ?? null;

            if (is_string($cartRevision) === false || $cartRevision === '') {
                throw new CheckoutInputException(RequestErrorCode::INVALID_BODY);
            }
        } else {
            $items = ProductRequestData::parseHttpList($body['items'] ?? null, json: $parser->isJson());
            $shippingCountry = $body['shippingCountry'] ?? null;

            if ($parser->isJson() === false && $shippingCountry === '') {
                $shippingCountry = null;
            }

            if ($shippingCountry !== null && is_string($shippingCountry) === false) {
                throw new CheckoutInputException(ShippingErrorCode::COUNTRY_INVALID);
            }
        }

        return new CheckoutSubmissionInput(
            checkoutSource: $checkoutSource,
            attemptToken: new AttemptToken($attemptToken),
            cartRevision: $cartRevision,
            items: $items,
            shippingCountry: $shippingCountry,
            json: $parser->isJson(),
        );
    }

    private static function assertOrigin(App $kirby): void
    {
        $request = $kirby->request();
        $origin = $request->header('Origin');
        $value = $origin ?? $request->header('Referer');

        // Some browsers omit both headers. Native CSRF remains required;
        // when Origin is present, a matching Referer cannot override it.
        if ($value === null) {
            return;
        }

        if (is_string($value) === false || CheckoutUrlValidator::sameOrigin($value, $kirby->site()->url($kirby->language()?->code())) === false) {
            throw new CheckoutInputException(RequestErrorCode::ORIGIN_INVALID);
        }
    }
}
