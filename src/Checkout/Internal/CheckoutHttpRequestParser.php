<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\RequestErrorCode;
use stdClass;

/**
 * Strict browser body decoding and native CSRF verification shared by Cart and Checkout.
 *
 * @internal
 */
final readonly class CheckoutHttpRequestParser
{
    private const MAX_JSON_DEPTH = 32;

    private bool $json;

    public function __construct(private App $kirby)
    {
        $header = $kirby->request()->header('Content-Type', $_SERVER['CONTENT_TYPE'] ?? '');
        $type = is_string($header) ? strtolower(trim(explode(';', $header)[0])) : '';

        if (in_array($type, ['application/json', 'application/x-www-form-urlencoded'], true) === false) {
            throw new CheckoutInputException(RequestErrorCode::UNSUPPORTED_MEDIA_TYPE);
        }

        $this->json = $type === 'application/json';
    }

    public function isJson(): bool
    {
        return $this->json;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        $raw = $this->kirby->request()->body()->contents();

        if ($this->json) {
            // Kirby falls back to forms after invalid JSON; this boundary must reject it and distinguish {} from [].
            $object = is_string($raw) ? json_decode($raw, depth: self::MAX_JSON_DEPTH) : null;

            if ($object instanceof stdClass === false) {
                throw new CheckoutInputException(RequestErrorCode::INVALID_BODY);
            }

            // Keep nested objects intact so selection parsing can distinguish option maps from JSON lists.
            $body = (array) $object;
        } else {
            if (is_array($raw)) {
                $body = $raw; // PHP has already decoded an ordinary POST form.
            } else {
                // Respect the declared form type instead of Kirby's JSON-first fallback.
                parse_str($raw, $body);
            }

            if ($body === []) {
                throw new CheckoutInputException(RequestErrorCode::INVALID_BODY);
            }
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    /** @param array<string, mixed> $body */
    public function assertCsrf(array $body): void
    {
        $header = $this->kirby->request()->header('X-CSRF');
        $formToken = $this->json ? null : ($body['csrf'] ?? null);
        $token = $header ?? $formToken;

        if (is_string($token) === false || ($header !== null && $formToken !== null && $header !== $formToken)) {
            throw new CheckoutInputException(RequestErrorCode::CSRF_INVALID);
        }

        if ($this->kirby->csrf($token) !== true) {
            throw new CheckoutInputException(RequestErrorCode::CSRF_INVALID);
        }
    }
}
