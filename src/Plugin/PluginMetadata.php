<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Plugin;

/** @internal Canonical plugin identity and protected Stripe metadata keys. */
final class PluginMetadata
{
    /** Kirby plugin name and persisted integration owner marker. */
    public const NAME = 'programmatordev/stripe-checkout';

    /** Composer package name reported through Stripe application information. */
    public const PACKAGE_NAME = 'programmatordev/kirby-stripe-checkout';

    public const URL = 'https://github.com/programmatordev/kirby-stripe-checkout';

    public const OWNER_KEY = 'kirby_stripe_checkout_owner';

    public const ORDER_KEY = 'kirby_stripe_checkout_order';

    public const LINE_KEY = 'kirby_stripe_checkout_line';

    public const SHIPPING_OPTION_KEY = 'kirby_stripe_checkout_shipping_option';

    public const SHIPPING_QUOTE_KEY = 'kirby_stripe_checkout_shipping_quote';
}
