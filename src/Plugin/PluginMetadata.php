<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Plugin;

/** @internal Canonical plugin identity and protected Stripe metadata keys. */
final class PluginMetadata
{
    /** Prefix reserved for metadata owned by the plugin. */
    public const KEY_PREFIX = 'kirby_stripe_checkout_';

    /** Kirby plugin name and persisted integration owner marker. */
    public const NAME = 'programmatordev/stripe-checkout';

    /** Composer package name reported through Stripe application information. */
    public const PACKAGE_NAME = 'programmatordev/kirby-stripe-checkout';

    public const URL = 'https://github.com/programmatordev/kirby-stripe-checkout';

    public const OWNER_KEY = self::KEY_PREFIX . 'owner';

    public const ORDER_KEY = self::KEY_PREFIX . 'order';

    public const LINE_KEY = self::KEY_PREFIX . 'line';

    public const SHIPPING_OPTION_KEY = self::KEY_PREFIX . 'shipping_option';

    public const SHIPPING_QUOTE_KEY = self::KEY_PREFIX . 'shipping_quote';
}
