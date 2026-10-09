<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use DateTimeImmutable;
use Kirby\Session\Sessions;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\AttemptBinding;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\BrowserAttemptContext;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\BrowserAttemptStore;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;

final class BrowserAttemptStoreTest extends KirbyTestCase
{
    public function testIndependentRequestsPreserveBothActionsAndBindOnlyTheFirstPurchase(): void
    {
        $sessions = new Sessions($this->environment->workspace()->roots()['sessions'], ['mode' => 'manual', 'gcInterval' => false]);
        $session = $sessions->create();
        $first = new BrowserAttemptStore($session);
        $context = $this->context();
        $issuedAt = new DateTimeImmutable('2026-10-09T10:00:00Z');
        $attempt = $first->issue($context, 'https://shop.test/product', $issuedAt);
        $nativeToken = $session->token();
        $this->assertNotNull($nativeToken);
        $secondSession = $sessions->get($nativeToken);
        $second = new BrowserAttemptStore($secondSession);
        $second->load($attempt->token(), $context, $issuedAt);
        $other = $first->issue($context, 'https://shop.test/other', $issuedAt);
        $this->assertSame($other->token()->value(), $second->load($other->token(), $context, $issuedAt)->token()->value());
        $binding = AttemptBinding::direct([new ProductRequest('shirt')], str_repeat('a', 64), guestReference: $context->guestReference());
        $boundAt = $issuedAt->modify('+1 minute');
        $first->bind($attempt->token(), $context, $binding, $boundAt);
        $duplicate = $second->bind($attempt->token(), $context, $binding, $boundAt->modify('+1 hour'));
        $this->assertSame($boundAt->getTimestamp(), $duplicate->boundAt());
        $this->assertSame('https://shop.test/product', $duplicate->initiatingUrl());

        try {
            $changedBinding = AttemptBinding::direct([new ProductRequest('other')], str_repeat('a', 64), guestReference: $context->guestReference());
            $second->bind($attempt->token(), $context, $changedBinding, $boundAt);
            $this->fail('An action cannot be rebound to another purchase.');
        } catch (CheckoutInputException $error) {
            $this->assertSame(CheckoutErrorCode::ATTEMPT_CONFLICT, $error->errorCode());
        }

        $this->assertSame($boundAt->getTimestamp(), $first->bind($attempt->token(), $context, $binding, $boundAt)->boundAt());
        $this->assertSame($other->token()->value(), $first->load($other->token(), $context, $boundAt)->token()->value());
        $session->destroy();
    }

    public function testTheSameTokenAndContextInAnotherBrowserGrantNoAccess(): void
    {
        $sessions = new Sessions($this->environment->workspace()->roots()['sessions'], ['mode' => 'manual', 'gcInterval' => false]);
        $firstSession = $sessions->create();
        $otherSession = $sessions->create();
        $context = $this->context();
        $issuedAt = new DateTimeImmutable();
        $attempt = (new BrowserAttemptStore($firstSession))->issue($context, 'https://shop.test', $issuedAt);

        try {
            (new BrowserAttemptStore($otherSession))->load($attempt->token(), $context, $issuedAt);
            $this->fail('A token alone cannot authorize a different browser.');
        } catch (CheckoutInputException $error) {
            $this->assertSame(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID, $error->errorCode());
        } finally {
            $firstSession->destroy();
            $otherSession->destroy();
        }
    }

    #[DataProvider('changedContexts')]
    public function testChangedBrowserContextCannotReuseTheAction(string $change): void
    {
        $issuedAt = new DateTimeImmutable();
        $context = $this->context(cart: new CartSnapshot('cart', 'revision', [], 1, 1));
        $store = new BrowserAttemptStore($this->kirby->session());
        $attempt = $store->issue($context, 'https://shop.test', $issuedAt);
        $cart = match ($change) {
            'source' => null,
            'cart id' => new CartSnapshot('other-cart', 'revision', [], 1, 1),
            'cart revision' => new CartSnapshot('cart', 'new-revision', [], 1, 1),
            default => new CartSnapshot('cart', 'revision', [], 1, 1),
        };
        $changed = BrowserAttemptContext::capture(
            checkoutSource: $change === 'source' ? CheckoutSource::Direct : CheckoutSource::Cart,
            uiMode: $change === 'mode' ? UiMode::Embedded : UiMode::Hosted,
            languageCode: $change === 'language' ? 'pt' : null,
            userUuid: $change === 'actor' ? 'user://member' : null,
            csrf: $change === 'csrf' ? 'different-native-csrf' : 'native-csrf',
            cart: $cart,
        );
        $this->expectException(CheckoutInputException::class);
        $this->expectExceptionMessage(CheckoutErrorCode::ATTEMPT_CONFLICT);
        $store->load($attempt->token(), $changed, $issuedAt);
    }

    /** @return iterable<string, array{string}> */
    public static function changedContexts(): iterable
    {
        $changes = ['source', 'mode', 'language', 'actor', 'csrf', 'cart id', 'cart revision'];

        foreach ($changes as $change) {
            yield $change => [$change];
        }
    }

