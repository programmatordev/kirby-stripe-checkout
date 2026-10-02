<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart\Internal;

use Kirby\Cms\App;
use Kirby\Http\Request;
use ProgrammatorDev\StripeCheckout\Cart\CartOperation;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\ProductRequestData;
use ProgrammatorDev\StripeCheckout\Checkout\RequestErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\SelectionErrorCode;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;
use stdClass;

/** @internal Validates HTTP transport only; product rules remain in the shared cart API. */
final class CartRequestParser
{
    public static function addItem(App $kirby): ProductRequest
    {
        $selection = self::body($kirby, CartOperation::AddItem);

        // HTTP uses the concise Cart vocabulary; the shared selection parser and stored product requests keep their internal schema.
        if (array_key_exists('options', $selection)) {
            $selection['selectedOptions'] = $selection['options'];
            unset($selection['options']);
        }

        return ProductRequestData::parse($selection);
    }

    public static function updateItem(App $kirby): CartItemUpdate
    {
        $body = self::body($kirby, CartOperation::UpdateItem);
        $quantity = $body['quantity'] ?? null;

        if (is_int($quantity) === false || $quantity < 1) {
            throw new CheckoutInputException(SelectionErrorCode::QUANTITY_INVALID);
        }

        return new CartItemUpdate($quantity, self::revision($body));
    }

    public static function updateShippingCountry(App $kirby): ShippingCountryUpdate
    {
        $body = self::body($kirby, CartOperation::UpdateShippingCountry);

        if (
            array_key_exists('shippingCountry', $body) === false
            || (is_string($body['shippingCountry']) === false && $body['shippingCountry'] !== null)
        ) {
            throw new CheckoutInputException(ShippingErrorCode::COUNTRY_INVALID);
        }

        return new ShippingCountryUpdate($body['shippingCountry'], self::revision($body));
    }

    public static function removeItem(App $kirby): string
    {
        return self::revision(self::body($kirby, CartOperation::RemoveItem));
    }

    public static function clear(App $kirby): string
    {
        return self::revision(self::body($kirby, CartOperation::Clear));
    }

    /** @return array<string, mixed> */
    private static function body(App $kirby, CartOperation $operation): array
    {
        $request = $kirby->request();
        $header = $request->header('Content-Type', $_SERVER['CONTENT_TYPE'] ?? '');
        $type = is_string($header) ? strtolower(trim(explode(';', $header)[0])) : '';

        if (in_array($type, ['application/json', 'application/x-www-form-urlencoded'], true) === false) {
            throw new CheckoutInputException(RequestErrorCode::UNSUPPORTED_MEDIA_TYPE);
        }

        $raw = $request->body()->contents();

        if ($type === 'application/json') {
            // Kirby deliberately falls back to form parsing after invalid JSON.
            // This endpoint must reject it and distinguish {} from [] instead.
            $object = is_string($raw) ? json_decode($raw, depth: 32) : null;

            if ($object instanceof stdClass === false) {
                throw new CheckoutInputException(RequestErrorCode::INVALID_BODY);
            }

            $body = (array) $object;

            if (array_key_exists('options', $body)) {
                if ($body['options'] instanceof stdClass === false) {
                    throw new CheckoutInputException(SelectionErrorCode::INVALID);
                }

                $body['options'] = (array) $body['options'];
            }
        } else {
            if (is_array($raw)) {
                $body = $raw; // PHP has already decoded an ordinary POST form.
            } else {
                // Avoid Body::data()'s JSON-first fallback for form-labelled input too.
                parse_str($raw, $body);
            }

            if ($body === []) {
                throw new CheckoutInputException(RequestErrorCode::INVALID_BODY);
            }
        }

        self::csrf($kirby, $request, $type === 'application/json' ? null : ($body['csrf'] ?? null));

        if ($type !== 'application/json') {
            unset($body['csrf']);

            // Form values are strings; normalize only the documented quantity.
            if (in_array($operation, [CartOperation::AddItem, CartOperation::UpdateItem], true) && array_key_exists('quantity', $body)) {
                $body['quantity'] = self::formQuantity($body['quantity']);
            }

            if ($operation === CartOperation::UpdateShippingCountry && ($body['shippingCountry'] ?? null) === '') {
                $body['shippingCountry'] = null;
            }
        }

        $keys = match ($operation) {
            CartOperation::AddItem => ['reference', 'quantity', 'options'],
            CartOperation::UpdateItem => ['revision', 'quantity'],
            CartOperation::UpdateShippingCountry => ['revision', 'shippingCountry'],
            default => ['revision'],
        };

        if (array_diff(array_keys($body), $keys) !== []) {
            throw new CheckoutInputException(SelectionErrorCode::INVALID);
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    /** @param array<string, mixed> $body */
    private static function revision(array $body): string
    {
        $revision = $body['revision'] ?? null;

        // Require the version the browser saw; substituting the current server revision would silently authorize writes from stale forms or tabs.
        if (is_string($revision) === false || $revision === '' || strlen($revision) > 128) {
            throw new CheckoutInputException(SelectionErrorCode::INVALID);
        }

        return $revision;
    }

    private static function csrf(App $kirby, Request $request, mixed $formToken): void
    {
        $header = $request->header('X-CSRF');
        $token = $header ?? $formToken;

        if (
            is_string($token) === false
            || ($header !== null && $formToken !== null && $header !== $formToken)
            || $kirby->csrf($token) !== true
        ) {
            throw new CheckoutInputException(RequestErrorCode::CSRF_INVALID);
        }
    }

    private static function formQuantity(mixed $value): int
    {
        if (is_string($value) === false || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new CheckoutInputException(SelectionErrorCode::QUANTITY_INVALID);
        }

        $quantity = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($quantity === false) {
            throw new CheckoutInputException(SelectionErrorCode::QUANTITY_INVALID);
        }

        return $quantity;
    }
}
