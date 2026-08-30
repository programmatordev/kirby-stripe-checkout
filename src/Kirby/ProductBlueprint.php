<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;

/**
 * Builds independently reusable product field blueprints.
 *
 * @internal
 */
final class ProductBlueprint
{
    /** @return array<string, mixed> */
    public static function name(App $kirby): array
    {
        return [
            'label' => 'programmatordev.stripe-checkout.product.name.label',
            'help' => 'programmatordev.stripe-checkout.product.name.help',
            'type' => 'text',
        ];
    }

    /** @return array<string, mixed> */
    public static function price(App $kirby): array
    {
        try {
            $currency = self::currency($kirby);
        } catch (ConfigurationException) {
            return self::configurationWarning();
        }

        return [
            'label' => 'programmatordev.stripe-checkout.product.price.label',
            'help' => 'programmatordev.stripe-checkout.product.price.help',
            'after' => $currency,
            'pattern' => '[0-9]+(?:\.[0-9]+)?',
            'translate' => false,
            'type' => 'text',
        ];
    }

    /** @return array<string, mixed> */
    public static function stripePrice(App $kirby): array
    {
        try {
            self::currency($kirby);
        } catch (ConfigurationException) {
            return self::configurationWarning();
        }

        // The searchable Stripe Price selector replaces this informational
        // state in Batch 6.2c; raw Price IDs are never a normal Panel input.
        return [
            'label' => 'programmatordev.stripe-checkout.product.price.label',
            'text' => 'programmatordev.stripe-checkout.product.stripePrice.pending',
            'theme' => 'info',
            'type' => 'info',
        ];
    }

    /** @return array<string, mixed> */
    public static function description(App $kirby): array
    {
        return [
            'label' => 'programmatordev.stripe-checkout.product.description.label',
            'help' => 'programmatordev.stripe-checkout.product.description.help',
            'type' => 'textarea',
        ];
    }

    /** @return array<string, mixed> */
    public static function images(App $kirby): array
    {
        return [
            'label' => 'programmatordev.stripe-checkout.product.images.label',
            'help' => 'programmatordev.stripe-checkout.product.images.help',
            'multiple' => true,
            'type' => 'files',
        ];
    }

    /** @return array<string, mixed> */
    public static function sku(App $kirby): array
    {
        return [
            'label' => 'programmatordev.stripe-checkout.product.sku.label',
            'help' => 'programmatordev.stripe-checkout.product.sku.help',
            'translate' => false,
            'type' => 'text',
        ];
    }

    /** @return array<string, mixed> */
    public static function requiresShipping(App $kirby): array
    {
        return [
            'label' => 'programmatordev.stripe-checkout.product.shipping.label',
            'help' => 'programmatordev.stripe-checkout.product.shipping.help',
            'default' => 'inherit',
            'empty' => false,
            'options' => [
                'inherit' => ['*' => 'programmatordev.stripe-checkout.product.shipping.inherit'],
                'yes' => ['*' => 'programmatordev.stripe-checkout.product.shipping.yes'],
                'no' => ['*' => 'programmatordev.stripe-checkout.product.shipping.no'],
            ],
            'translate' => false,
            'type' => 'select',
        ];
    }

    /** @return array<string, mixed> */
    public static function variants(App $kirby): array
    {
        try {
            $settings = (new RuntimeFactory($kirby))->settings();
            $currency = $settings->currency();

            if ($currency === null) {
                throw new ConfigurationException('configuration.required', 'settings.currency');
            }
        } catch (ConfigurationException) {
            return self::configurationWarning();
        }

        return [
            'label' => 'programmatordev.stripe-checkout.variants.label',
            'help' => 'programmatordev.stripe-checkout.variants.help',
            'currency' => $currency,
            'presets' => (new VariantPresetLibrary($kirby))->all(),
            'priceSource' => $settings->priceSource()->value,
            'type' => 'stripe-checkout-variants',
        ];
    }

    /** @return array<string, mixed> */
    private static function configurationWarning(): array
    {
        return [
            'label' => 'programmatordev.stripe-checkout.product.configuration.label',
            'text' => 'programmatordev.stripe-checkout.product.configuration.help',
            'theme' => 'warning',
            'type' => 'info',
        ];
    }

    private static function currency(App $kirby): string
    {
        $currency = (new RuntimeFactory($kirby))->settings()->currency();

        if ($currency === null) {
            throw new ConfigurationException('configuration.required', 'settings.currency');
        }

        return $currency;
    }
}
