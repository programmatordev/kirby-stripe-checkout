<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Stripe;

use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Configuration\StripeConfiguration;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Stripe\StripeApiClientFactory;
use Stripe\Stripe;
use Stripe\Util\ApiVersion;

final class StripeApiClientFactoryTest extends TestCase
{
    public function testCreatesAnExplicitOperationScopedClient(): void
    {
        $globalKey = Stripe::getApiKey();
        $globalVersion = Stripe::getApiVersion();
        $globalRetries = Stripe::getMaxNetworkRetries();
        $client = (new StripeApiClientFactory())->create(
            new StripeConfiguration('rk_test_operation', null, null),
            '0.7.0',
        );

        $this->assertSame('rk_test_operation', $client->getApiKey());
        $this->assertSame(ApiVersion::CURRENT, $client->getStripeVersion());
        $this->assertSame(2, $client->getMaxNetworkRetries());
        $this->assertSame([
            'name' => 'programmatordev/kirby-stripe-checkout',
            'url' => 'https://github.com/programmatordev/kirby-stripe-checkout',
            'version' => '0.7.0',
        ], $client->getAppInfo());
        $this->assertSame($globalKey, Stripe::getApiKey());
        $this->assertSame($globalVersion, Stripe::getApiVersion());
        $this->assertSame($globalRetries, Stripe::getMaxNetworkRetries());
        $this->assertNull($client->getStripeAccount());
        $this->assertNull($client->getStripeContext());
    }

    public function testRequiresAnApiKey(): void
    {
        try {
            (new StripeApiClientFactory())->create(
                new StripeConfiguration(null, null, null),
            );
            $this->fail('Expected the client factory to require an API key.');
        } catch (ConfigurationException $error) {
            $this->assertSame('configuration.credential_missing', $error->errorCode());
            $this->assertSame('stripe.secretKey', $error->path());
        }
    }
}
