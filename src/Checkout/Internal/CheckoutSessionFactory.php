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
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Plugin\PluginMetadata;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionRecord;
use Stripe\Checkout\Session;

/** Correlates provider Sessions and verifies the data needed to open Checkout. */
final class CheckoutSessionFactory
{
    public function create(
        CheckoutSessionRecord $record,
        SessionRequestContext $context,
        SessionRequest $request,
        ?bool $liveMode,
    ): CheckoutSession {
        $canOpenCheckout = match ($context->uiMode()) {
            UiMode::Hosted => CheckoutUrlValidator::isHostedCheckoutUrl($record->url) && $record->clientSecret === null,
            UiMode::Embedded => is_string($record->clientSecret)
                && trim($record->clientSecret) !== ''
                && $record->url === null,
        };

        if (
            $record->expiresAt !== $context->expiresAt()->getTimestamp()
            || $record->status !== Session::STATUS_OPEN
            || in_array($record->paymentStatus, [
                Session::PAYMENT_STATUS_UNPAID,
                Session::PAYMENT_STATUS_NO_PAYMENT_REQUIRED,
            ], true) === false
            || $canOpenCheckout === false
        ) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }

        return new CheckoutSession(
            association: $this->association($record, $context->order(), $request, $liveMode),
            url: $record->url,
            clientSecret: $record->clientSecret,
        );
    }

    /**
     * Shared purchase correlation; historical reads do not require a hosted URL or embedded client secret.
     *
     * @param bool|null $liveMode Expected credential mode. Null means it cannot be inferred;
     *                           the returned Session must still declare its actual mode.
     */
    public function association(
        CheckoutSessionRecord $record,
        OrderCreationContext $order,
        SessionRequest $request,
        ?bool $liveMode,
    ): CheckoutSessionAssociation {
        $this->validateSessionState(record: $record, request: $request);

        if ($record->liveMode === null || $liveMode !== null && $record->liveMode !== $liveMode) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }

        $this->validatePurchaseCorrelation(record: $record, order: $order, request: $request);

        if ($record->requestId !== null && trim($record->requestId) === '') {
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

        return $association;
    }

    private function validateSessionState(CheckoutSessionRecord $record, SessionRequest $request): void
    {
        if ($record->createdAt === null || $record->createdAt < 0) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }

        if ($record->expiresAt === null || $record->expiresAt <= $record->createdAt) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }

        if ($record->expiresAt !== ($request->parameters()['expires_at'] ?? null)) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }

        // Historical reads include closed Sessions; create() separately requires a Session that can still open Checkout.
        if (in_array($record->status, [Session::STATUS_OPEN, Session::STATUS_COMPLETE, Session::STATUS_EXPIRED], true) === false) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }

        if (in_array($record->paymentStatus, [Session::PAYMENT_STATUS_PAID, Session::PAYMENT_STATUS_UNPAID, Session::PAYMENT_STATUS_NO_PAYMENT_REQUIRED], true) === false) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }
    }

    private function validatePurchaseCorrelation(
        CheckoutSessionRecord $record,
        OrderCreationContext $order,
        SessionRequest $request,
    ): void {
        $parameters = $request->parameters();
        // Correlate only plugin-owned metadata; merchant keys and additional provider metadata need not match the saved request.
        $expectedMetadata = array_filter(
            is_array($parameters['metadata'] ?? null) ? $parameters['metadata'] : [],
            static fn(mixed $value, mixed $key): bool => is_string($key)
                && str_starts_with($key, PluginMetadata::KEY_PREFIX),
            ARRAY_FILTER_USE_BOTH,
        );
        $uiMode = match ($order->uiMode()) {
            UiMode::Hosted => Session::UI_MODE_HOSTED_PAGE,
            UiMode::Embedded => Session::UI_MODE_EMBEDDED_PAGE,
        };

        if ($record->mode !== Session::MODE_PAYMENT) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }

        if ($record->uiMode !== $uiMode) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }

        if (strtoupper((string) $record->currency) !== $order->currency()) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }

        if ($record->clientReferenceId !== $order->pageUuid()) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }

        if ($record->integrationIdentifier !== ($parameters['integration_identifier'] ?? null)) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }

        if (
            ($record->metadata[PluginMetadata::OWNER_KEY] ?? null) !== PluginMetadata::NAME
            || ($record->metadata[PluginMetadata::ORDER_KEY] ?? null) !== $order->pageUuid()
        ) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }

        if ($this->hasExpectedMetadata($record->metadata, $expectedMetadata) === false) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }
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

            // The gateway reduces expanded Rates to IDs; all references remain untrusted until the association validates them against the request.
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
