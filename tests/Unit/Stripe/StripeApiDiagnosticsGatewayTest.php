<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Stripe;

use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticsErrorCode;
use ProgrammatorDev\StripeCheckout\Diagnostics\Exception\DiagnosticsException;
use ProgrammatorDev\StripeCheckout\Stripe\Diagnostics\StripeApiDiagnosticsGateway;
use ProgrammatorDev\StripeCheckout\Stripe\Diagnostics\WebhookEndpointRecord;
use ProgrammatorDev\StripeCheckout\Test\Support\Stripe\DiagnosticsClient;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;
use Stripe\Util\ApiVersion;

final class StripeApiDiagnosticsGatewayTest extends TestCase
{
    private ClientInterface $previousClient;

    protected function setUp(): void
    {
        $this->previousClient = ApiRequestor::httpClient();
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient($this->previousClient);
        ApiRequestor::resetTelemetry();
    }

    public function testReadsAccountModeAndPaginatesAllWebhookEndpoints(): void
    {
        $client = new DiagnosticsClient();
        $client->webhookPages = [
            '' => $this->page([$this->endpoint('we_first')], true),
            'we_first' => $this->page([$this->endpoint('we_second')], false),
        ];
        ApiRequestor::setHttpClient($client);
        $gateway = new StripeApiDiagnosticsGateway(new StripeClient('sk_test_diagnostics'));

        $mode = $gateway->accountMode();
        $endpoints = $gateway->webhookEndpoints();

        $this->assertSame(CredentialMode::Test, $mode);
        $this->assertSame(['we_first', 'we_second'], array_map(
            static fn(WebhookEndpointRecord $endpoint): string => $endpoint->endpointId(),
            $endpoints,
        ));
        $this->assertCount(3, $client->requests);
        $this->assertSame('we_first', $client->requests[2]['parameters']['starting_after']);
    }

    public function testClassifiesRestrictedKeyReadsWithoutProviderMessages(): void
    {
        $client = new DiagnosticsClient();
        $client->httpStatus = 403;
        ApiRequestor::setHttpClient($client);
        $gateway = new StripeApiDiagnosticsGateway(new StripeClient('rk_test_diagnostics'));

        try {
            $gateway->accountMode();
            $this->fail('The denied Stripe read was accepted.');
        } catch (DiagnosticsException $error) {
            $this->assertSame(DiagnosticsErrorCode::PERMISSION_DENIED, $error->errorCode());
            $this->assertStringNotContainsString('PRIVATE_DIAGNOSTICS_CANARY', $error->getMessage());
        }
    }

    /**
     * @param list<array<string, mixed>> $endpoints
     * @return array{object: string, has_more: bool, data: list<array<string, mixed>>}
     */
    private function page(array $endpoints, bool $hasMore): array
    {
        return [
            'object' => 'list',
            'has_more' => $hasMore,
            'data' => $endpoints,
        ];
    }

    /** @return array<string, mixed> */
    private function endpoint(string $endpointId): array
    {
        return [
            'id' => $endpointId,
            'object' => 'webhook_endpoint',
            'url' => 'https://kirby-stripe-checkout.test/stripe-checkout/webhook',
            'enabled_events' => ['*'],
            'livemode' => false,
            'api_version' => ApiVersion::CURRENT,
            'status' => 'enabled',
        ];
    }
}
