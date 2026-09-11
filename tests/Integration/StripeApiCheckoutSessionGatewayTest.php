<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Configuration\StripeConfiguration;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailureType;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Exception\CheckoutSessionGatewayException;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\StripeApiCheckoutSessionGateway;
use ProgrammatorDev\StripeCheckout\Stripe\StripeApiClientFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

final class StripeApiCheckoutSessionGatewayTest extends KirbyTestCase
{
    public function testMapsTheSdkRequestAndResponseAtTheGatewayEdge(): void
    {
        $requests = [];
        $client = $this->httpClient();
        $client->method('request')->willReturnCallback(
            static function (...$arguments) use (&$requests): array {
                $requests[] = $arguments;

                return [json_encode([
                    'id' => 'cs_test_session',
                    'object' => 'checkout.session',
                    'client_reference_id' => 'page://Abc123def456GHI7',
                    'client_secret' => null,
                    'created' => 1_789_084_800,
                    'currency' => 'eur',
                    'expires_at' => 1_789_171_200,
                    'integration_identifier' => 'kirby_stripe_checkout_abcdefgh',
                    'livemode' => false,
                    'metadata' => ['kirby_stripe_checkout_order' => 'page://Abc123def456GHI7'],
                    'mode' => 'payment',
                    'payment_status' => 'unpaid',
                    'status' => 'open',
                    'ui_mode' => 'hosted_page',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test_session',
                ], JSON_THROW_ON_ERROR), 200, ['request-id' => 'req_checkout']];
            },
        );
        ApiRequestor::setHttpClient($client);
        $stripeClient = (new StripeApiClientFactory())->create(
            new StripeConfiguration('sk_test_gateway', null, null),
            '0.7.0',
        );
        $request = new SessionRequest([
            'currency' => 'eur',
            'line_items' => [['price' => 'price_standard', 'quantity' => 1]],
            'mode' => 'payment',
        ]);
        $record = (new StripeApiCheckoutSessionGateway($stripeClient))->create(
            $request,
            'stripe-checkout/session/Abc123def456GHI7',
        );

        $this->assertCount(1, $requests);
        $sdkRequest = $requests[0];
        $this->assertSame('post', $sdkRequest[0] ?? null);
        $this->assertSame('https://api.stripe.com/v1/checkout/sessions', $sdkRequest[1] ?? null);
        $this->assertSame($request->parameters(), $sdkRequest[3] ?? null);
        $headers = $sdkRequest[2] ?? null;
        $this->assertIsArray($headers);
        $this->assertTrue($this->hasHeader($headers, 'Idempotency-Key: stripe-checkout/session/Abc123def456GHI7'));
        $this->assertSame('cs_test_session', $record->id);
        $this->assertSame(1_789_084_800, $record->createdAt);
        $this->assertSame(1_789_171_200, $record->expiresAt);
        $this->assertSame('open', $record->status);
        $this->assertSame('unpaid', $record->paymentStatus);
        $this->assertFalse($record->liveMode);
        $this->assertSame('payment', $record->mode);
        $this->assertSame('hosted_page', $record->uiMode);
        $this->assertSame('eur', $record->currency);
        $this->assertSame('page://Abc123def456GHI7', $record->clientReferenceId);
        $this->assertSame('kirby_stripe_checkout_abcdefgh', $record->integrationIdentifier);
        $this->assertSame(['kirby_stripe_checkout_order' => 'page://Abc123def456GHI7'], $record->metadata);
        $this->assertSame('req_checkout', $record->requestId);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_session', $record->url);
        $this->assertNull($record->clientSecret);
    }

    public function testWrapsSdkFailuresWithoutUsingTheirMessageAsThePublicMessage(): void
    {
        $client = $this->httpClient();
        $client->method('request')->willThrowException(new RuntimeException('PRIVATE PROVIDER DETAIL'));
        ApiRequestor::setHttpClient($client);
        $gateway = new StripeApiCheckoutSessionGateway(
            (new StripeApiClientFactory())->create(
                new StripeConfiguration('sk_test_gateway', null, null),
            ),
        );

        try {
            $gateway->create(new SessionRequest(['mode' => 'payment']), 'idempotency-key');
            $this->fail('Expected the gateway to wrap an SDK failure.');
        } catch (CheckoutSessionGatewayException $error) {
            $this->assertSame('The Stripe Checkout Session request failed.', $error->getMessage());
            $this->assertStringNotContainsString('PRIVATE', $error->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $error->getPrevious());
            $this->assertSame(CheckoutSessionFailureType::Uncertain, $error->failure()->type());
        }
    }

