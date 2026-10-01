<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Prototype\PaymentMethodDx;

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;

/** Candidate frozen hook value; not registered or supported by the production plugin. */
final readonly class Payment
{
    public function __construct(
        private PaymentStatus $status,
        private Money $amount,
        private ?Money $amountReceived = null,
        private ?string $methodType = null,
        private ?string $stripePaymentIntentId = null,
        private ?string $stripeChargeId = null,
        private ?string $paymentIntentStatus = null,
        private ?string $nextActionType = null,
        private ?string $failureCode = null,
        private ?PaymentInstructions $instructions = null,
    ) {}

    public function status(): PaymentStatus
    {
        return $this->status;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function amountReceived(): ?Money
    {
        return $this->amountReceived;
    }

    public function methodType(): ?string
    {
        return $this->methodType;
    }

    public function stripePaymentIntentId(): ?string
    {
        return $this->stripePaymentIntentId;
    }

    public function stripeChargeId(): ?string
    {
        return $this->stripeChargeId;
    }

    public function paymentIntentStatus(): ?string
    {
        return $this->paymentIntentStatus;
    }

    public function nextActionType(): ?string
    {
        return $this->nextActionType;
    }

    public function failureCode(): ?string
    {
        return $this->failureCode;
    }

    public function instructions(): ?PaymentInstructions
    {
        return $this->instructions;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'currency' => $this->amount->getCurrency()->getCurrencyCode(),
            'amount' => (string) $this->amount->getAmount(),
            'amountReceived' => $this->amountReceived === null ? null : (string) $this->amountReceived->getAmount(),
            'methodType' => $this->methodType,
            'stripePaymentIntentId' => $this->stripePaymentIntentId,
            'stripeChargeId' => $this->stripeChargeId,
            'paymentIntentStatus' => $this->paymentIntentStatus,
            'nextActionType' => $this->nextActionType,
            'failureCode' => $this->failureCode,
            'instructions' => $this->instructions?->toArray(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $keys = ['status', 'currency', 'amount', 'amountReceived', 'methodType', 'stripePaymentIntentId', 'stripeChargeId', 'paymentIntentStatus', 'nextActionType', 'failureCode', 'instructions'];
        OrderData::validateAllowedKeys($data, $keys);
        OrderData::validateRequiredKeys($data, $keys);
        $registry = new StripeCurrencyRegistry();
        $currency = OrderData::text($data['currency']);

        return new self(
            status: PaymentStatus::from(OrderData::text($data['status'])),
            amount: $registry->toMoney($registry->fromDecimal(OrderData::text($data['amount']), $currency)),
            amountReceived: $data['amountReceived'] === null
                ? null
                : $registry->toMoney($registry->fromDecimal(OrderData::text($data['amountReceived']), $currency)),
            methodType: OrderData::nullableString($data['methodType']),
            stripePaymentIntentId: OrderData::nullableString($data['stripePaymentIntentId']),
            stripeChargeId: OrderData::nullableString($data['stripeChargeId']),
            paymentIntentStatus: OrderData::nullableString($data['paymentIntentStatus']),
            nextActionType: OrderData::nullableString($data['nextActionType']),
            failureCode: OrderData::nullableString($data['failureCode']),
            instructions: $data['instructions'] === null ? null : PaymentInstructions::fromArray(OrderData::map($data['instructions'])),
        );
    }
}
