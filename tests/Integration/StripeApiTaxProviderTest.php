<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use ProgrammatorDev\StripeCheckout\Configuration\StripeConfiguration;
use ProgrammatorDev\StripeCheckout\Stripe\StripeApiClientFactory;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\StripeApiTaxProvider;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

final class StripeApiTaxProviderTest extends KirbyTestCase
{
    public function testMapsTaxCodePagesAndUsesReadOnlySdkRequests(): void
    {
        $requests = [];
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturnCallback(static function (...$arguments) use (&$requests): array {
            $requests[] = $arguments;

            return [json_encode([
                'object' => 'list',
                'url' => '/v1/tax_codes',
                'has_more' => count($requests) === 1,
                'data' => [[
                    'object' => 'tax_code',
                    'id' => 'txcd_test',
                    'name' => 'Test classification',
                    'description' => 'Provider description',
                    'requirements' => ['performance_location' => count($requests) === 1 ? 'required' : 'optional'],
                ]],
            ], JSON_THROW_ON_ERROR), 200, []];
        });
        ApiRequestor::setHttpClient($client);
        $provider = $this->provider();
        $first = $provider->listTaxCodes();
        $second = $provider->listTaxCodes('txcd_test');

        $this->assertTrue($first->hasMore());
        $this->assertFalse($second->hasMore());
        $this->assertSame('txcd_test', $first->taxCodes()[0]->id);
        $this->assertSame('Test classification', $first->taxCodes()[0]->name);
        $this->assertSame('Provider description', $first->taxCodes()[0]->description);
        $this->assertTrue($first->taxCodes()[0]->requiresPerformanceLocation);
        $this->assertFalse($second->taxCodes()[0]->requiresPerformanceLocation);
        $this->assertCount(2, $requests);
        $this->assertSame('get', $requests[0][0]);
        $this->assertSame('https://api.stripe.com/v1/tax_codes', $requests[0][1]);
        $this->assertSame(['limit' => 100], $requests[0][3]);
        $this->assertSame('get', $requests[1][0]);
        $this->assertSame([
            'limit' => 100,
            'starting_after' => 'txcd_test',
        ], $requests[1][3]);
    }

    private function provider(): StripeApiTaxProvider
    {
        return new StripeApiTaxProvider((new StripeApiClientFactory())->create(new StripeConfiguration(
            secretKey: 'sk_test_tax_provider',
            publishableKey: null,
            webhookSecret: null,
        )));
    }
}
