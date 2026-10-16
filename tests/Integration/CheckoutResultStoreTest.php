<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutResultReason;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\BrowserAttemptContext;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutResultStore;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;

final class CheckoutResultStoreTest extends KirbyTestCase
{
    public function testResultRequiresItsActorAndDestinationAndIsConsumedOnce(): void
    {
        $savedAt = new DateTimeImmutable('2026-10-16T10:00:00Z');
        $store = new CheckoutResultStore($this->kirby->session());
        $context = $this->context();
        $reference = $store->save(
            reason: CheckoutResultReason::HostedCancel,
            context: $context,
            pageUuid: null,
            destinationUrl: 'https://kirby-stripe-checkout.test/cancelled?from=checkout',
            savedAt: $savedAt,
        );

        $this->assertNull($store->consume(
            reference: $reference,
            userUuid: null,
            guestReference: 'another-browser',
            destinationUrl: 'https://kirby-stripe-checkout.test/cancelled?from=checkout',
            checkedAt: $savedAt,
        ));
        $this->assertNull($store->consume(
            reference: $reference,
            userUuid: null,
            guestReference: $context->guestReference(),
            destinationUrl: 'https://kirby-stripe-checkout.test/another-page',
            checkedAt: $savedAt,
        ));

        $result = $store->consume(
            reference: $reference,
            userUuid: null,
            guestReference: $context->guestReference(),
            destinationUrl: 'https://kirby-stripe-checkout.test/cancelled?from=checkout',
            checkedAt: $savedAt,
        );
        $this->assertNotNull($result);
        $this->assertSame(CheckoutResultReason::HostedCancel, $result->reason());
        $this->assertNull($store->consume(
            reference: $reference,
            userUuid: null,
            guestReference: $context->guestReference(),
            destinationUrl: 'https://kirby-stripe-checkout.test/cancelled?from=checkout',
            checkedAt: $savedAt,
        ));
    }

    public function testExpiredAndCapacityResultsArePrunedWithoutChangingOtherSessionData(): void
    {
        $session = $this->kirby->session();
        $session->data()->set('other', 'keep');
        $savedAt = new DateTimeImmutable('2026-10-16T10:00:00Z');
        $store = new CheckoutResultStore($session);
        $context = $this->context();
        $firstReference = $store->save(
            reason: CheckoutResultReason::HostedCancel,
            context: $context,
            pageUuid: null,
            destinationUrl: 'https://kirby-stripe-checkout.test/cancelled',
            savedAt: $savedAt,
        );

        for ($index = 1; $index <= 100; $index++) {
            $latestReference = $store->save(
                reason: CheckoutResultReason::HostedCancel,
                context: $context,
                pageUuid: null,
                destinationUrl: 'https://kirby-stripe-checkout.test/cancelled',
                savedAt: $savedAt,
            );
        }

        $this->assertNull($store->consume(
            reference: $firstReference,
            userUuid: null,
            guestReference: $context->guestReference(),
            destinationUrl: 'https://kirby-stripe-checkout.test/cancelled',
            checkedAt: $savedAt,
        ));
        $this->assertNotNull($store->consume(
            reference: $latestReference,
            userUuid: null,
            guestReference: $context->guestReference(),
            destinationUrl: 'https://kirby-stripe-checkout.test/cancelled',
            checkedAt: $savedAt,
        ));

        $expiredReference = $store->save(
            reason: CheckoutResultReason::HostedCancel,
            context: $context,
            pageUuid: null,
            destinationUrl: 'https://kirby-stripe-checkout.test/cancelled',
            savedAt: $savedAt,
        );
        $this->assertNull($store->consume(
            reference: $expiredReference,
            userUuid: null,
            guestReference: $context->guestReference(),
            destinationUrl: 'https://kirby-stripe-checkout.test/cancelled',
            checkedAt: $savedAt->modify('+15 minutes'),
        ));
        $this->assertSame('keep', $session->data()->get('other'));
    }

    private function context(): BrowserAttemptContext
    {
        return BrowserAttemptContext::capture(
            checkoutSource: CheckoutSource::Direct,
            uiMode: UiMode::Hosted,
            languageCode: null,
            userUuid: null,
            csrf: 'native-csrf',
            cart: null,
        );
    }
}
