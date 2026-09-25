<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Checkout;

use Brick\Money\Money;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartEntry;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\AttemptBinding;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\AttemptToken;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;

final class AttemptBindingTest extends TestCase
{
    public function testGenerationCarriesAKirbyUuidAndExactly32RandomBytes(): void
    {
        $token = AttemptToken::generate(
            randomBytes: function (int $length): string {
                $this->assertSame(32, $length);

                return str_repeat("\xff", $length);
            },
            uuidGenerator: static fn(): string => 'kirbyorderuuid01',
        );

        $this->assertSame('kirbyorderuuid01', $token->orderUuid());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token->value());
        $this->assertSame($token->hash(), (new AttemptToken($token->value()))->hash());
        $this->assertSame($token->orderUuid(), (new AttemptToken($token->value()))->orderUuid());
        $this->assertNotSame($token->value(), $token->hash());
        $this->assertNotSame(AttemptToken::generate()->hash(), AttemptToken::generate()->hash());
    }

    public function testInvalidEntropyCannotSilentlyWeakenAToken(): void
    {
        $this->expectException(LogicException::class);
        AttemptToken::generate(
            randomBytes: static fn(int $length): string => str_repeat('x', $length - 1),
            uuidGenerator: static fn(): string => 'kirbyorderuuid01',
        );
    }

    #[DataProvider('invalidTokens')]
    public function testRejectsMalformedTransportTokens(string $value): void
    {
        $this->expectException(CheckoutInputException::class);
        $this->expectExceptionMessage('checkout.attempt_token_invalid');
        new AttemptToken($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'old opaque token' => [str_repeat('a', 43)];
        yield 'missing nonce' => ['a2lyYnlvcmRlcnV1aWQwMQ.'];
        yield 'non-canonical UUID encoding' => ['a2lyYnlvcmRlcnV1aWQwMQ==.' . str_repeat('a', 43)];
        yield 'short nonce' => ['a2lyYnlvcmRlcnV1aWQwMQ.' . str_repeat('a', 42)];
        yield 'padding' => ['a2lyYnlvcmRlcnV1aWQwMQ.' . str_repeat('a', 42) . '='];
        yield 'newline' => ['a2lyYnlvcmRlcnV1aWQwMQ.' . str_repeat('a', 43) . "\n"];
    }

    public function testSameBindingCanBeRetriedWhileANewActionGetsANewToken(): void
    {
        $cart = $this->cart();
        $first = AttemptBinding::cart($cart, hash('sha256', 'request'), guestReference: 'guest');
        $retry = AttemptBinding::cart($cart, hash('sha256', 'request'), guestReference: 'guest');
        $first->assertMatches($retry);
        $this->assertSame(CheckoutSource::Cart, $first->checkoutSource());
        $this->assertSame($cart->revision(), $first->cartRevision());
        $this->assertSame($first->fingerprint(), $retry->fingerprint());
        $this->assertNotSame(AttemptToken::generate()->hash(), AttemptToken::generate()->hash());
    }

    #[DataProvider('changedBindings')]
    public function testChangedBindingConflicts(string $change): void
    {
        $fingerprint = hash('sha256', 'request');
        $first = AttemptBinding::cart($this->cart(), $fingerprint, guestReference: 'guest');
        $changed = match ($change) {
            'user' => AttemptBinding::cart($this->cart(), $fingerprint, userUuid: 'user://customer'),
            'guest' => AttemptBinding::cart($this->cart(), $fingerprint, guestReference: 'other'),
            'source' => AttemptBinding::direct([new ProductRequest('shirt')], $fingerprint, guestReference: 'guest'),
            'cart' => AttemptBinding::cart($this->cart(id: 'other'), $fingerprint, guestReference: 'guest'),
            'revision' => AttemptBinding::cart($this->cart(revision: 'other'), $fingerprint, guestReference: 'guest'),
            default => AttemptBinding::cart($this->cart(), hash('sha256', 'changed context'), guestReference: 'guest'),
        };

        $this->expectException(CheckoutInputException::class);
        $this->expectExceptionMessage('checkout.attempt_conflict');
        $first->assertMatches($changed);
    }

    /** @return iterable<string, array{string}> */
    public static function changedBindings(): iterable
    {
        foreach (['user', 'guest', 'source', 'cart', 'revision', 'context'] as $change) {
            yield $change => [$change];
        }
    }

    public function testDirectBindingPreservesOrderAndNormalizesOptionKeyOrder(): void
    {
        $fingerprint = hash('sha256', 'request');
        $first = AttemptBinding::direct([
            new ProductRequest('shirt', 1, ['size' => 'large', 'colour' => 'blue']),
            new ProductRequest('bag'),
        ], $fingerprint, guestReference: 'guest');
        $same = AttemptBinding::direct([
            new ProductRequest('shirt', 1, ['colour' => 'blue', 'size' => 'large']),
            new ProductRequest('bag'),
        ], $fingerprint, guestReference: 'guest');
        $first->assertMatches($same);
        $this->assertSame(CheckoutSource::Direct, $first->checkoutSource());

        foreach ([
            [new ProductRequest('bag'), new ProductRequest('shirt', 1, ['colour' => 'blue', 'size' => 'large'])],
            [new ProductRequest('shirt', 2, ['colour' => 'blue', 'size' => 'large']), new ProductRequest('bag')],
            [new ProductRequest('shirt', 1, ['colour' => 'red', 'size' => 'large']), new ProductRequest('bag')],
        ] as $changedItems) {
            try {
                $first->assertMatches(AttemptBinding::direct($changedItems, $fingerprint, guestReference: 'guest'));
                $this->fail('Expected changed direct input to conflict.');
            } catch (CheckoutInputException $error) {
                $this->assertSame('checkout.attempt_conflict', $error->errorCode());
            }
        }
    }

    #[DataProvider('invalidActors')]
    public function testExactlyOneValidActorIsRequired(?string $user, ?string $guest): void
    {
        $this->expectException(InvalidArgumentException::class);
        AttemptBinding::cart($this->cart(), hash('sha256', 'request'), $user, $guest);
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function invalidActors(): iterable
    {
        yield 'missing' => [null, null];
        yield 'both' => ['user://customer', 'guest'];
        yield 'email' => ['customer@example.com', null];
        yield 'bare scheme' => ['user://', null];
    }

    #[DataProvider('compatibleActorsAndSources')]
    public function testCheckoutCompatibilityChecksActorAndSource(
        bool $authenticated,
        CheckoutSource $checkoutSource,
        ?string $userUuid,
        ?string $guestReference,
        bool $compatible,
    ): void {
        $binding = $this->cartBinding($authenticated);
        $checkout = $this->checkout($checkoutSource, $userUuid);

        if ($compatible === false) {
            $this->expectException(CheckoutInputException::class);
            $this->expectExceptionMessage(CheckoutErrorCode::ATTEMPT_CONFLICT);
        }

        $binding->assertCompatibleCheckout($checkout, $guestReference);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('compatibleActorsAndSources')]
    public function testOrderCompatibilityChecksActorAndSource(
        bool $authenticated,
        CheckoutSource $checkoutSource,
        ?string $userUuid,
        ?string $guestReference,
        bool $compatible,
    ): void {
        $binding = $this->cartBinding($authenticated);
        $order = $this->order($this->checkout($checkoutSource, $userUuid));

        if ($compatible === false) {
            $this->expectException(CheckoutInputException::class);
            $this->expectExceptionMessage(CheckoutErrorCode::ATTEMPT_CONFLICT);
        }

        $binding->assertCompatibleOrder($order, $guestReference);
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{bool, CheckoutSource, ?string, ?string, bool}> */
    public static function compatibleActorsAndSources(): iterable
    {
        yield 'matching guest' => [false, CheckoutSource::Cart, null, 'guest', true];
        yield 'matching user' => [true, CheckoutSource::Cart, 'user://customer', null, true];
        yield 'different source' => [false, CheckoutSource::Direct, null, 'guest', false];
        yield 'different guest' => [false, CheckoutSource::Cart, null, 'other', false];
        yield 'missing guest' => [false, CheckoutSource::Cart, null, null, false];
        yield 'different user' => [true, CheckoutSource::Cart, 'user://other', null, false];
        yield 'user logged out' => [true, CheckoutSource::Cart, null, 'guest', false];
        yield 'guest logged in' => [false, CheckoutSource::Cart, 'user://customer', null, false];
    }

    public function testOrderCompatibilityRejectsADifferentCartRevision(): void
    {
        $binding = $this->cartBinding();
        $checkout = $this->checkout();
        $binding->assertCompatibleCheckout($checkout, 'guest');
        $binding->assertCompatibleOrder($this->order($checkout), 'guest');

        $this->expectException(CheckoutInputException::class);
        $this->expectExceptionMessage(CheckoutErrorCode::ATTEMPT_CONFLICT);
        $binding->assertCompatibleOrder($this->order($checkout, revision: 'other'), 'guest');
    }

    public function testDirectOrderCompatibilityAcceptsNoCartRevision(): void
    {
        $binding = AttemptBinding::direct([new ProductRequest('shirt')], hash('sha256', 'request'), guestReference: 'guest');
        $checkout = $this->checkout(CheckoutSource::Direct);

        $binding->assertCompatibleCheckout($checkout, 'guest');
        $binding->assertCompatibleOrder($this->order($checkout), 'guest');
        $this->addToAssertionCount(2);
    }

    private function cartBinding(bool $authenticated = false): AttemptBinding
    {
        return AttemptBinding::cart(
            cart: $this->cart(),
            contextFingerprint: hash('sha256', 'request'),
            userUuid: $authenticated ? 'user://customer' : null,
            guestReference: $authenticated ? null : 'guest',
        );
    }

    private function checkout(CheckoutSource $checkoutSource = CheckoutSource::Cart, ?string $userUuid = null): CheckoutContext
    {
        return new CheckoutContext(
            items: [new CheckoutLineItem(new Product(
                request: new ProductRequest('shirt'),
                name: 'Shirt',
                requiresShipping: false,
                price: new Price(Money::of('16', 'EUR')),
            ))],
            languageCode: null,
            locale: 'en_US',
            userUuid: $userUuid,
            checkoutSource: $checkoutSource,
            uiMode: UiMode::Hosted,
        );
    }

    private function order(CheckoutContext $checkout, string $revision = 'revision'): OrderCreationContext
    {
        return new OrderCreationContext(
            uuid: 'checkoutorder001',
            orderNumber: 'ORD-CHECKOUTORDER001',
            checkoutSource: $checkout->checkoutSource(),
            cartRevision: $checkout->checkoutSource() === CheckoutSource::Cart ? $revision : null,
            userUuid: $checkout->userUuid(),
            languageCode: $checkout->languageCode(),
            uiMode: $checkout->uiMode(),
            currency: $checkout->currency()->getCurrencyCode(),
            lineItems: array_map(OrderLineItemSnapshot::fromCheckoutLineItem(...), $checkout->items()),
        );
    }

    private function cart(string $id = 'cart', string $revision = 'revision'): CartSnapshot
    {
        return new CartSnapshot($id, $revision, [new CartEntry('item', new ProductRequest('shirt'))], 100, 100);
    }
}
