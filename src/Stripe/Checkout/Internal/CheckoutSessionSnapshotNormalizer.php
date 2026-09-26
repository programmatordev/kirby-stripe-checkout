<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Checkout\Internal;

use Brick\Math\BigDecimal;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\AddressSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\ConsentSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\CustomerSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\CustomFieldSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\DiscountSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\ShippingSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\TaxSnapshot;
use ProgrammatorDev\StripeCheckout\Plugin\PluginMetadata;
use ProgrammatorDev\StripeCheckout\Shipping\DeliveryEstimate;
use ProgrammatorDev\StripeCheckout\Shipping\DeliveryEstimateUnit;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionRecord;
use Stripe\ShippingRate;
use Throwable;

/**
 * Converts selected untrusted Stripe Session fields into canonical order facts.
 *
 * It reads only the current provider result. Settings and the initiating request
 * cannot stand in for fields Stripe omitted, changed, or populated at Checkout.
 *
 * @internal
 * @see https://docs.stripe.com/api/checkout/sessions/object
 */
final class CheckoutSessionSnapshotNormalizer
{
    /**
     * @return array{
     *   stripeCustomerId: ?string,
     *   customer: ?array<string, mixed>,
     *   billingAddress: ?array<string, mixed>,
     *   shippingAddress: ?array<string, mixed>,
     *   stripeShippingRateId: ?string,
     *   shipping: ?array<string, mixed>,
     *   shippingTotal: ?string,
     *   customFields: list<array<string, mixed>>,
     *   consent: ?array<string, mixed>,
     *   discounts: list<array<string, mixed>>,
     *   discountTotal: ?string,
     *   tax: ?array<string, mixed>,
     *   taxTotal: ?string
     * }
     */
    public function normalize(CheckoutSessionRecord $sessionRecord): array
    {
        try {
            $sessionData = $sessionRecord->orderSnapshotSource;
            $customerDetails = $this->nullableMap($sessionData['customer_details'] ?? null);
            $collectedInformation = $this->nullableMap($sessionData['collected_information'] ?? null);
            $customer = $customerDetails === null ? null : $this->customer($customerDetails);
            $billingAddress = $customerDetails === null ? null : $this->billingAddress($customerDetails);
            $shippingAddress = $collectedInformation === null
                ? null
                : $this->shippingAddress($collectedInformation);
            [$shippingRateId, $shipping, $shippingTotal] = $this->shipping(
                sessionData: $sessionData,
                currency: $sessionRecord->currency,
            );
            [$discounts, $discountTotal] = $this->discounts(
                sessionData: $sessionData,
                currency: $sessionRecord->currency,
            );
            $tax = $this->tax($sessionData, $sessionRecord->currency);

            return [
                'stripeCustomerId' => $this->referenceId($sessionData['customer'] ?? null, 'cus_'),
                'customer' => $customer?->toArray(),
                'billingAddress' => $billingAddress?->toArray(),
                'shippingAddress' => $shippingAddress?->toArray(),
                'stripeShippingRateId' => $shippingRateId,
                'shipping' => $shipping?->toArray(),
                'shippingTotal' => $shippingTotal,
                'customFields' => array_map(
                    static fn(CustomFieldSnapshot $customField): array => $customField->toArray(),
                    $this->customFields($sessionData['custom_fields'] ?? []),
                ),
                'consent' => $this->consent($sessionData['consent'] ?? null)?->toArray(),
                'discounts' => array_map(
                    static fn(DiscountSnapshot $discount): array => $discount->toArray(),
                    $discounts,
                ),
                'discountTotal' => $discountTotal,
                'tax' => $tax?->toArray(),
                'taxTotal' => $tax?->amount(),
            ];
        } catch (Throwable) {
            throw new OrderDataException();
        }
    }

