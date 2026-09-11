<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Support;

use DateInterval;
use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\AttemptToken;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutAttempt;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;

/** Constructs complete deterministic attempts for order persistence tests. */
final class CheckoutAttemptFactory
{
    public static function create(
        OrderCreationContext $order,
        DateTimeImmutable $createdAt,
        ?string $guestReference = 'guest',
    ): CheckoutAttempt {
        $context = new SessionRequestContext(
            order: $order,
            locale: 'auto',
            expiresAt: $createdAt->add(new DateInterval(CheckoutAttempt::SESSION_LIFETIME)),
            initiatingUrl: 'https://example.test/products',
            successDestination: 'https://example.test/success',
            cancelDestination: 'https://example.test/cancel',
            returnDestination: 'https://example.test/return',
        );
        $request = new SessionRequest([
            'client_reference_id' => $order->pageUuid(),
            'currency' => strtolower($order->currency()),
            'expires_at' => $context->expiresAt()->getTimestamp(),
            'mode' => 'payment',
        ]);

        return new CheckoutAttempt(
            order: $order,
            context: $context,
            request: $request,
            token: new AttemptToken(str_repeat('a', 32)),
            guestReference: $guestReference,
            stripeApiVersion: '2026-07-29.dahlia',
            createdAt: $createdAt,
        );
    }
}
