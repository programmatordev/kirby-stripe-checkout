<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailure;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailureType;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\RateLimitException;
use Throwable;

/** @internal Converts stripe-php failures into the plugin's sanitized categories. */
final class CheckoutSessionFailureClassifier
{
    public function classify(Throwable $error, bool $mutation): CheckoutSessionFailure
    {
        // A failed read can be repeated safely. A transport or server failure
        // during creation is uncertain because Stripe may have accepted the POST.
        $type = match (true) {
            $error instanceof RateLimitException => CheckoutSessionFailureType::Retryable,
            // stripe-php retries HTTP 409 conflicts. If all client retries are
            // exhausted, repeating the exact idempotent request remains safe.
            $error instanceof ApiErrorException && $error->getHttpStatus() === 409 => CheckoutSessionFailureType::Retryable,
            $error instanceof ApiConnectionException => $mutation
                ? CheckoutSessionFailureType::Uncertain
                : CheckoutSessionFailureType::Unavailable,
            $error instanceof ApiErrorException && ($error->getHttpStatus() === null || $error->getHttpStatus() >= 500) => $mutation
                ? CheckoutSessionFailureType::Uncertain
                : CheckoutSessionFailureType::Unavailable,
            $error instanceof ApiErrorException => CheckoutSessionFailureType::Rejected,
            default => $mutation
                ? CheckoutSessionFailureType::Uncertain
                : CheckoutSessionFailureType::Unavailable,
        };
        $stripeApiError = $error instanceof ApiErrorException ? $error : null;
        $providerType = $stripeApiError?->getError()?->type;

        return CheckoutSessionFailure::fromProvider(
            type: $type,
            requestId: $stripeApiError?->getRequestId(),
            providerCode: $stripeApiError?->getStripeCode(),
            providerType: $providerType,
        );
    }
}
