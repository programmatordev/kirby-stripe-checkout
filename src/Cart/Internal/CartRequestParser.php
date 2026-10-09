<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart\Internal;

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Cart\CartOperation;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutHttpRequestParser;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\ProductRequestData;
use ProgrammatorDev\StripeCheckout\Checkout\SelectionErrorCode;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;

/** @internal Validates HTTP transport only; product rules remain in the shared cart API. */
final class CartRequestParser
{
    public static function addItem(App $kirby): ProductRequest
    {
        $parser = new CheckoutHttpRequestParser($kirby);
        $selection = self::body($parser, CartOperation::AddItem);

        return ProductRequestData::parseHttp(
            $selection,
            json: $parser->isJson(),
        );
    }

    public static function updateItem(App $kirby): CartItemUpdate
    {
        $body = self::body(new CheckoutHttpRequestParser($kirby), CartOperation::UpdateItem);
        $quantity = $body['quantity'] ?? null;

        if (is_int($quantity) === false || $quantity < 1) {
            throw new CheckoutInputException(SelectionErrorCode::QUANTITY_INVALID);
        }

        return new CartItemUpdate($quantity, self::revision($body));
    }

    public static function updateShippingCountry(App $kirby): ShippingCountryUpdate
    {
        $body = self::body(new CheckoutHttpRequestParser($kirby), CartOperation::UpdateShippingCountry);

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
        return self::revision(self::body(new CheckoutHttpRequestParser($kirby), CartOperation::RemoveItem));
    }

    public static function clear(App $kirby): string
    {
        return self::revision(self::body(new CheckoutHttpRequestParser($kirby), CartOperation::Clear));
    }

    /** @return array<string, mixed> */
    private static function body(CheckoutHttpRequestParser $parser, CartOperation $operation): array
    {
        $body = $parser->body();

        $parser->assertCsrf($body);

        if ($parser->isJson() === false) {
            unset($body['csrf']);

            // Form values are strings; normalize only the documented quantity.
            if ($operation === CartOperation::UpdateItem && array_key_exists('quantity', $body)) {
                $body['quantity'] = ProductRequestData::formQuantity($body['quantity']);
            }

            if ($operation === CartOperation::UpdateShippingCountry && ($body['shippingCountry'] ?? null) === '') {
                $body['shippingCountry'] = null;
            }
        }

        if ($operation === CartOperation::AddItem) {
            // The shared product input boundary owns Add's allowed fields and form quantity conversion.
            return $body;
        }

        $keys = match ($operation) {
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
}