    /**
     * @param array<string, mixed> $sessionData
     * @return array{?string, ?ShippingSnapshot, ?string}
     */
    private function shipping(array $sessionData, ?string $currency): array
    {
        $totalDetails = $this->nullableMap($sessionData['total_details'] ?? null);
        $providerShippingTotal = $totalDetails['amount_shipping'] ?? null;
        $shippingCost = $this->nullableMap($sessionData['shipping_cost'] ?? null);

        if ($shippingCost === null) {
            if ($providerShippingTotal === null) {
                return [null, null, null];
            }

            // An explicit zero is an authoritative no-shipping result. A missing
            // amount remains unknown and must not be converted into a zero fact.
            if ($providerShippingTotal !== 0 || $currency === null) {
                throw new OrderDataException();
            }

            return [null, null, $this->providerAmount(0, $currency)];
        }

        if (is_int($providerShippingTotal) === false || $currency === null) {
            throw new OrderDataException();
        }

        $shippingRate = $shippingCost['shipping_rate'] ?? null;
        $shippingRateId = $this->referenceId($shippingRate, 'shr_');
        $shippingRateData = $this->referenceData($shippingRate);

        // A selected ID alone proves the reference but cannot provide the
        // immutable rate details required by the final order snapshot.
        if ($shippingRateId === null || $shippingRateData === []) {
            throw new OrderDataException();
        }

        if (($shippingRateData['type'] ?? null) !== ShippingRate::TYPE_FIXED_AMOUNT) {
            throw new OrderDataException();
        }

        $providerSubtotal = $shippingCost['amount_subtotal'] ?? null;
        $providerTax = $shippingCost['amount_tax'] ?? null;
        $providerTotal = $shippingCost['amount_total'] ?? null;
        $fixedAmount = $this->map($shippingRateData['fixed_amount'] ?? null);
        $fixedProviderAmount = $fixedAmount['amount'] ?? null;
        $fixedCurrency = $fixedAmount['currency'] ?? null;

        // The Rate's fixed amount describes the pre-tax, pre-discount shipping
        // subtotal. Stripe's returned shipping total may differ after both.
        // https://docs.stripe.com/api/checkout/sessions/object#checkout_session_object-shipping_cost
        if (
            is_int($providerSubtotal) === false
            || is_int($providerTax) === false
            || is_int($providerTotal) === false
            || $providerShippingTotal !== $providerTotal
            || $fixedProviderAmount !== $providerSubtotal
            || is_string($fixedCurrency) === false
            || strtoupper($fixedCurrency) !== strtoupper($currency)
        ) {
            throw new OrderDataException();
        }

        $metadata = $this->map($shippingRateData['metadata'] ?? null);

        if (($metadata[PluginMetadata::OWNER_KEY] ?? null) !== PluginMetadata::NAME) {
            throw new OrderDataException();
        }

        OrderData::uuid(OrderData::text($metadata[PluginMetadata::ORDER_KEY] ?? null));

        $shipping = ShippingSnapshot::fromArray([
            'optionKey' => $metadata[PluginMetadata::SHIPPING_OPTION_KEY] ?? null,
            'quoteFingerprint' => $metadata[PluginMetadata::SHIPPING_QUOTE_KEY] ?? null,
            'label' => $shippingRateData['display_name'] ?? null,
            'currency' => strtoupper($currency),
            'subtotal' => $this->providerAmount($providerSubtotal, $currency),
            'providerSubtotal' => $providerSubtotal,
            'tax' => $this->providerAmount($providerTax, $currency),
            'providerTax' => $providerTax,
            'total' => $this->providerAmount($providerTotal, $currency),
            'providerTotal' => $providerTotal,
            'deliveryEstimate' => $this->deliveryEstimate($shippingRateData['delivery_estimate'] ?? null),
            'taxBehavior' => $shippingRateData['tax_behavior'] ?? null,
            'taxCode' => $this->referenceId($shippingRateData['tax_code'] ?? null, 'txcd_'),
        ]);

        return [$shippingRateId, $shipping, $shipping->total()];
    }

