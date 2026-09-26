<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\CheckoutSessionAssociation;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionRecord;
use Stripe\Checkout\Session;

/** Converts one untrusted provider record into a validated Checkout Session. */
final class CheckoutSessionFactory
{
    private const PRIVATE_METADATA_PREFIX = 'kirby_stripe_checkout_';

    public function create(
        CheckoutSessionRecord $record,
        SessionRequestContext $context,
        SessionRequest $request,
        ?bool $liveMode,
    ): CheckoutSession {
        $parameters = $request->parameters();
        $expectedMetadata = array_filter(
            is_array($parameters['metadata'] ?? null) ? $parameters['metadata'] : [],
            static fn(mixed $value, mixed $key): bool => is_string($key)
                && str_starts_with($key, self::PRIVATE_METADATA_PREFIX),
            ARRAY_FILTER_USE_BOTH,
        );
        $uiMode = match ($context->uiMode()) {
            UiMode::Hosted => Session::UI_MODE_HOSTED_PAGE,
            UiMode::Embedded => Session::UI_MODE_EMBEDDED_PAGE,
        };
        $hasPresentation = match ($context->uiMode()) {
            UiMode::Hosted => CheckoutUrlValidator::isHostedPresentation($record->url) && $record->clientSecret === null,
            UiMode::Embedded => is_string($record->clientSecret)
                && trim($record->clientSecret) !== ''
                && $record->url === null,
        };

        if (
            $record->createdAt === null
            || $record->createdAt < 0
            || $record->expiresAt !== $context->expiresAt()->getTimestamp()
            || $record->status !== Session::STATUS_OPEN
            || in_array($record->paymentStatus, [
                Session::PAYMENT_STATUS_UNPAID,
                Session::PAYMENT_STATUS_NO_PAYMENT_REQUIRED,
            ], true) === false
            || $liveMode !== null && $record->liveMode !== $liveMode
            || $record->mode !== Session::MODE_PAYMENT
            || $record->uiMode !== $uiMode
            || strtolower((string) $record->currency) !== strtolower($context->order()->currency())
            || $record->clientReferenceId !== $context->order()->pageUuid()
            || $record->integrationIdentifier !== ($parameters['integration_identifier'] ?? null)
            || $this->hasExpectedMetadata($record->metadata, $expectedMetadata) === false
            || $hasPresentation === false
            || ($record->requestId !== null && trim($record->requestId) === '')
        ) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }

        try {
            $association = new CheckoutSessionAssociation(
                sessionId: $record->id ?? '',
                shippingRateIds: $this->shippingRateIds($record, $request),
                request: $request,
            );
        } catch (OrderDataException) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }

        return new CheckoutSession(
            association: $association,
            url: $record->url,
            clientSecret: $record->clientSecret,
        );
    }

    /**
     * @return list<mixed>
     */
    private function shippingRateIds(
        CheckoutSessionRecord $record,
        SessionRequest $request,
    ): array {
        $expectedOptions = $request->parameters()['shipping_options'] ?? null;
        $shippingOptions = $record->shippingOptions;

        if ($expectedOptions === null) {
            if ($shippingOptions !== null && $shippingOptions !== []) {
                throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
            }

            return [];
        }

        if (
            is_array($expectedOptions) === false
            || array_is_list($expectedOptions) === false
            || is_array($shippingOptions) === false
            || array_is_list($shippingOptions) === false
            || count($shippingOptions) !== count($expectedOptions)
        ) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }

        $ids = [];

        foreach ($shippingOptions as $shippingOption) {
            if (is_array($shippingOption) === false || array_is_list($shippingOption)) {
                throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
            }

            // The gateway reduces expanded Rates to IDs; all references remain
            // untrusted until the association validates them against the request.
            $ids[] = $shippingOption['shipping_rate'] ?? null;
        }

        return $ids;
    }

    /**
     * Provider map ordering is not part of the metadata contract.
     *
     * @param array<string, mixed> $actual
     * @param array<string, mixed> $expected
     */
    private function hasExpectedMetadata(array $actual, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if (array_key_exists($key, $actual) === false || $actual[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
