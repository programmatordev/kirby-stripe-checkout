<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart\Internal;

use Kirby\Cms\App;
use Kirby\Http\Request;
use ProgrammatorDev\StripeCheckout\Cart\CartOperation;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SelectionData;
use stdClass;

/** @internal Validates HTTP transport only; product rules remain in the shared cart API. */
final class CartRequestParser
{
    /**
     * @return array{reference?: string, quantity?: int, options?: array<string, string>, revision?: string}
     */
    public static function parse(App $kirby, CartOperation $operation): array
    {
        $request = $kirby->request();
        $header = $request->header('Content-Type', $_SERVER['CONTENT_TYPE'] ?? '');
        $type = is_string($header) ? strtolower(trim(explode(';', $header)[0])) : '';

        if (in_array($type, ['application/json', 'application/x-www-form-urlencoded'], true) === false) {
            throw new CheckoutInputException('request.unsupported_media_type');
        }

        $raw = $request->body()->contents();

        if ($type === 'application/json') {
            // Kirby deliberately falls back to form parsing after invalid JSON.
            // This endpoint must reject it and distinguish {} from [] instead.
            $object = is_string($raw) ? json_decode($raw, depth: 32) : null;

            if ($object instanceof stdClass === false) {
                throw new CheckoutInputException('request.invalid_body');
            }

            $body = (array) $object;

            if (array_key_exists('options', $body)) {
                if ($body['options'] instanceof stdClass === false) {
                    throw new CheckoutInputException('selection.invalid');
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
                throw new CheckoutInputException('request.invalid_body');
            }
        }

        self::csrf($kirby, $request, $type === 'application/json' ? null : ($body['csrf'] ?? null));

        if ($type !== 'application/json') {
            unset($body['csrf']);

            // Form values are strings; normalize only the documented quantity.
            if (in_array($operation, [CartOperation::Add, CartOperation::Update], true) && array_key_exists('quantity', $body)) {
                $body['quantity'] = self::formQuantity($body['quantity']);
            }
        }

        $keys = match ($operation) {
            CartOperation::Add => ['reference', 'quantity', 'options'],
            CartOperation::Update => ['revision', 'quantity'],
            default => ['revision'],
        };

        if (array_diff(array_keys($body), $keys) !== []) {
            throw new CheckoutInputException('selection.invalid');
        }

        if ($operation === CartOperation::Add) {
            $selection = $body;

            // HTTP uses the concise Cart vocabulary; the shared selection
            // parser and stored product requests keep their internal schema.
            if (array_key_exists('options', $selection)) {
                $selection['selectedOptions'] = $selection['options'];
                unset($selection['options']);
            }

            $product = SelectionData::parse($selection);

            return [
                'reference' => $product->reference(),
                'quantity' => $product->quantity(),
                'options' => $product->selectedOptions(),
            ];
        }

        // Require the version the browser saw; substituting the current server
        // revision would silently authorize writes from stale forms or tabs.
        if (is_string($body['revision'] ?? null) === false || $body['revision'] === '' || strlen($body['revision']) > 128) {
            throw new CheckoutInputException('selection.invalid');
        }

        if ($operation === CartOperation::Update && (is_int($body['quantity'] ?? null) === false || $body['quantity'] < 1)) {
            throw new CheckoutInputException('selection.quantity_invalid');
        }

        /** @var array{revision: string, quantity?: int} $body */
        return $body;
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
            throw new CheckoutInputException('request.csrf_invalid');
        }
    }

    private static function formQuantity(mixed $value): int
    {
        if (is_string($value) === false || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new CheckoutInputException('selection.quantity_invalid');
        }

        $quantity = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($quantity === false) {
            throw new CheckoutInputException('selection.quantity_invalid');
        }

        return $quantity;
    }
}
