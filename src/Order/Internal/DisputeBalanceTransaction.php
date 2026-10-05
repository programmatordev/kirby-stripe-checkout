<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use Brick\Money\Currency;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use Stripe\BalanceTransaction;
use Throwable;

/**
 * @internal Selected signed balance movements in their own currency and exact provider units.
 *
 * The identity supports duplicate detection and ordering; the remaining facts are serialized into the dispute snapshot
 * for display and material-change detection.
 */
final readonly class DisputeBalanceTransaction
{
    private const KEYS = ['stripeBalanceTransactionId', 'currency', 'amount', 'fee', 'net', 'createdAt'];

    /** @param array<string, mixed> $data */
    private function __construct(private array $data) {}

    /** @param array<string, mixed> $data */
    public static function fromStripe(array $data): self
    {
        if (($data['object'] ?? null) !== BalanceTransaction::OBJECT_NAME) {
            throw new OrderDataException();
        }

        return self::fromArray([
            'stripeBalanceTransactionId' => $data['id'] ?? null,
            'currency' => strtoupper(OrderData::string($data['currency'] ?? null)),
            'amount' => $data['amount'] ?? null,
            'fee' => $data['fee'] ?? null,
            'net' => $data['net'] ?? null,
            'createdAt' => $data['created'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        try {
            OrderData::validateAllowedKeys($data, self::KEYS);
            OrderData::validateRequiredKeys($data, self::KEYS);
            OrderData::nonEmptyString($data['stripeBalanceTransactionId']);
            $currency = OrderData::string($data['currency']);
            Currency::of($currency);

            if ($currency !== strtoupper($currency)) {
                throw new OrderDataException();
            }

            // Settlement currency/units can differ from presentment; preserve signed integers without FX or fee reconstruction.
            // https://docs.stripe.com/api/balance_transactions/object#balance_transaction_object-exchange_rate
            $amountFields = ['amount', 'fee', 'net'];

            foreach ($amountFields as $field) {
                OrderData::integer($data[$field]);
            }

            if (OrderData::integer($data['createdAt']) < 0) {
                throw new OrderDataException();
            }

            return new self(OrderData::map($data));
        } catch (Throwable) {
            throw new OrderDataException();
        }
    }

    public function stripeBalanceTransactionId(): string
    {
        return OrderData::string($this->data['stripeBalanceTransactionId']);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
