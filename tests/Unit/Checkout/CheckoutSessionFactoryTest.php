<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Checkout;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSessionFactory;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionRecord;
use ProgrammatorDev\StripeCheckout\Test\Support\OrderFixture;

final class CheckoutSessionFactoryTest extends TestCase
{
    #[DataProvider('validPresentations')]
    public function testValidatesAndPreservesProviderPresentationValues(
        UiMode $uiMode,
        ?string $url,
        ?string $clientSecret,
    ): void {
        $context = $this->context($uiMode);
        $session = (new CheckoutSessionFactory())->create(
            record: $this->record($context, $url, $clientSecret),
            context: $context,
            request: $this->request(),
            liveMode: false,
        );

        $this->assertSame($url, $session->url());
        $this->assertSame($clientSecret, $session->clientSecret());
        $this->assertSame('cs_test_presentation', $session->association()->sessionId());
    }

    /** @return iterable<string, array{UiMode, ?string, ?string}> */
    public static function validPresentations(): iterable
    {
        yield 'hosted redirect' => [UiMode::Hosted, 'https://checkout.stripe.com/session', null];
        yield 'opaque embedded secret at the length limit' => [UiMode::Embedded, null, str_repeat('s', 2048)];
    }

    #[DataProvider('invalidPresentations')]
    public function testRejectsMalformedPresentationAsAnIncompatibleProviderResult(
        UiMode $uiMode,
        ?string $url,
        ?string $clientSecret,
    ): void {
        $context = $this->context($uiMode);
        $this->expectException(CheckoutSessionException::class);
        $this->expectExceptionMessage('checkout.session_incompatible');

        (new CheckoutSessionFactory())->create(
            record: $this->record($context, $url, $clientSecret),
            context: $context,
            request: $this->request(),
            liveMode: false,
        );
    }

    /** @return iterable<string, array{UiMode, ?string, ?string}> */
    public static function invalidPresentations(): iterable
    {
        yield 'hosted without redirect' => [UiMode::Hosted, null, null];
        yield 'hosted with both values' => [UiMode::Hosted, 'https://checkout.stripe.com/session', 'secret'];
        yield 'embedded without secret' => [UiMode::Embedded, null, null];
        yield 'embedded with both values' => [UiMode::Embedded, 'https://checkout.stripe.com/session', 'secret'];
        yield 'insecure hosted redirect' => [UiMode::Hosted, 'http://checkout.stripe.com/session', null];
        yield 'empty embedded secret' => [UiMode::Embedded, null, ''];
        yield 'padded embedded secret' => [UiMode::Embedded, null, ' secret'];
        yield 'whitespace-only embedded secret' => [UiMode::Embedded, null, ' '];
        yield 'oversized embedded secret' => [UiMode::Embedded, null, str_repeat('s', 2049)];
        yield 'multiline embedded secret' => [UiMode::Embedded, null, "secret\nvalue"];
    }

    public function testHistoricalAssociationDoesNotRequirePresentationValues(): void
    {
        $context = $this->context(UiMode::Embedded);
        $association = (new CheckoutSessionFactory())->association(
            record: $this->record($context, null, null),
            order: $context->order(),
            request: $this->request(),
            liveMode: false,
        );

        $this->assertSame('cs_test_presentation', $association->sessionId());
    }

    private function context(UiMode $uiMode): SessionRequestContext
    {
        return new SessionRequestContext(
            order: new OrderCreationContext(
                uuid: 'Abc123def456GHI7',
                orderNumber: 'ORD-ABC123DEF456GHI7',
                checkoutSource: CheckoutSource::Cart,
                cartRevision: 'revision',
                userUuid: null,
                languageCode: 'en',
                uiMode: $uiMode,
                currency: 'EUR',
                lineItems: [OrderFixture::lineItem()],
            ),
            locale: 'en_US',
            expiresAt: new DateTimeImmutable('@2000003600'),
            initiatingUrl: 'https://example.com/product',
            successDestination: 'https://example.com/success',
            cancelDestination: 'https://example.com/cancel',
            returnDestination: 'https://example.com/return',
        );
    }

    private function request(): SessionRequest
    {
        return new SessionRequest([
            'expires_at' => 2000003600,
            'metadata' => [
                'kirby_stripe_checkout_owner' => 'programmatordev/stripe-checkout',
                'kirby_stripe_checkout_order' => 'page://Abc123def456GHI7',
            ],
        ]);
    }

    private function record(SessionRequestContext $context, ?string $url, ?string $clientSecret): CheckoutSessionRecord
    {
        return new CheckoutSessionRecord(
            id: 'cs_test_presentation',
            createdAt: 2000000000,
            expiresAt: 2000003600,
            status: 'open',
            paymentStatus: 'unpaid',
            liveMode: false,
            mode: 'payment',
            uiMode: $context->uiMode() === UiMode::Hosted ? 'hosted_page' : 'embedded_page',
            currency: 'eur',
            clientReferenceId: 'page://Abc123def456GHI7',
            integrationIdentifier: null,
            metadata: [
                'kirby_stripe_checkout_owner' => 'programmatordev/stripe-checkout',
                'kirby_stripe_checkout_order' => 'page://Abc123def456GHI7',
            ],
            requestId: 'req_presentation',
            url: $url,
            clientSecret: $clientSecret,
        );
    }
}