    /** @return array{minimum: ?int, maximum: ?int, unit: string}|null */
    private function deliveryEstimate(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $estimate = $this->map($value);
        $minimum = $this->nullableMap($estimate['minimum'] ?? null);
        $maximum = $this->nullableMap($estimate['maximum'] ?? null);
        $minimumValue = $minimum === null ? null : OrderData::integer($minimum['value'] ?? null);
        $maximumValue = $maximum === null ? null : OrderData::integer($maximum['value'] ?? null);
        $unitValue = $minimum['unit'] ?? $maximum['unit'] ?? null;

        if (
            is_string($unitValue) === false
            || ($minimum !== null && ($minimum['unit'] ?? null) !== $unitValue)
            || ($maximum !== null && ($maximum['unit'] ?? null) !== $unitValue)
        ) {
            throw new OrderDataException();
        }

        return (new DeliveryEstimate(
            minimum: $minimumValue,
            maximum: $maximumValue,
            unit: DeliveryEstimateUnit::from($unitValue),
        ))->toArray();
    }

    private function providerAmount(int $providerAmount, string $currency): string
    {
        $registry = new StripeCurrencyRegistry();

        return (string) $registry
            ->toMoney($registry->fromProviderAmount($providerAmount, strtoupper($currency)))
            ->getAmount();
    }

