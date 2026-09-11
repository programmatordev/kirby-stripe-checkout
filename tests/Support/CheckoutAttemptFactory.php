<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Support;

use DateInterval;
use DateTimeImmutable;
use LogicException;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartEntry;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\AttemptBinding;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\AttemptToken;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutAttempt;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;

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
        $productRequest = new ProductRequest('product');
        // Keep the binding independently valid so malformed CheckoutAttempt
        // actor combinations are rejected by the domain object under test.
        $bindingGuestReference = $order->userUuid() === null
            ? $guestReference ?? 'binding-guest'
            : null;
        $binding = $order->sourceType() === CheckoutSource::Cart
            ? AttemptBinding::cart(
                cart: new CartSnapshot(
                    id: 'cart',
                    revision: $order->cartRevision() ?? throw new LogicException('Cart attempts require a revision.'),
                    entries: [new CartEntry('item', $productRequest)],
                    createdAt: $createdAt->getTimestamp(),
                    updatedAt: $createdAt->getTimestamp(),
                ),
                contextFingerprint: hash('sha256', 'checkout-context'),
                userUuid: $order->userUuid(),
                guestReference: $bindingGuestReference,
            )
            : AttemptBinding::direct(
                items: [$productRequest],
                contextFingerprint: hash('sha256', 'checkout-context'),
                userUuid: $order->userUuid(),
                guestReference: $bindingGuestReference,
            );

        return new CheckoutAttempt(
            order: $order,
            context: $context,
            request: $request,
            binding: $binding,
            token: new AttemptToken(str_repeat('a', 32)),
            guestReference: $guestReference,
            stripeApiVersion: '2026-07-29.dahlia',
            credentialMode: CredentialMode::Test,
            credentialFingerprint: hash('sha256', 'test-credential'),
            createdAt: $createdAt,
        );
    }
}
