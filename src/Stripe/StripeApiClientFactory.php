<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe;

use ProgrammatorDev\StripeCheckout\Configuration\StripeConfiguration;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Plugin\PluginMetadata;
use Stripe\StripeClient;
use Stripe\Util\ApiVersion;

/**
 * Builds one explicitly configured Stripe API client for a plugin operation.
 *
 * @internal
 */
final class StripeApiClientFactory
{
    private const MAX_NETWORK_RETRIES = 2;

    public function create(
        StripeConfiguration $configuration,
        ?string $pluginVersion = null,
    ): StripeClient {
        $apiKey = $configuration->secretKey();

        if ($apiKey === null) {
            throw new ConfigurationException(
                'configuration.credential_missing',
                'stripe.secretKey',
            );
        }

        $appInfo = [
            'name' => PluginMetadata::PACKAGE_NAME,
            'url' => PluginMetadata::URL,
        ];

        if ($pluginVersion !== null && $pluginVersion !== '') {
            $appInfo['version'] = $pluginVersion;
        }

        return new StripeClient([
            'api_key' => $apiKey,
            'app_info' => $appInfo,
            'max_network_retries' => self::MAX_NETWORK_RETRIES,
            'stripe_version' => ApiVersion::CURRENT,
        ]);
    }
}
