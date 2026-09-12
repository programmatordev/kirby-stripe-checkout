<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;

/**
 * Immutable SDK-independent parameters for one Checkout Session creation.
 *
 * Values stay limited to Stripe's scalar/list/map request vocabulary. The
 * gateway is the only boundary that converts this value into an SDK request.
 */
final readonly class SessionRequest
{
    /** Stripe request maps are shallow; this also terminates cyclic custom input. */
    private const MAX_NESTING_DEPTH = 16;

    /** @var array<string, mixed> */
    private array $parameters;

    private string $fingerprint;

    /** @param array<mixed, mixed> $parameters */
    public function __construct(array $parameters)
    {
        if ($parameters === [] || array_is_list($parameters)) {
            throw new InvalidArgumentException('Session request parameters must be a non-empty map.');
        }

        /** @var array<string, mixed> $normalized */
        $normalized = $this->normalize($parameters);
        $this->parameters = $normalized;
        $this->fingerprint = hash(
            'sha256',
            json_encode($this->parameters, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /** Stable digest of the recursively key-sorted request; list order is retained. */
    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    /**
     * @param array<mixed, mixed> $values
     * @return array<mixed, mixed>
     */
    private function normalize(array $values, int $depth = 0): array
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            throw new InvalidArgumentException('Session request parameters are nested too deeply.');
        }

        $list = array_is_list($values);
        $normalized = [];

        foreach ($values as $key => $value) {
            // Validate strings before JSON fingerprinting can surface a less useful encoding error.
            if (
                $list === false
                && (
                    is_string($key) === false
                    || $key === ''
                    || str_contains($key, "\0")
                    || TextValidator::isUtf8($key) === false
                )
            ) {
                throw new InvalidArgumentException('Session request map keys must be non-empty UTF-8 strings without null bytes.');
            }

            if (is_array($value)) {
                $normalized[$key] = $this->normalize($value, $depth + 1);

                continue;
            }

            if (is_string($value) && TextValidator::isUtf8($value) === false) {
                throw new InvalidArgumentException('Session request strings must use valid UTF-8.');
            }

            if (
                is_string($value) === false
                && is_int($value) === false
                && is_bool($value) === false
                && $value !== null
            ) {
                throw new InvalidArgumentException('Session request values must contain only strings, integers, booleans, null, lists, and maps.');
            }

            $normalized[$key] = $value;
        }

        if ($list === false) {
            ksort($normalized);
        }

        return $normalized;
    }
}
