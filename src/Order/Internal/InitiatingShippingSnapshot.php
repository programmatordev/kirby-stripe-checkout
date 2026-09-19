<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingOption;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuoteStatus;
use ProgrammatorDev\StripeCheckout\Shipping\StripeShippingCountryRegistry;

/** @internal Frozen shipping quote used only while creating one Checkout Session request. */
final readonly class InitiatingShippingSnapshot
{
    /**
     * @param list<string> $allowedCountries
     * @param list<ShippingOption> $options
     */
    private function __construct(
        private ?string $shippingCountry,
        private array $allowedCountries,
        private ?string $languageCode,
        private string $locale,
        private string $currency,
        private array $options,
        private string $quoteFingerprint,
    ) {}

    public static function fromQuote(
        CheckoutContext $checkout,
        ShippingContext $shipping,
        ShippingQuote $quote,
    ): self {
        if (
            $checkout->shippableItems() === []
            || $quote->status() !== ShippingQuoteStatus::Available
        ) {
            throw new OrderDataException();
        }

        $currency = $checkout->currency()->getCurrencyCode();

        foreach ($quote->options() as $option) {
            if ($option->amount()->getCurrency()->getCurrencyCode() !== $currency) {
                throw new OrderDataException();
            }
        }

        $shippingCountry = $shipping->shippingCountry();
        $allowedCountries = $shippingCountry === null
            ? (new StripeShippingCountryRegistry())->codes()
            : [$shippingCountry];
        $facts = self::facts(
            shippingCountry: $shippingCountry,
            allowedCountries: $allowedCountries,
            languageCode: $checkout->languageCode(),
            locale: $checkout->locale(),
            currency: $currency,
            options: $quote->options(),
        );

        return new self(
            shippingCountry: $shippingCountry,
            allowedCountries: $allowedCountries,
            languageCode: $checkout->languageCode(),
            locale: $checkout->locale(),
            currency: $currency,
            options: $quote->options(),
            quoteFingerprint: hash('sha256', OrderData::json($facts)),
        );
    }

    public function shippingCountry(): ?string
    {
        return $this->shippingCountry;
    }

    /** @return list<string> */
    public function allowedCountries(): array
    {
        return $this->allowedCountries;
    }

    public function languageCode(): ?string
    {
        return $this->languageCode;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /** @return list<ShippingOption> */
    public function options(): array
    {
        return $this->options;
    }

    public function quoteFingerprint(): string
    {
        return $this->quoteFingerprint;
    }

    /**
     * @param list<string> $allowedCountries
     * @param list<ShippingOption> $options
     * @return array<string, mixed>
     */
    private static function facts(
        ?string $shippingCountry,
        array $allowedCountries,
        ?string $languageCode,
        string $locale,
        string $currency,
        array $options,
    ): array {
        $registry = new StripeCurrencyRegistry();

        return [
            'shippingCountry' => $shippingCountry,
            'allowedCountries' => $allowedCountries,
            'languageCode' => $languageCode,
            'locale' => $locale,
            'currency' => $currency,
            'options' => array_map(
                static fn(ShippingOption $option): array => [
                    ...$option->toArray(),
                    'currency' => $option->amount()->getCurrency()->getCurrencyCode(),
                    'providerAmount' => $registry->fromMoney($option->amount())->minorAmount(),
                ],
                $options,
            ),
        ];
    }
}
