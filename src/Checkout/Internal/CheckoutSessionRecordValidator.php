<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionRecord;
use Stripe\Checkout\Session;

/** Validates the minimal correlation and presentation facts returned by Session creation/retrieval. */
final class CheckoutSessionRecordValidator
{
    private const PRIVATE_METADATA_PREFIX = 'kirby_stripe_checkout_';

    public function validate(
        CheckoutSessionRecord $sessionRecord,
        SessionRequestContext $context,
        SessionRequest $request,
        ?bool $liveMode,
    ): void {
        $parameters = $request->parameters();
        // Custom metadata is an escape hatch. Only the private keys establish
        // ownership and correlation, so those are the keys this boundary requires.
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
            UiMode::Hosted => CheckoutUrlValidator::isHostedPresentation($sessionRecord->url) && $sessionRecord->clientSecret === null,
            UiMode::Embedded => is_string($sessionRecord->clientSecret)
                && trim($sessionRecord->clientSecret) !== ''
                && $sessionRecord->url === null,
        };

        if (
            is_string($sessionRecord->id) === false
            || preg_match('/\Acs_[A-Za-z0-9_]+\z/', $sessionRecord->id) !== 1
            || $sessionRecord->createdAt === null
            || $sessionRecord->createdAt < 0
            || $sessionRecord->expiresAt !== $context->expiresAt()->getTimestamp()
            || $sessionRecord->status !== Session::STATUS_OPEN
            || in_array($sessionRecord->paymentStatus, [
                Session::PAYMENT_STATUS_UNPAID,
                Session::PAYMENT_STATUS_NO_PAYMENT_REQUIRED,
            ], true) === false
            || $liveMode !== null && $sessionRecord->liveMode !== $liveMode
            || $sessionRecord->mode !== Session::MODE_PAYMENT
            || $sessionRecord->uiMode !== $uiMode
            || strtolower((string) $sessionRecord->currency) !== strtolower($context->order()->currency())
            || $sessionRecord->clientReferenceId !== $context->order()->pageUuid()
            || $sessionRecord->integrationIdentifier !== ($parameters['integration_identifier'] ?? null)
            || $this->hasExpectedMetadata($sessionRecord->metadata, $expectedMetadata) === false
            || $hasPresentation === false
            || ($sessionRecord->requestId !== null && trim($sessionRecord->requestId) === '')
        ) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }
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
