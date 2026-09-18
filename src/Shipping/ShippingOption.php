<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping;

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Shipping\Exception\InvalidShippingOptionException;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;
use ProgrammatorDev\StripeCheckout\Tax\TaxBehavior;
use Throwable;

/** Exposes one validated, localized, fixed whole-order shipping option. */
final readonly class ShippingOption
{
    public function __construct(
        private string $key,
        private string $label,
        private Money $amount,
        private ?DeliveryEstimate $deliveryEstimate = null,
        private TaxBehavior $taxBehavior = TaxBehavior::StripeDefault,
        private ?string $taxCode = null,
    ) {
        if (
            preg_match('/\A[a-z0-9_-]{1,64}\z/D', $key) !== 1
        ) {
            throw new InvalidShippingOptionException('key', 'A shipping option requires a valid stable key.');
        }

        // This becomes Stripe's customer-facing `display_name`, which is
        // limited to 100 characters.
        // https://docs.stripe.com/api/checkout/sessions/create#checkout_session_create-shipping_options-shipping_rate_data-display_name
        if (
            $label === ''
            || trim($label) !== $label
            || TextValidator::isSingleLine($label) === false
            || mb_strlen($label) > 100
        ) {
            throw new InvalidShippingOptionException('label', 'A shipping option requires a valid label.');
        }

        try {
            (new StripeCurrencyRegistry())->fromMoney($amount);
        } catch (Throwable $error) {
            throw new InvalidShippingOptionException('amount', 'A shipping option requires an exact non-negative amount.');
        }

        if ($taxCode !== null && preg_match('/\Atxcd_[A-Za-z0-9]{1,249}\z/D', $taxCode) !== 1) {
            throw new InvalidShippingOptionException('taxCode', 'A shipping option requires a valid Stripe Tax Code.');
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function deliveryEstimate(): ?DeliveryEstimate
    {
        return $this->deliveryEstimate;
    }

    public function taxBehavior(): TaxBehavior
    {
        return $this->taxBehavior;
    }

    public function taxCode(): ?string
    {
        return $this->taxCode;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'amount' => $this->amount->getAmount()->toString(),
            'deliveryEstimate' => $this->deliveryEstimate?->toArray(),
            'taxBehavior' => $this->taxBehavior->value,
            'taxCode' => $this->taxCode,
        ];
    }
}
