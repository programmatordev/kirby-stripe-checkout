<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Configuration\StripeConfiguration;
use ProgrammatorDev\StripeCheckout\Stripe\Dispute\StripeApiDisputeGateway;
use ProgrammatorDev\StripeCheckout\Stripe\StripeApiClientFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

final class DisputeGatewayTest extends KirbyTestCase
{
    public function testEmptyCompleteDisputeListIsDistinctFromAnEmptyContinuation(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->method('request')->willReturn([
            '{"object":"list","has_more":false,"data":[]}', 200, [],
        ]);
        ApiRequestor::setHttpClient($http);
        $client = (new StripeApiClientFactory())->create(new StripeConfiguration('sk_test_disputes', null, null), '0.7.0');
        $this->assertSame([], (new StripeApiDisputeGateway($client))->allForPaymentIntent('pi_test'));
    }

    public function testRefusesUnboundedDisputeHistoryWithoutReturningAPrefix(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $count = 0;
        $http->method('request')->willReturnCallback(static function () use (&$count): array {
            $data = [];

            for ($item = 0; $item < 100; $item++) {
                $data[] = [
                    'id' => 'du_' . ++$count,
                    'object' => 'dispute',
                ];
            }

            return [json_encode([
                'object' => 'list',
                'has_more' => true,
                'data' => $data,
            ], JSON_THROW_ON_ERROR), 200, []];
        });
        ApiRequestor::setHttpClient($http);
        $client = (new StripeApiClientFactory())->create(new StripeConfiguration('sk_test_disputes', null, null), '0.7.0');

        try {
            (new StripeApiDisputeGateway($client))->allForPaymentIntent('pi_test');
            $this->fail('An excessive collection cannot be treated as complete.');
        } catch (CheckoutSessionException $error) {
            $this->assertSame(CheckoutErrorCode::SESSION_INCOMPATIBLE, $error->errorCode());
            $this->assertFalse($error->isRetryable());
            $this->assertLessThanOrEqual(10100, $count);
        }
    }
}
