<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Plugin;

/** @internal Canonical plugin and Composer package identity metadata. */
final class PluginMetadata
{
    /** Kirby plugin name and persisted integration owner marker. */
    public const NAME = 'programmatordev/stripe-checkout';

    /** Composer package name reported through Stripe application information. */
    public const PACKAGE_NAME = 'programmatordev/kirby-stripe-checkout';

    public const URL = 'https://github.com/programmatordev/kirby-stripe-checkout';
}
