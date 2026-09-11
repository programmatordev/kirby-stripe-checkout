<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
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
        mixed $uiMode = null,
        mixed $checkoutExpirationMinutes = null,
        mixed $successDestination = null,
        mixed $cancelDestination = null,
        mixed $returnDestination = null,
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
        $this->uiMode = $this->normalizeUiMode($uiMode);
        $this->checkoutExpirationMinutes = $this->normalizeExpiration($checkoutExpirationMinutes);
        $this->successDestination = $this->normalizeDestination($successDestination, 'successDestination');
        $this->cancelDestination = $this->normalizeDestination($cancelDestination, 'cancelDestination');
        $this->returnDestination = $this->normalizeDestination($returnDestination, 'returnDestination');
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
    private readonly ?string $uiMode;
    private readonly ?int $checkoutExpirationMinutes;
    private readonly ?string $successDestination;
    private readonly ?string $cancelDestination;
    private readonly ?string $returnDestination;

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

    public function uiMode(): ?string
    {
        return $this->uiMode;
    }

    public function checkoutExpirationMinutes(): ?int
    {
        return $this->checkoutExpirationMinutes;
    }

    public function successDestination(): ?string
    {
        return $this->successDestination;
    }

    public function cancelDestination(): ?string
    {
        return $this->cancelDestination;
    }

    public function returnDestination(): ?string
    {
        return $this->returnDestination;
    }

    public function value(string $name): string|bool|int|null
    {
        return match ($name) {
            'priceSource' => $this->priceSource(),
            'currency' => $this->currency(),
            'defaultRequiresShipping' => $this->defaultRequiresShipping(),
            'uiMode' => $this->uiMode(),
            'checkoutExpirationMinutes' => $this->checkoutExpirationMinutes(),
            'successDestination' => $this->successDestination(),
            'cancelDestination' => $this->cancelDestination(),
            'returnDestination' => $this->returnDestination(),
            default => $this->retention[$name] ?? null,
        };
    }

    private function normalizeUiMode(mixed $value): ?string
    {
        $value = $value === '' ? null : $value;

        if ($value !== null && (is_string($value) === false || UiMode::tryFrom($value) === null)) {
            throw new ConfigurationException('persistence.content_invalid', 'settings.uiMode');
        }

        return $value;
    }

    private function normalizeExpiration(mixed $value): ?int
    {
        if ($value === '' || $value === null) {
            return null;
        }

        $expiration = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => Defaults::CHECKOUT_EXPIRATION_MINUTES_MIN,
                'max_range' => Defaults::CHECKOUT_EXPIRATION_MINUTES_MAX,
            ],
        ]);

        if ($expiration === false || is_float($value) || is_bool($value)) {
            throw new ConfigurationException(
                'persistence.content_invalid',
                'settings.checkoutExpirationMinutes',
            );
        }

        return $expiration;
    }

    private function normalizeDestination(mixed $value, string $name): ?string
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (is_string($value) === false || trim($value) !== $value) {
            throw new ConfigurationException('persistence.content_invalid', 'settings.' . $name);
        }

        return $value;
    }
}
