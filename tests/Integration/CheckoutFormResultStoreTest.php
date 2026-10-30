<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutError;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\BrowserAttempt;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\BrowserAttemptContext;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\BrowserAttemptStore;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutFormResultStore;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSubmissionInput;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;

final class CheckoutFormResultStoreTest extends KirbyTestCase
{
    public function testExpiredResultsArePrunedWithoutRemovingOtherSessionData(): void
    {
        $session = $this->kirby->session();
        $session->data()->set('other', 'keep');
        $savedAt = new DateTimeImmutable('2026-10-16T10:00:00Z');
        $attempt = $this->attempt($savedAt);
        $store = new CheckoutFormResultStore($session);
        $expiresAt = BrowserAttemptStore::retainedUntil($attempt);
        $reference = $store->save($this->error(), $attempt, null, $savedAt, $expiresAt);
        $this->assertNotNull($store->find($reference, $savedAt));
        $this->assertNull($store->find($reference, new DateTimeImmutable('@' . $expiresAt)));
        $this->assertSame('keep', $session->data()->get('other'));
    }

    public function testCapacitySkipsAnExactRetryAndEvictsTheOldestOrdinaryResult(): void
    {
        $savedAt = new DateTimeImmutable('2026-10-16T10:00:00Z');
        $attempt = $this->attempt($savedAt);
        $store = new CheckoutFormResultStore($this->kirby->session());
        $expiresAt = BrowserAttemptStore::retainedUntil($attempt);
        $protectedReference = $store->save($this->error(), $attempt, $this->retryInput($attempt), $savedAt, $expiresAt);
        $oldestOrdinaryReference = $store->save($this->error(), $attempt, null, $savedAt, $expiresAt);

        for ($index = 2; $index < 100; $index++) {
            $store->save($this->error(), $attempt, null, $savedAt, $expiresAt);
        }

        $latestReference = $store->save($this->error(), $attempt, null, $savedAt, $expiresAt);
        $this->assertNotNull($store->find($protectedReference, $savedAt));
        $this->assertNull($store->find($oldestOrdinaryReference, $savedAt));
        $this->assertNotNull($store->find($latestReference, $savedAt));
    }

    public function testCapacityNeverEvictsAnExactRetryResult(): void
    {
        $savedAt = new DateTimeImmutable('2026-10-16T10:00:00Z');
        $attempt = $this->attempt($savedAt);
        $store = new CheckoutFormResultStore($this->kirby->session());
        $expiresAt = BrowserAttemptStore::retainedUntil($attempt);
        $retryInput = $this->retryInput($attempt);
        $firstReference = $store->save($this->error(), $attempt, $retryInput, $savedAt, $expiresAt);

        for ($index = 1; $index < 100; $index++) {
            $store->save($this->error(), $attempt, $retryInput, $savedAt, $expiresAt);
        }

        try {
            $store->save($this->error(), $attempt, null, $savedAt, $expiresAt);
            $this->fail('An exact retry result must not be evicted.');
        } catch (CheckoutInputException $error) {
            $this->assertSame(CheckoutErrorCode::ATTEMPT_LIMIT_REACHED, $error->errorCode());
        }

        $this->assertNotNull($store->find($firstReference, $savedAt));
    }

    private function attempt(DateTimeImmutable $issuedAt): BrowserAttempt
    {
        $context = BrowserAttemptContext::capture(
            checkoutSource: CheckoutSource::Direct,
            uiMode: UiMode::Hosted,
            languageCode: null,
            userUuid: null,
            csrf: 'native-csrf',
            cart: null,
        );

        return (new BrowserAttemptStore($this->kirby->session()))->issue(
            context: $context,
            initiatingUrl: 'https://kirby-stripe-checkout.test/product',
            issuedAt: $issuedAt,
        );
    }

    private function error(): CheckoutError
    {
        return new CheckoutError(
            code: CheckoutErrorCode::SESSION_UNCERTAIN,
            message: 'Checkout could not be confirmed.',
            retryable: true,
        );
    }

    private function retryInput(BrowserAttempt $attempt): CheckoutSubmissionInput
    {
        return new CheckoutSubmissionInput(
            checkoutSource: CheckoutSource::Direct,
            attemptToken: $attempt->token(),
            cartRevision: null,
            items: [new ProductRequest('shirt')],
            shippingCountry: null,
        );
    }
}
