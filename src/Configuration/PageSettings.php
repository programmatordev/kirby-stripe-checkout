<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;

/**
 * Carries validated non-secret values read from the protected hub Page.
 *
 * @internal
 */
final class PageSettings
{
    /** @var array<string, bool|int|null> */
    private readonly array $retention;

    public function __construct(
        mixed $priceSource = null,
        mixed $currency = null,
        mixed $defaultRequiresShipping = null,
        mixed $cleanupCreationFailures = null,
        mixed $creationFailureRetentionDays = null,
        mixed $cleanupUnpaidOrders = null,
        mixed $unpaidOrderRetentionDays = null,
    ) {
        $priceSource = $priceSource === '' ? null : $priceSource;

        if (
            $priceSource !== null
            && (
                is_string($priceSource) === false
                || PriceSource::tryFrom($priceSource) === null
            )
        ) {
            throw new ConfigurationException(
                'persistence.content_invalid',
                'settings.priceSource',
            );
        }

        $currency = $currency === '' ? null : $currency;

        if (
            $currency !== null
            && (
                is_string($currency) === false
                || (new StripeCurrencyRegistry())->supports($currency) === false
            )
        ) {
            throw new ConfigurationException(
                'persistence.content_invalid',
                'settings.currency',
            );
        }

        // Kirby's select field stores stable strings; PHP configuration reaches
        // the resolver separately as a native boolean.
        $defaultRequiresShipping = match ($defaultRequiresShipping) {
            null, '' => null,
            true, 'yes' => true,
            false, 'no' => false,
            default => throw new ConfigurationException(
                'persistence.content_invalid',
                'settings.defaultRequiresShipping',
            ),
        };

        $this->priceSource = $priceSource;
        $this->currency = $currency;
        $this->defaultRequiresShipping = $defaultRequiresShipping;
        $retention = compact('cleanupCreationFailures', 'creationFailureRetentionDays', 'cleanupUnpaidOrders', 'unpaidOrderRetentionDays');

        foreach (Defaults::RETENTION as $name => $default) {
            $value = $retention[$name];

            if ($value === '' || $value === null) {
                $retention[$name] = null;

                continue;
            }

            // Kirby persists toggle/number fields as text. Normalize only that
            // transport here; PHP options are checked without string coercion.
            $value = is_bool($default)
                ? match ($value) {
                    true, 'true' => true,
                    false, 'false' => false,
                    default => null,
                }
            : filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($value === null || (is_int($default) && (is_int($value) === false || is_float($retention[$name]) || is_bool($retention[$name])))) {
                throw new ConfigurationException('persistence.content_invalid', 'settings.' . $name);
            }

            $retention[$name] = $value;
        }

        $this->retention = $retention;
    }

    private readonly ?string $priceSource;
    private readonly ?string $currency;
    private readonly ?bool $defaultRequiresShipping;

    public function priceSource(): ?string
    {
        return $this->priceSource;
    }

    public function currency(): ?string
    {
        return $this->currency;
    }

    public function defaultRequiresShipping(): ?bool
    {
        return $this->defaultRequiresShipping;
    }

    public function value(string $name): string|bool|int|null
    {
        return match ($name) {
            'priceSource' => $this->priceSource(),
            'currency' => $this->currency(),
            'defaultRequiresShipping' => $this->defaultRequiresShipping(),
            default => $this->retention[$name] ?? null,
        };
    }
}
