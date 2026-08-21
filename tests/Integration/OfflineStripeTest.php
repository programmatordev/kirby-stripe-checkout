<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use LogicException;
use ProgrammatorDev\StripeCheckout\Test\Support\BlockingStripeClient;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use Stripe\ApiRequestor;

final class OfflineStripeTest extends KirbyTestCase
{
    public function testKirbyEnvironmentBlocksStripeNetworkRequests(): void
    {
        $client = ApiRequestor::httpClient();

        $this->assertInstanceOf(BlockingStripeClient::class, $client);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unexpected Stripe HTTP request: GET https://api.stripe.com/v1/checkout/sessions/test');

        $client->request(
            'get',
            'https://api.stripe.com/v1/checkout/sessions/test',
            [],
            [],
            false
        );
    }
}
