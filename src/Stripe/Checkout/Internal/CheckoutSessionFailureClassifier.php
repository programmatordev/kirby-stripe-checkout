<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailure;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailureType;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\RateLimitException;
use Stripe\Util\CaseInsensitiveArray;
use Throwable;

/** @internal Converts stripe-php failures into the plugin's sanitized categories. */
final class CheckoutSessionFailureClassifier
{
    public function classify(Throwable $error, bool $mutation): CheckoutSessionFailure
    {
        $retryDirective = $error instanceof ApiErrorException
            ? $this->retryDirective($error)
            : null;

        // A failed read can be repeated safely. A transport or server failure
        // during creation is uncertain because Stripe may have accepted the POST.
        $type = match (true) {
            $error instanceof RateLimitException => CheckoutSessionFailureType::Unavailable,
            $error instanceof ApiConnectionException => $mutation
                ? CheckoutSessionFailureType::Uncertain
                : CheckoutSessionFailureType::Unavailable,
            $error instanceof ApiErrorException && ($error->getHttpStatus() === null || $error->getHttpStatus() >= 500) => $mutation
                ? CheckoutSessionFailureType::Uncertain
                : CheckoutSessionFailureType::Unavailable,
            $error instanceof ApiErrorException && $retryDirective === true => CheckoutSessionFailureType::Unavailable,
            $error instanceof ApiErrorException && $error->getHttpStatus() === 409 && $retryDirective !== false => CheckoutSessionFailureType::Unavailable,
            $error instanceof ApiErrorException => CheckoutSessionFailureType::Rejected,
            default => $mutation
                ? CheckoutSessionFailureType::Uncertain
                : CheckoutSessionFailureType::Unavailable,
        };
        $stripeApiError = $error instanceof ApiErrorException ? $error : null;
        $providerType = $stripeApiError?->getError()?->type;

        return CheckoutSessionFailure::fromProvider(
            type: $type,
            retryable: $retryDirective ?? $this->fallbackRetryable($error),
            requestId: $stripeApiError?->getRequestId(),
            providerCode: $stripeApiError?->getStripeCode(),
            providerType: $providerType,
        );
    }

    private function fallbackRetryable(Throwable $error): bool
    {
        if ($error instanceof ApiConnectionException || $error instanceof RateLimitException) {
            return true;
        }

        if ($error instanceof ApiErrorException) {
            $status = $error->getHttpStatus();

            return $status === null
                || $status === 409
                || $status === 429
                || $status >= 500;
        }

        return false;
    }

    /**
     * Stripe's explicit response directive overrides status-based retry policy.
     * stripe-php already honors it for in-process retries; the plugin also needs
     * it for a later request that resumes the persisted attempt.
     */
    private function retryDirective(ApiErrorException $error): ?bool
    {
        $headers = $error->getHttpHeaders();
        $value = null;

        if ($headers instanceof CaseInsensitiveArray) {
            $value = $headers['stripe-should-retry'];
        } elseif (is_array($headers)) {
            foreach ($headers as $name => $header) {
                if (is_string($name) && strtolower($name) === 'stripe-should-retry') {
                    $value = $header;

                    break;
                }
            }
        }

        return match ($value) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }
}