    /** @param array<string, mixed> $sessionData */
    private function tax(array $sessionData, ?string $currency): ?TaxSnapshot
    {
        if (array_key_exists('automatic_tax', $sessionData) === false) {
            return null;
        }

        $automaticTax = $this->map($sessionData['automatic_tax']);
        $totalDetails = $this->nullableMap($sessionData['total_details'] ?? null);
        $providerAmount = $totalDetails['amount_tax'] ?? null;
        $breakdown = null;
        $totalBreakdown = $this->nullableMap($totalDetails['breakdown'] ?? null);

        // Aggregated rates and per-line rates describe overlapping allocations.
        // Keep their targets distinct; never sum both or recompute tax locally.
        // https://docs.stripe.com/api/checkout/sessions/object#checkout_session_object-total_details-breakdown-taxes
        if ($totalBreakdown !== null && array_key_exists('taxes', $totalBreakdown)) {
            $breakdown = $this->taxEntries($totalBreakdown['taxes'], 'order', null, $currency);

            // Preserve the meaning of an explicitly empty aggregate before
            // appending separately expanded line/shipping allocations.
            if ($breakdown === [] && $providerAmount !== null && $providerAmount !== 0) {
                throw new OrderDataException();
            }
        }

        if (($sessionData['line_items'] ?? null) !== null) {
            $lineItems = $this->map($sessionData['line_items']);

            // An expanded Session contains only the first handful of lines. The
            // reconciliation caller must supply a fully paginated collection.
            // https://docs.stripe.com/api/checkout/sessions/line_items
            if (($lineItems['has_more'] ?? null) !== false) {
                throw new OrderDataException();
            }

            foreach ($this->list($lineItems['data'] ?? null) as $lineItem) {
                $lineItem = $this->map($lineItem);

                if (($lineItem['taxes'] ?? null) !== null) {
                    $breakdown ??= [];
                    array_push($breakdown, ...$this->taxEntries(
                        value: $lineItem['taxes'],
                        target: 'line_item',
                        targetId: OrderData::text($lineItem['id'] ?? null, 255),
                        currency: $currency,
                    ));
                }
            }
        }

        $shippingCost = $this->nullableMap($sessionData['shipping_cost'] ?? null);

        if (($shippingCost['taxes'] ?? null) !== null) {
            $breakdown ??= [];
            array_push($breakdown, ...$this->taxEntries(
                value: $shippingCost['taxes'],
                target: 'shipping',
                targetId: $this->referenceId($shippingCost['shipping_rate'] ?? null, 'shr_'),
                currency: $currency,
            ));
        }

        return TaxSnapshot::fromArray([
            'automaticTaxEnabled' => $automaticTax['enabled'] ?? null,
            'calculationStatus' => $automaticTax['status'] ?? null,
            'provider' => $automaticTax['provider'] ?? null,
            'currency' => $currency === null ? null : strtoupper($currency),
            'amount' => $this->taxAmount($providerAmount, $currency),
            'providerAmount' => $providerAmount,
            // Null means unexpanded/unavailable, not a calculated empty breakdown.
            'breakdown' => $breakdown,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function taxEntries(mixed $value, string $target, ?string $targetId, ?string $currency): array
    {
        $entries = [];

        foreach ($this->list($value) as $tax) {
            $tax = $this->map($tax);
            $rate = $this->map($tax['rate'] ?? null);
            $entries[] = [
                'target' => $target,
                'targetId' => $targetId,
                'amount' => $this->taxAmount($tax['amount'] ?? null, $currency),
                'providerAmount' => $tax['amount'] ?? null,
                'currency' => $currency === null ? null : strtoupper($currency),
                'taxableAmount' => $this->taxAmount($tax['taxable_amount'] ?? null, $currency),
                'providerTaxableAmount' => $tax['taxable_amount'] ?? null,
                'rateId' => $this->referenceId($rate['id'] ?? null, 'txr_'),
                'inclusive' => $rate['inclusive'] ?? null,
                'percentage' => $this->percentage($rate['percentage'] ?? null),
                'effectivePercentage' => $this->percentage($rate['effective_percentage'] ?? null),
                'jurisdiction' => $rate['jurisdiction'] ?? null,
                'jurisdictionLevel' => $rate['jurisdiction_level'] ?? null,
                'country' => $rate['country'] ?? null,
                'state' => $rate['state'] ?? null,
                'taxType' => $rate['tax_type'] ?? null,
                'rateType' => $rate['rate_type'] ?? null,
                'displayName' => $rate['display_name'] ?? null,
                'taxabilityReason' => $tax['taxability_reason'] ?? null,
            ];
        }

        return $entries;
    }

    private function taxAmount(mixed $value, ?string $currency): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($currency === null) {
            throw new OrderDataException();
        }

        return $this->providerAmount(OrderData::integer($value), $currency);
    }

    /** @param array<string, mixed> $details */
    private function customer(array $details): CustomerSnapshot
    {
        $taxIds = [];

        foreach ($this->list($details['tax_ids'] ?? []) as $taxId) {
            $taxId = $this->map($taxId);
            $taxIds[] = [
                'type' => $taxId['type'] ?? null,
                'value' => $taxId['value'] ?? null,
            ];
        }

        return CustomerSnapshot::fromArray([
            'email' => $details['email'] ?? null,
            'individualName' => $details['individual_name'] ?? null,
            'businessName' => $details['business_name'] ?? null,
            'phone' => $details['phone'] ?? null,
            'taxIds' => $taxIds,
        ]);
    }

    /** @param array<string, mixed> $details */
    private function billingAddress(array $details): ?AddressSnapshot
    {
        if (($details['address'] ?? null) === null) {
            return null;
        }

        return $this->address(
            address: $this->map($details['address']),
            name: $details['name'] ?? null,
        );
    }

    /** @param array<string, mixed> $collectedInformation */
    private function shippingAddress(array $collectedInformation): ?AddressSnapshot
    {
        if (($collectedInformation['shipping_details'] ?? null) === null) {
            return null;
        }

        $shippingDetails = $this->map($collectedInformation['shipping_details']);

        return $this->address(
            address: $this->map($shippingDetails['address'] ?? null),
            name: $shippingDetails['name'] ?? null,
        );
    }

    /** @param array<string, mixed> $address */
    private function address(array $address, mixed $name): AddressSnapshot
    {
        $country = $address['country'] ?? null;

        if (is_string($country)) {
            $country = strtoupper($country);
        }

        return AddressSnapshot::fromArray([
            'name' => $name,
            'line1' => $address['line1'] ?? null,
            'line2' => $address['line2'] ?? null,
            'postalCode' => $address['postal_code'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'country' => $country,
        ]);
    }

    /** @return list<CustomFieldSnapshot> */
    private function customFields(mixed $values): array
    {
        $values = $this->list($values);

        // Stripe currently returns at most three custom fields for a Session.
        // https://docs.stripe.com/api/checkout/sessions/object#checkout_session_object-custom_fields
        if (count($values) > 3) {
            throw new OrderDataException();
        }

        $customFields = [];
        $keys = [];

        foreach ($values as $value) {
            $customField = $this->map($value);
            $key = $customField['key'] ?? null;

            if (is_string($key) === false || isset($keys[$key])) {
                throw new OrderDataException();
            }

            $keys[$key] = true;
            $type = OrderData::text($customField['type'] ?? null, 20);
            $typeData = $this->map($customField[$type] ?? null);
            $label = $this->map($customField['label'] ?? null);
            $answer = $typeData['value'] ?? null;

            if (($label['type'] ?? null) !== 'custom') {
                throw new OrderDataException();
            }

            $customFields[] = CustomFieldSnapshot::fromArray([
                'key' => $key,
                'type' => $type,
                'label' => $label['custom'] ?? null,
                'required' => isset($customField['optional']) && is_bool($customField['optional'])
                    ? $customField['optional'] === false
                    : null,
                // Presence in the returned Session establishes configuration for
                // that Session without consulting current plugin Settings.
                'configured' => true,
                'answered' => $answer !== null,
                'value' => $answer,
            ]);
        }

        return $customFields;
    }

    private function consent(mixed $value): ?ConsentSnapshot
    {
        if ($value === null) {
            return null;
        }

        $consent = $this->map($value);

        return ConsentSnapshot::fromArray([
            'termsOfService' => $consent['terms_of_service'] ?? null,
            'promotions' => $consent['promotions'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $sessionData
     * @return array{list<DiscountSnapshot>, ?string}
     */
    private function discounts(array $sessionData, ?string $currency): array
    {
        if (($sessionData['total_details'] ?? null) === null) {
            return [[], null];
        }

        $totalDetails = $this->map($sessionData['total_details']);
        $providerTotal = $totalDetails['amount_discount'] ?? null;

        if (is_int($providerTotal) === false || $providerTotal < 0 || is_string($currency) === false) {
            throw new OrderDataException();
        }

        $currency = strtoupper($currency);
        $registry = new StripeCurrencyRegistry();
        $total = $registry->toMoney($registry->fromProviderAmount($providerTotal, $currency));
        $breakdown = $this->nullableMap($totalDetails['breakdown'] ?? null);
        $discountEntries = $breakdown === null ? [] : $this->list($breakdown['discounts'] ?? []);
        $discounts = [];
        $calculatedTotal = 0;

        foreach ($discountEntries as $entry) {
            $entry = $this->map($entry);
            $providerAmount = $entry['amount'] ?? null;

            if (is_int($providerAmount) === false || $providerAmount < 0) {
                throw new OrderDataException();
            }

            if ($providerAmount > PHP_INT_MAX - $calculatedTotal) {
                throw new OrderDataException();
            }

            $calculatedTotal += $providerAmount;
            $discount = $this->map($entry['discount'] ?? null);
            $promotionCode = $discount['promotion_code']
                ?? null;
            $promotionCodeData = $this->referenceData($promotionCode);
            $discountSource = $this->nullableMap($discount['source'] ?? null) ?? [];
            $coupon = $discountSource['coupon']
                ?? $discount['coupon']
                ?? $this->promotionCoupon($promotionCodeData);
            $couponData = $this->referenceData($coupon);
            $restrictions = $this->nullableMap($promotionCodeData['restrictions'] ?? null) ?? [];
            $minimumAmount = $restrictions['minimum_amount'] ?? null;
            $minimumCurrency = $restrictions['minimum_amount_currency'] ?? null;

            // Promotion Codes may return a currency-specific minimum instead of
            // the legacy top-level pair. Select only this Session's currency.
            // https://docs.stripe.com/api/promotion_codes/object#promotion_code_object-restrictions-currency_options
            if ($minimumAmount === null && isset($restrictions['currency_options'])) {
                $currencyOptions = $this->map($restrictions['currency_options']);
                $currencyMinimum = $this->nullableMap($currencyOptions[strtolower($currency)] ?? null);

                if ($currencyMinimum !== null) {
                    $minimumAmount = $currencyMinimum['minimum_amount'] ?? null;
                    $minimumCurrency = $currency;
                }
            }

            if (($minimumAmount === null) !== ($minimumCurrency === null)) {
                throw new OrderDataException();
            }

            $minimumAmountValue = null;

            if ($minimumAmount !== null) {
                if (is_int($minimumAmount) === false || is_string($minimumCurrency) === false) {
                    throw new OrderDataException();
                }

                $minimumCurrency = strtoupper($minimumCurrency);
                $minimumAmountValue = (string) $registry
                    ->toMoney($registry->fromProviderAmount($minimumAmount, $minimumCurrency))
                    ->getAmount();
            }

            $discounts[] = DiscountSnapshot::fromArray([
                'discountId' => $discount['id'] ?? null,
                'couponId' => $this->referenceId($coupon, null),
                'promotionCodeId' => $this->referenceId($promotionCode, 'promo_'),
                'couponName' => $couponData['name'] ?? null,
                'promotionCode' => $promotionCodeData['code'] ?? null,
                'amount' => (string) $registry
                    ->toMoney($registry->fromProviderAmount($providerAmount, $currency))
                    ->getAmount(),
                'currency' => $currency,
                'providerAmount' => $providerAmount,
                'percentOff' => $this->percentage($couponData['percent_off'] ?? null),
                'appliesToProducts' => $this->appliesToProducts($couponData['applies_to'] ?? null),
                'firstTimeTransaction' => $restrictions['first_time_transaction'] ?? null,
                'minimumAmount' => $minimumAmountValue,
                'minimumAmountCurrency' => $minimumCurrency,
                'providerMinimumAmount' => $minimumAmount,
            ]);
        }

        if ($calculatedTotal !== $providerTotal || ($providerTotal > 0 && $discounts === [])) {
            throw new OrderDataException();
        }

        return [$discounts, (string) $total->getAmount()];
    }

    /** @param array<string, mixed> $promotionCode */
    private function promotionCoupon(array $promotionCode): mixed
    {
        $promotion = $this->nullableMap($promotionCode['promotion'] ?? null);

        return $promotion['coupon'] ?? null;
    }

    /** @return list<mixed> */
    private function appliesToProducts(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $appliesTo = $this->map($value);

        return $this->list($appliesTo['products'] ?? []);
    }

    private function percentage(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value) && is_finite($value)) {
            return (string) BigDecimal::of((string) $value)->strippedOfTrailingZeros();
        }

        if (is_string($value)) {
            return $value;
        }

        throw new OrderDataException();
    }

    /** @return array<string, mixed> */
    private function referenceData(mixed $value): array
    {
        if ($value === null || is_string($value)) {
            return [];
        }

        return $this->map($value);
    }

    private function referenceId(mixed $value, ?string $prefix): ?string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                throw new OrderDataException();
            }

            $value = $value['id'] ?? null;
        }

        if ($value === null) {
            return null;
        }

        $value = OrderData::text($value, 255);

        if ($prefix !== null && preg_match('/\A' . $prefix . '[A-Za-z0-9_]+\z/D', $value) !== 1) {
            throw new OrderDataException();
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function map(mixed $value): array
    {
        if (is_array($value) === false || array_is_list($value)) {
            throw new OrderDataException();
        }

        foreach (array_keys($value) as $key) {
            if (is_string($key) === false) {
                throw new OrderDataException();
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return array<string, mixed>|null */
    private function nullableMap(mixed $value): ?array
    {
        return $value === null ? null : $this->map($value);
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        if (is_array($value) === false || array_is_list($value) === false) {
            throw new OrderDataException();
        }

        return $value;
    }
}
