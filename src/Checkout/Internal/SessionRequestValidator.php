<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\InvalidSessionRequestException;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;

/** Enforces the payment, navigation, currency, and correlation safety floor. */
final class SessionRequestValidator
{
    private const PRIVATE_METADATA_PREFIX = 'kirby_stripe_checkout_';

    private const MAX_METADATA_ENTRIES = 50;

    private const MAX_METADATA_KEY_LENGTH = 40;

    private const MAX_METADATA_VALUE_LENGTH = 500;

    private const ADDITIVE_PAYMENT_INTENT_FIELDS = [
        'description',
        'receipt_email',
        'statement_descriptor_suffix',
    ];

    private const PROTECTED_TOP_LEVEL_FIELDS = [
        'client_reference_id',
        'currency',
        'expires_at',
        'integration_identifier',
        'locale',
        'mode',
        'ui_mode',
    ];

    // These parameters alter flows the current order and reconciliation models
    // do not yet represent, so even the advanced factory cannot enable them.
    private const UNSUPPORTED_TOP_LEVEL_FIELDS = [
        'adaptive_pricing',
        'after_expiration',
        'customer',
        'customer_account',
        'customer_creation',
        'customer_email',
        'customer_update',
        'excluded_payment_method_types',
        'managed_payments',
        'optional_items',
        'origin_context',
        'payment_method_collection',
        'payment_method_configuration',
        'payment_method_data',
        'payment_method_types',
        'permissions',
        'saved_payment_method_options',
        'setup_intent_data',
        'subscription_data',
    ];

    // Reject the same unsupported capabilities wherever Stripe nests them.
    private const UNSUPPORTED_NESTED_FIELDS = [
        'adjustable_quantity',
        'application_fee_amount',
        'application_fee_percent',
        'capture_method',
        'currency_options',
        'issuer',
        'liability',
        'on_behalf_of',
        'recurring',
        'setup_future_usage',
        'transfer_data',
        'transfer_group',
    ];

    /** @param array<mixed, mixed> $additions */
    public function applyAdditions(SessionRequest $request, array $additions): SessionRequest
    {
        $parameters = $request->parameters();

        foreach ($additions as $field => $value) {
            if (is_string($field) === false || $field === '') {
                throw new InvalidSessionRequestException('session_request.additions_invalid');
            }

            if ($field === 'metadata') {
                $parameters['metadata'] = $this->addMetadata(
                    $parameters['metadata'] ?? null,
                    $value,
                    'metadata',
                );

                continue;
            }

            if ($field === 'payment_intent_data') {
                $parameters['payment_intent_data'] = $this->addPaymentIntentData(
                    $parameters['payment_intent_data'] ?? null,
                    $value,
                );

                continue;
            }

            if (array_key_exists($field, $parameters)) {
                throw new InvalidSessionRequestException('session_request.parameter_protected', $field);
            }

            throw new InvalidSessionRequestException('session_request.parameter_unsupported', $field);
        }

        try {
            return $this->validate($request, new SessionRequest($parameters));
        } catch (InvalidArgumentException $error) {
            throw new InvalidSessionRequestException('session_request.additions_invalid', previous: $error);
        }
    }

    public function validate(SessionRequest $request, SessionRequest $customizedRequest): SessionRequest
    {
        $expected = $request->parameters();
        $parameters = $customizedRequest->parameters();

        foreach (self::PROTECTED_TOP_LEVEL_FIELDS as $field) {
            $this->assertSame($expected, $parameters, $field);
        }

        $this->validateNavigation($expected, $parameters);
        $this->validateUnsupportedFields($parameters);
        $this->validateMetadata($expected, $parameters);
        $this->validateLineItems($expected, $parameters);
        $this->validatePrivateMetadataLocations($parameters);

        return $customizedRequest;
    }

    /** @return array<string, mixed> */
    private function addMetadata(mixed $current, mixed $additions, string $path): array
    {
        if (
            is_array($current) === false
            || array_is_list($current)
            || is_array($additions) === false
            || ($additions !== [] && array_is_list($additions))
        ) {
            throw new InvalidSessionRequestException('session_request.additions_invalid', $path);
        }

        foreach ($additions as $key => $value) {
            $fieldPath = $path . '.' . (string) $key;

            if (
                is_string($key) === false
                || $key === ''
                || is_string($value) === false
                || str_starts_with($key, self::PRIVATE_METADATA_PREFIX)
            ) {
                throw new InvalidSessionRequestException('session_request.additions_invalid', $fieldPath);
            }

            if (array_key_exists($key, $current)) {
                throw new InvalidSessionRequestException('session_request.parameter_protected', $fieldPath);
            }

            $current[$key] = $value;
        }

        /** @var array<string, mixed> $current */
        $this->validateMetadataValues($current, $path, 'session_request.additions_invalid');

        return $current;
    }

