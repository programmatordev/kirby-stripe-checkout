<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionRecord;

/** Validates the minimal correlation and presentation facts returned by Session creation/retrieval. */
final class CheckoutSessionRecordValidator
{
    private const PRIVATE_METADATA_PREFIX = 'kirby_stripe_checkout_';

    public function validate(
        CheckoutSessionRecord $sessionRecord,
        SessionRequestContext $context,
        SessionRequest $request,
        bool $liveMode,
    ): void {
        $parameters = $request->parameters();
        // Project metadata is an escape hatch. Only the private keys establish
        // ownership and correlation, so those are the keys this boundary requires.
        $expectedMetadata = array_filter(
            is_array($parameters['metadata'] ?? null) ? $parameters['metadata'] : [],
            static fn(mixed $value, mixed $key): bool => is_string($key)
                && str_starts_with($key, self::PRIVATE_METADATA_PREFIX),
            ARRAY_FILTER_USE_BOTH,
        );
        $uiMode = match ($context->uiMode()) {
            UiMode::Hosted => 'hosted_page',
            UiMode::Embedded => 'embedded_page',
        };
        $hasPresentation = match ($context->uiMode()) {
            UiMode::Hosted => $this->isHttpUrl($sessionRecord->url) && $sessionRecord->clientSecret === null,
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
            || $sessionRecord->status !== 'open'
            || in_array($sessionRecord->paymentStatus, ['unpaid', 'no_payment_required'], true) === false
            || $sessionRecord->liveMode !== $liveMode
            || $sessionRecord->mode !== 'payment'
            || $sessionRecord->uiMode !== $uiMode
            || strtolower((string) $sessionRecord->currency) !== strtolower($context->order()->currency())
            || $sessionRecord->clientReferenceId !== $context->order()->pageUuid()
            || $sessionRecord->integrationIdentifier !== ($parameters['integration_identifier'] ?? null)
            || array_intersect_key($sessionRecord->metadata, $expectedMetadata) !== $expectedMetadata
            || $hasPresentation === false
            || ($sessionRecord->requestId !== null && trim($sessionRecord->requestId) === '')
        ) {
            throw new CheckoutSessionException('checkout.session_incompatible');
        }
    }

    private function isHttpUrl(?string $value): bool
    {
        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
