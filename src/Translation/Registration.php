<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Translation;

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;

/**
 * Applies validated custom overrides after Kirby has loaded all plugins.
 *
 * @internal
 */
final class Registration
{
    public static function applyCustomOverrides(App $kirby): void
    {
        /** @var array<string, mixed> $options */
        $options = $kirby->options();
        $report = (new ConfigurationResolver())->resolve($options);

        if ($report->isValid() === false) {
            return;
        }

        $overrides = [];

        foreach ($report->configurationOrFail()->translations() as $locale => $translations) {
            foreach ($translations as $suffix => $value) {
                $overrides[$locale][Catalogue::PREFIX . $suffix] = $value;
            }
        }

        if ($overrides !== []) {
            $kirby->extend(['translations' => $overrides]);
        }
    }
}