    public function testAFullCollectionOfAcceptedPurchasesRejectsIssuanceWithoutEviction(): void
    {
        $store = new BrowserAttemptStore($this->kirby->session());
        $context = $this->context();
        $issuedAt = new DateTimeImmutable();
        $binding = AttemptBinding::direct([new ProductRequest('shirt')], str_repeat('a', 64), guestReference: $context->guestReference());
        $first = $store->issue($context, 'https://shop.test', $issuedAt);
        $store->bind($first->token(), $context, $binding, $issuedAt);

        for ($index = 0; $index < 99; $index++) {
            $attempt = $store->issue($context, 'https://shop.test', $issuedAt);
            $store->bind($attempt->token(), $context, $binding, $issuedAt);
        }

        try {
            $store->issue($context, 'https://shop.test', $issuedAt);
            $this->fail('Accepted purchases must not be evicted.');
        } catch (CheckoutInputException $error) {
            $this->assertSame(CheckoutErrorCode::ATTEMPT_LIMIT_REACHED, $error->errorCode());
        }

        $this->assertSame($first->token()->value(), $store->load($first->token(), $context, $issuedAt)->token()->value());
    }

    public function testRetentionNeverRenewsOnDuplicateBindingAndPreservesOtherSessionData(): void
    {
        $session = $this->kirby->session();
        $session->data()->set('other', 'keep');
        $store = new BrowserAttemptStore($session);
        $context = $this->context();
        $issuedAt = new DateTimeImmutable('2026-10-09T10:00:00Z');
        $attempt = $store->issue($context, 'https://shop.test', $issuedAt);
        $unused = $store->issue($context, 'https://shop.test/unused', $issuedAt);
        $binding = AttemptBinding::direct([new ProductRequest('shirt')], str_repeat('a', 64), guestReference: $context->guestReference());
        $boundAt = $issuedAt->modify('+1 hour');
        $store->bind($attempt->token(), $context, $binding, $boundAt);
        $store->bind($attempt->token(), $context, $binding, $issuedAt->modify('+23 hours'));
        $this->assertSame($boundAt->getTimestamp(), $store->load($attempt->token(), $context, $issuedAt->modify('+24 hours'))->boundAt());

        try {
            $store->load($unused->token(), $context, $issuedAt->modify('+24 hours'));
            $this->fail('Unused form must expire.');
        } catch (CheckoutInputException $error) {
            $this->assertSame(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID, $error->errorCode());
        }

        try {
            $store->load($attempt->token(), $context, $boundAt->modify('+24 hours'));
            $this->fail('Duplicate binding must not renew browser retention.');
        } catch (CheckoutInputException $error) {
            $this->assertSame(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID, $error->errorCode());
        }

        $this->assertSame('keep', $session->data()->get('other'));
    }

    public function testIssuingManyFormsRetiresUnusedActionsButPreservesAnAcceptedPurchase(): void
    {
        $store = new BrowserAttemptStore($this->kirby->session());
        $context = $this->context();
        $issuedAt = new DateTimeImmutable();
        $accepted = $store->issue($context, 'https://shop.test/accepted', $issuedAt);
        $binding = AttemptBinding::direct([new ProductRequest('shirt')], str_repeat('a', 64), guestReference: $context->guestReference());
        $store->bind($accepted->token(), $context, $binding, $issuedAt);
        $oldestUnused = $store->issue($context, 'https://shop.test/unused', $issuedAt);

        for ($index = 0; $index < 99; $index++) {
            $store->issue($context, 'https://shop.test/form', $issuedAt);
        }

        $this->assertSame($accepted->token()->value(), $store->load($accepted->token(), $context, $issuedAt)->token()->value());
        $this->expectException(CheckoutInputException::class);
        $this->expectExceptionMessage(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        $store->load($oldestUnused->token(), $context, $issuedAt);
    }

    public function testCorruptedStorageCannotAuthorizeTheActionAndDoesNotEraseUnrelatedData(): void
    {
        $session = $this->kirby->session();
        $store = new BrowserAttemptStore($session);
        $context = $this->context();
        $issuedAt = new DateTimeImmutable();
        $attempt = $store->issue($context, 'https://shop.test', $issuedAt);
        $data = $attempt->toArray();
        $data['context'] = [...$context->toArray(), 'userUuid' => ['untrusted']];
        $session->data()->set(BrowserAttemptStore::KEY, [
            'schema' => 1,
            'attempts' => [$attempt->token()->hash() => $data],
        ]);
        $session->data()->set('other', 'keep');

        try {
            $store->load($attempt->token(), $context, $issuedAt);
            $this->fail('Corrupted actor state must be rejected.');
        } catch (CheckoutInputException $error) {
            $this->assertSame(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID, $error->errorCode());
            $this->assertSame('keep', $session->data()->get('other'));
        }
    }

    private function context(?CartSnapshot $cart = null): BrowserAttemptContext
    {
        return BrowserAttemptContext::capture(
            checkoutSource: $cart === null ? CheckoutSource::Direct : CheckoutSource::Cart,
            uiMode: UiMode::Hosted,
            languageCode: null,
            userUuid: null,
            csrf: 'native-csrf',
            cart: $cart,
        );
    }
}