    public function testRetrievesAnExistingSessionWithoutAnIdempotencyKey(): void
    {
        $requests = [];
        $client = $this->httpClient();
        $client->method('request')->willReturnCallback(
            static function (...$arguments) use (&$requests): array {
                $requests[] = $arguments;

                return [json_encode([
                    'id' => 'cs_test_session',
                    'object' => 'checkout.session',
                    'client_reference_id' => 'page://Abc123def456GHI7',
                    'client_secret' => null,
                    'created' => 1_789_084_800,
                    'currency' => 'eur',
                    'expires_at' => 1_789_171_200,
                    'integration_identifier' => 'kirby_stripe_checkout_abcdefgh',
                    'livemode' => false,
                    'metadata' => [],
                    'mode' => 'payment',
                    'payment_status' => 'unpaid',
                    'status' => 'open',
                    'ui_mode' => 'hosted_page',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test_session',
                ], JSON_THROW_ON_ERROR), 200, ['request-id' => 'req_retrieve']];
            },
        );
        ApiRequestor::setHttpClient($client);
        $gateway = new StripeApiCheckoutSessionGateway(
            (new StripeApiClientFactory())->create(
                new StripeConfiguration('sk_test_gateway', null, null),
            ),
        );
        $record = $gateway->retrieve('cs_test_session');

        $this->assertCount(1, $requests);
        $this->assertSame('get', $requests[0][0] ?? null);
        $this->assertSame('https://api.stripe.com/v1/checkout/sessions/cs_test_session', $requests[0][1] ?? null);
        $headers = $requests[0][2] ?? null;
        $this->assertIsArray($headers);
        $this->assertFalse((bool) array_filter($headers, static fn(mixed $header): bool => is_string($header) && str_starts_with($header, 'Idempotency-Key:')));
        $this->assertSame('cs_test_session', $record->id);
        $this->assertSame('req_retrieve', $record->requestId);
    }

    public function testRejectsAnInvalidSessionIdBeforeRetrieval(): void
    {
        $client = $this->httpClient();
        $client->expects($this->never())->method('request');
        ApiRequestor::setHttpClient($client);
        $gateway = new StripeApiCheckoutSessionGateway(
            (new StripeApiClientFactory())->create(
                new StripeConfiguration('sk_test_gateway', null, null),
            ),
        );

        $this->expectException(InvalidArgumentException::class);
        $gateway->retrieve('not-a-session');
    }

    public function testRequiresAnIdempotencyKeyBeforeCallingStripe(): void
    {
        $client = $this->httpClient();
        $client->expects($this->never())->method('request');
        ApiRequestor::setHttpClient($client);
        $gateway = new StripeApiCheckoutSessionGateway(
            (new StripeApiClientFactory())->create(
                new StripeConfiguration('sk_test_gateway', null, null),
            ),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A Checkout Session idempotency key is required.');

        $gateway->create(new SessionRequest(['mode' => 'payment']), '   ');
    }

    public function testMapsUnexpectedProviderValuesToAnUntrustedPartialRecord(): void
    {
        $client = $this->httpClient();
        $client->method('request')->willReturn([
            json_encode([
                'object' => 'checkout.session',
                'client_reference_id' => false,
                'client_secret' => false,
                'created' => 'unexpected',
                'currency' => false,
                'expires_at' => 'unexpected',
                'id' => false,
                'integration_identifier' => false,
                'livemode' => 0,
                'metadata' => 'unexpected',
                'mode' => false,
                'payment_status' => false,
                'status' => false,
                'ui_mode' => false,
                'url' => false,
            ], JSON_THROW_ON_ERROR),
            200,
            [],
        ]);
        ApiRequestor::setHttpClient($client);
        $gateway = new StripeApiCheckoutSessionGateway(
            (new StripeApiClientFactory())->create(
                new StripeConfiguration('sk_test_gateway', null, null),
            ),
        );

        $record = $gateway->create(
            new SessionRequest(['mode' => 'payment']),
            'idempotency-key',
        );

        $this->assertNull($record->id);
        $this->assertNull($record->createdAt);
        $this->assertNull($record->liveMode);
        $this->assertSame([], $record->metadata);
        $this->assertNull($record->requestId);
        $this->assertNull($record->url);
    }

    /** @return ClientInterface&MockObject */
    private function httpClient(): ClientInterface
    {
        return $this->createMock(ClientInterface::class);
    }

    /** @param array<mixed> $headers */
    private function hasHeader(array $headers, string $expected): bool
    {
        foreach ($headers as $header) {
            if (is_string($header) && $header === $expected) {
                return true;
            }
        }

        return false;
    }
}