    /** @return array<string, mixed> */
    private function addPaymentIntentData(mixed $current, mixed $additions): array
    {
        if (
            is_array($current) === false
            || array_is_list($current)
            || is_array($additions) === false
            || ($additions !== [] && array_is_list($additions))
        ) {
            throw new InvalidSessionRequestException(
                'session_request.additions_invalid',
                'payment_intent_data',
            );
        }

        foreach ($additions as $field => $value) {
            $path = 'payment_intent_data.' . (string) $field;

            if (is_string($field) === false || $field === '') {
                throw new InvalidSessionRequestException('session_request.additions_invalid', $path);
            }

            if ($field === 'metadata') {
                $current['metadata'] = $this->addMetadata(
                    $current['metadata'] ?? null,
                    $value,
                    $path,
                );

                continue;
            }

            if (array_key_exists($field, $current)) {
                throw new InvalidSessionRequestException('session_request.parameter_protected', $path);
            }

            if (in_array($field, self::ADDITIVE_PAYMENT_INTENT_FIELDS, true) === false) {
                throw new InvalidSessionRequestException('session_request.parameter_unsupported', $path);
            }

            if (is_string($value) === false || $value === '') {
                throw new InvalidSessionRequestException('session_request.additions_invalid', $path);
            }

            if ($field === 'receipt_email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidSessionRequestException('session_request.additions_invalid', $path);
            }

            $valueLength = grapheme_strlen($value);

            if (
                $field === 'statement_descriptor_suffix'
                && $valueLength !== false
                && $valueLength > 22
            ) {
                throw new InvalidSessionRequestException('session_request.additions_invalid', $path);
            }

            $current[$field] = $value;
        }

        /** @var array<string, mixed> $current */
        return $current;
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $parameters
     */
    private function validateNavigation(array $expected, array $parameters): void
    {
        $navigationFields = [
            'cancel_url',
            'redirect_on_completion',
            'return_url',
            'success_url',
        ];

        foreach ($navigationFields as $field) {
            if (array_key_exists($field, $expected)) {
                $this->assertSame($expected, $parameters, $field);

                continue;
            }

            if (array_key_exists($field, $parameters)) {
                throw new InvalidSessionRequestException('session_request.parameter_protected', $field);
            }
        }
    }

    /** @param array<string, mixed> $parameters */
    private function validateUnsupportedFields(array $parameters): void
    {
        foreach (self::UNSUPPORTED_TOP_LEVEL_FIELDS as $field) {
            if (array_key_exists($field, $parameters)) {
                throw new InvalidSessionRequestException('session_request.parameter_unsupported', $field);
            }
        }

        $this->findUnsupportedNestedField($parameters);
    }

    /** @param array<mixed, mixed> $values */
    private function findUnsupportedNestedField(array $values, string $path = ''): void
    {
        foreach ($values as $field => $value) {
            $field = (string) $field;
            $fieldPath = $path === '' ? $field : $path . '.' . $field;

            if (in_array($field, self::UNSUPPORTED_NESTED_FIELDS, true)) {
                throw new InvalidSessionRequestException('session_request.parameter_unsupported', $fieldPath);
            }

            // Metadata keys are project vocabulary, not Stripe request paths.
            if ($field === 'metadata') {
                continue;
            }

            if (is_array($value)) {
                $this->findUnsupportedNestedField($value, $fieldPath);
            }
        }
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $parameters
     */
    private function validateMetadata(array $expected, array $parameters): void
    {
        $this->assertProtectedMetadata(
            $expected['metadata'] ?? null,
            $parameters['metadata'] ?? null,
            'metadata',
        );

        $expectedPaymentIntent = $expected['payment_intent_data'] ?? null;
        $paymentIntent = $parameters['payment_intent_data'] ?? null;

        if (is_array($expectedPaymentIntent) === false || is_array($paymentIntent) === false) {
            throw new InvalidSessionRequestException(
                'session_request.invariant_violation',
                'payment_intent_data',
            );
        }

        $this->assertProtectedMetadata(
            $expectedPaymentIntent['metadata'] ?? null,
            $paymentIntent['metadata'] ?? null,
            'payment_intent_data.metadata',
        );
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $parameters
     */
    private function validateLineItems(array $expected, array $parameters): void
    {
        $expectedLines = $expected['line_items'] ?? null;
        $lines = $parameters['line_items'] ?? null;

        if (
            is_array($expectedLines) === false
            || array_is_list($expectedLines) === false
            || is_array($lines) === false
            || array_is_list($lines) === false
            || count($lines) !== count($expectedLines)
        ) {
            throw new InvalidSessionRequestException('session_request.invariant_violation', 'line_items');
        }

        foreach ($expectedLines as $index => $expectedLine) {
            $line = $lines[$index] ?? null;
            $path = 'line_items.' . $index;

            if (is_array($expectedLine) === false || is_array($line) === false) {
                throw new InvalidSessionRequestException('session_request.invariant_violation', $path);
            }

            /** @var array<string, mixed> $expectedLine */
            /** @var array<string, mixed> $line */
            $this->assertSame($expectedLine, $line, 'quantity', $path . '.quantity');
            $this->assertProtectedMetadata(
                $expectedLine['metadata'] ?? null,
                $line['metadata'] ?? null,
                $path . '.metadata',
            );

            if (array_key_exists('price', $expectedLine)) {
                $this->assertSame($expectedLine, $line, 'price', $path . '.price');

                if (array_key_exists('price_data', $line)) {
                    throw new InvalidSessionRequestException(
                        'session_request.invariant_violation',
                        $path . '.price_data',
                    );
                }

                continue;
            }

            $expectedPrice = $expectedLine['price_data'] ?? null;
            $price = $line['price_data'] ?? null;

            if (is_array($expectedPrice) === false || is_array($price) === false) {
                throw new InvalidSessionRequestException(
                    'session_request.invariant_violation',
                    $path . '.price_data',
                );
            }

            /** @var array<string, mixed> $expectedPrice */
            /** @var array<string, mixed> $price */
            $this->assertSame($expectedPrice, $price, 'currency', $path . '.price_data.currency');
            $this->assertSame($expectedPrice, $price, 'unit_amount', $path . '.price_data.unit_amount');

            if (array_key_exists('price', $line)) {
                throw new InvalidSessionRequestException('session_request.invariant_violation', $path . '.price');
            }
        }
    }

    private function assertProtectedMetadata(mixed $expected, mixed $actual, string $path): void
    {
        if (
            is_array($expected) === false
            || array_is_list($expected)
            || is_array($actual) === false
            || array_is_list($actual)
        ) {
            throw new InvalidSessionRequestException('session_request.invariant_violation', $path);
        }

        foreach ($expected as $key => $value) {
            if (
                is_string($key)
                && str_starts_with($key, self::PRIVATE_METADATA_PREFIX)
                && ($actual[$key] ?? null) !== $value
            ) {
                throw new InvalidSessionRequestException(
                    'session_request.invariant_violation',
                    $path . '.' . $key,
                );
            }
        }

        foreach ($actual as $key => $value) {
            if (
                is_string($key)
                && str_starts_with($key, self::PRIVATE_METADATA_PREFIX)
                && array_key_exists($key, $expected) === false
            ) {
                throw new InvalidSessionRequestException(
                    'session_request.parameter_protected',
                    $path . '.' . $key,
                );
            }
        }

        /** @var array<string, mixed> $actual */
        $this->validateMetadataValues($actual, $path, 'session_request.invariant_violation');
    }

    /** @param array<string, mixed> $metadata */
    private function validateMetadataValues(array $metadata, string $path, string $errorCode): void
    {
        if (count($metadata) > self::MAX_METADATA_ENTRIES) {
            throw new InvalidSessionRequestException($errorCode, $path);
        }

        foreach ($metadata as $key => $value) {
            $keyLength = grapheme_strlen($key);
            $valueLength = is_string($value) ? grapheme_strlen($value) : false;

            if (
                $key === ''
                || ($keyLength !== false && $keyLength > self::MAX_METADATA_KEY_LENGTH)
                || str_contains($key, '[')
                || str_contains($key, ']')
                || is_string($value) === false
                || ($valueLength !== false && $valueLength > self::MAX_METADATA_VALUE_LENGTH)
            ) {
                throw new InvalidSessionRequestException($errorCode, $path . '.' . (string) $key);
            }
        }
    }

    /** @param array<mixed, mixed> $values */
    private function validatePrivateMetadataLocations(array $values, string $path = ''): void
    {
        foreach ($values as $key => $value) {
            $key = (string) $key;
            $fieldPath = $path === '' ? $key : $path . '.' . $key;

            if (str_starts_with($key, self::PRIVATE_METADATA_PREFIX)) {
                $allowed = $path === 'metadata'
                    || $path === 'payment_intent_data.metadata'
                    || preg_match('/\Aline_items\.\d+\.metadata\z/D', $path) === 1;

                if ($allowed === false) {
                    throw new InvalidSessionRequestException(
                        'session_request.parameter_protected',
                        $fieldPath,
                    );
                }
            }

            if (is_array($value)) {
                $this->validatePrivateMetadataLocations($value, $fieldPath);
            }
        }
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private function assertSame(
        array $expected,
        array $actual,
        string $field,
        ?string $path = null,
    ): void {
        if (
            array_key_exists($field, $expected) === false
            || array_key_exists($field, $actual) === false
            || $actual[$field] !== $expected[$field]
        ) {
            throw new InvalidSessionRequestException(
                'session_request.invariant_violation',
                $path ?? $field,
            );
        }
    }
}
