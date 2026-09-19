<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingOption;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuoteStatus;
use ProgrammatorDev\StripeCheckout\Shipping\StripeShippingCountryRegistry;

/**
 * @internal Frozen shipping quote used only while creating one Checkout Session request.
 *
 * The checkout fingerprint binds the quote to its inputs; the quote fingerprint
 * identifies the exact offered shipping policy and options.
 */
final readonly class InitiatingShippingSnapshot
{
    /**
     * @param list<string> $allowedCountries
     * @param list<ShippingOption> $options
     */
    private function __construct(
        private array $allowedCountries,
        private string $currency,
        private array $options,
        private string $checkoutFingerprint,
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
        // A countryless quote comes from a resolver/fallback that is valid for
        // every destination, so Checkout may expose Stripe's complete allowlist.
        $allowedCountries = $shippingCountry === null
            ? (new StripeShippingCountryRegistry())->codes()
            : [$shippingCountry];
        $quoteFacts = self::quoteFacts(
            shippingCountry: $shippingCountry,
            allowedCountries: $allowedCountries,
            locale: $checkout->locale(),
            currency: $currency,
            options: $quote->options(),
        );

        return new self(
            allowedCountries: $allowedCountries,
            currency: $currency,
            options: $quote->options(),
            checkoutFingerprint: CheckoutContextFingerprint::fromCheckout($checkout),
            quoteFingerprint: hash('sha256', OrderData::json($quoteFacts)),
        );
    }

    /** @return list<string> */
    public function allowedCountries(): array
    {
        return $this->allowedCountries;
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

    public function matches(OrderCreationContext $order): bool
    {
        return hash_equals(
            $this->checkoutFingerprint,
            CheckoutContextFingerprint::fromOrder($order),
        );
    }

    /**
     * Locale participates only in quote correlation because a resolver may use
     * it to localize or calculate options. SessionRequestContext remains the
     * sole source for the effective Stripe locale sent to Checkout.
     *
     * @param list<string> $allowedCountries
     * @param list<ShippingOption> $options
     * @return array<string, mixed>
     */
    private static function quoteFacts(
        ?string $shippingCountry,
        array $allowedCountries,
        string $locale,
        string $currency,
        array $options,
    ): array {
        $registry = new StripeCurrencyRegistry();

        return [
            'shippingCountry' => $shippingCountry,
            'allowedCountries' => $allowedCountries,
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
