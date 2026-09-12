<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\Exception\InvalidSessionRequestException;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;

/** Validates supported parameters and the invariants required by the Checkout lifecycle. */
final class SessionRequestValidator
{
    private const PRIVATE_METADATA_PREFIX = 'kirby_stripe_checkout_';

    private const PROTECTED_TOP_LEVEL_FIELDS = [
        'client_reference_id',
        'currency',
        'expires_at',
        'integration_identifier',
        'locale',
        'mode',
        'ui_mode',
    ];

    /**
     * Provider-managed state that the current Order model cannot reconcile yet.
     *
     * Adaptive pricing needs presentment snapshots; recovery can create another
     * Session; optional items can change saved lines; server-only shipping needs
     * a Session update flow; and saved methods need an explicit customer/consent
     * contract. Managed Payments changes the merchant-of-record model entirely.
     * A static payment-method list bypasses Stripe's Dashboard-managed dynamic
     * selection.
     *
     * @see https://docs.stripe.com/payments/currencies/localize-prices/adaptive-pricing
     * @see https://docs.stripe.com/payments/checkout/abandoned-carts
     * @see https://docs.stripe.com/payments/checkout/optional-items
     * @see https://docs.stripe.com/payments/checkout/custom-shipping-options
     * @see https://docs.stripe.com/payments/checkout/save-during-payment
     * @see https://docs.stripe.com/payments/managed-payments
     * @see https://docs.stripe.com/payments/payment-methods/dynamic-payment-methods
     */
    private const PROHIBITED_TOP_LEVEL_FIELDS = [
        'adaptive_pricing',
        'after_expiration',
        'managed_payments',
        'optional_items',
        'payment_method_types',
        'permissions',
        'saved_payment_method_options',
    ];

    /** Settlement, capture and future-use paths outside the current payment lifecycle. */
    private const PROHIBITED_PAYMENT_INTENT_FIELDS = [
        'application_fee_amount',
        'application_fee_percent',
        'capture_method',
        'on_behalf_of',
        'setup_future_usage',
        'transfer_data',
        'transfer_group',
    ];

    /** Payment-method-specific forms of unsupported capture and future-use behavior. */
    private const PROHIBITED_PAYMENT_METHOD_OPTION_FIELDS = [
        'capture_method',
        'setup_future_usage',
    ];

    public function __construct(
        private readonly SupportedSessionParametersValidator $supportedParametersValidator = new SupportedSessionParametersValidator(),
    ) {}

    public function validate(SessionRequest $request, SessionRequest $customizedRequest): SessionRequest
    {
        $expected = $request->parameters();
        $parameters = $customizedRequest->parameters();

        foreach (self::PROTECTED_TOP_LEVEL_FIELDS as $field) {
            $this->assertSame($expected, $parameters, $field);
        }

        $this->validateNavigation($expected, $parameters);
        $this->validateProhibitedParameters($parameters);
        $this->validateMetadata($expected, $parameters);
        $this->validateLineItems($expected, $parameters);
        $this->validatePrivateMetadataLocations($parameters);
        $this->supportedParametersValidator->validate($parameters);

        return $customizedRequest;
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
    private function validateProhibitedParameters(array $parameters): void
    {
        foreach (self::PROHIBITED_TOP_LEVEL_FIELDS as $field) {
            $this->assertAbsent($parameters, $field, $field);
        }

        $paymentIntentData = $parameters['payment_intent_data'] ?? null;

        if (is_array($paymentIntentData) && array_is_list($paymentIntentData) === false) {
            foreach (self::PROHIBITED_PAYMENT_INTENT_FIELDS as $field) {
                $this->assertAbsent(
                    $paymentIntentData,
                    $field,
                    'payment_intent_data.' . $field,
                );
            }
        }

        $automaticTax = $parameters['automatic_tax'] ?? null;

        if (is_array($automaticTax) && array_is_list($automaticTax) === false) {
            $this->assertAbsent($automaticTax, 'liability', 'automatic_tax.liability');
        }

        $invoiceCreation = $parameters['invoice_creation'] ?? null;

        if (is_array($invoiceCreation) && array_is_list($invoiceCreation) === false) {
            $invoiceData = $invoiceCreation['invoice_data'] ?? null;

            if (is_array($invoiceData) && array_is_list($invoiceData) === false) {
                // Stripe can assign invoice branding and support details to a
                // connected account, which is outside the one-merchant model.
                // https://docs.stripe.com/api/checkout/sessions/create?query=invoice_creation.invoice_data.issuer
                $this->assertAbsent(
                    $invoiceData,
                    'issuer',
                    'invoice_creation.invoice_data.issuer',
                );
            }
        }

        $paymentMethodOptions = $parameters['payment_method_options'] ?? null;

        if (is_array($paymentMethodOptions) && array_is_list($paymentMethodOptions) === false) {
            foreach ($paymentMethodOptions as $paymentMethod => $options) {
                if (is_array($options) && array_is_list($options) === false) {
                    foreach (self::PROHIBITED_PAYMENT_METHOD_OPTION_FIELDS as $field) {
                        $this->assertAbsent(
                            $options,
                            $field,
                            'payment_method_options.' . (string) $paymentMethod . '.' . $field,
                        );
                    }
                }
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

        if (
            is_array($expectedPaymentIntent) === false
            || array_is_list($expectedPaymentIntent)
            || is_array($paymentIntent) === false
            || array_is_list($paymentIntent)
        ) {
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
            $this->assertAbsent($line, 'adjustable_quantity', $path . '.adjustable_quantity');
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
            $this->assertAbsent($price, 'currency_options', $path . '.price_data.currency_options');
            $this->assertAbsent($price, 'recurring', $path . '.price_data.recurring');

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

    /** @param array<mixed, mixed> $values */
    private function assertAbsent(array $values, string $field, string $path): void
    {
        if (array_key_exists($field, $values)) {
            throw new InvalidSessionRequestException('session_request.parameter_protected', $path);
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
