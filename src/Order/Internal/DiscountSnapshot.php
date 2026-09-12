<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use Brick\Math\BigDecimal;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use Throwable;

/** @internal One applied discount and the safe provider facts that explain it. */
final readonly class DiscountSnapshot
{
    private const KEYS = [
        'discountId',
        'couponId',
        'promotionCodeId',
        'couponName',
        'promotionCode',
        'amount',
        'currency',
        'providerAmount',
        'percentOff',
        'appliesToProducts',
        'firstTimeTransaction',
        'minimumAmount',
        'minimumAmountCurrency',
        'providerMinimumAmount',
    ];

    /** @param array<string, mixed> $data */
    private function __construct(private array $data) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        try {
            OrderData::validateAllowedKeys($data, self::KEYS);
            OrderData::validateRequiredKeys($data, self::KEYS);
            $registry = new StripeCurrencyRegistry();
            $currency = OrderData::text($data['currency'], 3);
            $providerAmount = OrderData::integer($data['providerAmount']);
            $amount = $registry->toMoney($registry->fromProviderAmount($providerAmount, $currency));
            $minimumAmountCurrency = OrderData::nullableSingleLine($data['minimumAmountCurrency'], 3);
            $providerMinimumAmount = $data['providerMinimumAmount'] === null
                ? null
                : OrderData::integer($data['providerMinimumAmount']);
            $minimumAmount = OrderData::nullableString($data['minimumAmount']);

            if (
                $providerAmount < 0
                || strtoupper($currency) !== $currency
                || ($minimumAmount === null) !== ($minimumAmountCurrency === null)
                || ($minimumAmount === null) !== ($providerMinimumAmount === null)
            ) {
                throw new OrderDataException();
            }

            if ($minimumAmount !== null) {
                $minimumAmountCurrency = OrderData::text($minimumAmountCurrency, 3);

                if (
                    $providerMinimumAmount === null
                    || strtoupper($minimumAmountCurrency) !== $minimumAmountCurrency
                    || $providerMinimumAmount < 0
                ) {
                    throw new OrderDataException();
                }

                $minimumMoney = $registry->toMoney($registry->fromProviderAmount($providerMinimumAmount, $minimumAmountCurrency));

                if ((string) $minimumMoney->getAmount() !== $minimumAmount) {
                    throw new OrderDataException();
                }
            }

            $percentOff = OrderData::nullableString($data['percentOff']);

            if ($percentOff !== null) {
                $percentOff = (string) BigDecimal::of($percentOff)->strippedOfTrailingZeros();

                if (BigDecimal::of($percentOff)->isLessThanOrEqualTo(0) || BigDecimal::of($percentOff)->isGreaterThan(100)) {
                    throw new OrderDataException();
                }
            }

            $products = [];

            foreach (OrderData::list($data['appliesToProducts']) as $productId) {
                $productId = OrderData::text($productId, 255);

                if (preg_match('/\Aprod_[A-Za-z0-9_]+\z/D', $productId) !== 1 || in_array($productId, $products, true)) {
                    throw new OrderDataException();
                }

                $products[] = $productId;
            }

            $data = [
                'discountId' => self::reference($data['discountId'], 'di_'),
                'couponId' => self::reference($data['couponId'], null),
                'promotionCodeId' => self::reference($data['promotionCodeId'], 'promo_'),
                'couponName' => OrderData::nullableSingleLine($data['couponName'], 255),
                'promotionCode' => OrderData::nullableSingleLine($data['promotionCode'], 255),
                'amount' => (string) $amount->getAmount(),
                'currency' => $currency,
                'providerAmount' => $providerAmount,
                'percentOff' => $percentOff,
                'appliesToProducts' => $products,
                'firstTimeTransaction' => $data['firstTimeTransaction'] === null
                    ? null
                    : OrderData::boolean($data['firstTimeTransaction']),
                'minimumAmount' => $minimumAmount,
                'minimumAmountCurrency' => $minimumAmountCurrency,
                'providerMinimumAmount' => $providerMinimumAmount,
            ];

            if ($data['discountId'] === null && $data['couponId'] === null && $data['promotionCodeId'] === null) {
                throw new OrderDataException();
            }

            return new self($data);
        } catch (Throwable) {
            throw new OrderDataException();
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    private static function reference(mixed $value, ?string $prefix): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = OrderData::text($value, 255);

        if ($prefix !== null && preg_match('/\A' . $prefix . '[A-Za-z0-9_]+\z/D', $value) !== 1) {
            throw new OrderDataException();
        }

        return $value;
    }
}
