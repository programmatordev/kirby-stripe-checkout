<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping;

use ProgrammatorDev\StripeCheckout\Shipping\Exception\InvalidShippingQuoteException;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;

/** Contains one validated shipping outcome without exposing provider resources. */
final readonly class ShippingQuote
{
    /** @var list<ShippingOption> */
    private array $options;

    /** @param array<mixed> $options */
    private function __construct(
        private ShippingQuoteStatus $status,
        array $options,
        private ?string $issueCode,
    ) {
        if ($status === ShippingQuoteStatus::Available) {
            $this->options = self::validateOptions($options);

            if ($issueCode !== null) {
                throw new InvalidShippingQuoteException();
            }

            return;
        }

        if ($options !== [] || self::validIssueCode($issueCode) === false) {
            throw new InvalidShippingQuoteException();
        }

        $this->options = [];
    }

    /** @param array<mixed> $options */
    public static function available(array $options): self
    {
        return new self(ShippingQuoteStatus::Available, $options, null);
    }

    public static function destinationRequired(
        string $issueCode = ShippingErrorCode::DESTINATION_REQUIRED,
    ): self {
        return new self(ShippingQuoteStatus::DestinationRequired, [], $issueCode);
    }

    public static function unavailable(
        string $issueCode = ShippingErrorCode::UNAVAILABLE,
    ): self {
        return new self(ShippingQuoteStatus::Unavailable, [], $issueCode);
    }

    public function status(): ShippingQuoteStatus
    {
        return $this->status;
    }

    /** @return list<ShippingOption> */
    public function options(): array
    {
        return $this->options;
    }

    public function issueCode(): ?string
    {
        return $this->issueCode;
    }

    /**
     * @param array<mixed> $options
     * @return list<ShippingOption>
     */
    private static function validateOptions(array $options): array
    {
        // Checkout accepts at most five shipping options for one Session.
        // https://docs.stripe.com/api/checkout/sessions/create#checkout_session_create-shipping_options
        if (array_is_list($options) === false || $options === [] || count($options) > 5) {
            throw new InvalidShippingQuoteException();
        }

        $keys = [];
        $labels = [];
        $currency = null;

        foreach ($options as $option) {
            if (
                $option instanceof ShippingOption === false
                || isset($keys[$option->key()])
                || isset($labels[$option->label()])
            ) {
                throw new InvalidShippingQuoteException();
            }

            $optionCurrency = $option->amount()->getCurrency()->getCurrencyCode();

            if ($currency !== null && $currency !== $optionCurrency) {
                throw new InvalidShippingQuoteException(ShippingErrorCode::CURRENCY_MISMATCH);
            }

            $keys[$option->key()] = true;
            $labels[$option->label()] = true;
            $currency = $optionCurrency;
        }

        /** @var list<ShippingOption> $options */
        return $options;
    }

    private static function validIssueCode(?string $issueCode): bool
    {
        return is_string($issueCode)
            && str_starts_with($issueCode, 'shipping.')
            && strlen($issueCode) <= 128
            && TextValidator::isSingleLine($issueCode)
            && preg_match('/\Ashipping\.[a-z0-9_.-]+\z/D', $issueCode) === 1;
    }
}
