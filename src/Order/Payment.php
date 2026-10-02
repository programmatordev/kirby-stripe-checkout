<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order;

use Brick\Money\Money;
use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\PaymentSnapshot;

/** Frozen common payment facts and optional expiring action evidence; neither promises that an action remains usable. */
final readonly class Payment
{
    private const MONEY_FIELDS = ['amount', 'amountReceived', 'amountCaptured'];
    private const TEXT_FIELDS = ['stripePaymentIntentId', 'stripeChargeId', 'stripePaymentMethodId', 'paymentIntentStatus', 'chargeStatus', 'methodType', 'failureCode'];
    private const TIME_FIELDS = ['createdAt', 'chargeCreatedAt', 'nextActionObservedAt'];
    private const BOOLEAN_FIELDS = ['chargePaid', 'chargeCaptured'];

    /** @param array<string, mixed> $data */
    private function __construct(
        private array $data,
        private ?PaymentAction $nextAction,
        private ?DateTimeImmutable $nextActionExpiresAt,
    ) {}

    public static function fromSnapshot(PaymentSnapshot $snapshot, PaymentStatus $status, string $currency, ?PaymentAction $nextAction, ?int $nextActionObservedAt, ?DateTimeImmutable $nextActionExpiresAt): self
    {
        return self::fromArray([
            'status' => $status->value,
            'currency' => $currency,
            'amount' => $snapshot->amount() === null ? null : (string) $snapshot->amount()->getAmount(),
            'amountReceived' => $snapshot->amountReceived() === null ? null : (string) $snapshot->amountReceived()->getAmount(),
            'amountCaptured' => $snapshot->amountCaptured() === null ? null : (string) $snapshot->amountCaptured()->getAmount(),
            'stripePaymentIntentId' => $snapshot->stripePaymentIntentId(),
            'stripeChargeId' => $snapshot->stripeChargeId(),
            'stripePaymentMethodId' => $snapshot->stripePaymentMethodId(),
            'paymentIntentStatus' => $snapshot->paymentIntentStatus(),
            'chargeStatus' => $snapshot->chargeStatus(),
            'methodType' => $snapshot->methodType(),
            'failureCode' => $snapshot->failureCode(),
            'createdAt' => $snapshot->createdAt(),
            'chargeCreatedAt' => $snapshot->chargeCreatedAt(),
            'chargePaid' => $snapshot->chargePaid(),
            'chargeCaptured' => $snapshot->chargeCaptured(),
            'nextAction' => $nextAction?->toJson(),
            'nextActionObservedAt' => $nextActionObservedAt,
            'nextActionExpiresAt' => $nextActionExpiresAt === null ? null : OrderData::timestamp($nextActionExpiresAt),
        ]);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $keys = ['status', 'currency', 'nextAction', 'nextActionExpiresAt', ...self::MONEY_FIELDS, ...self::TEXT_FIELDS, ...self::TIME_FIELDS, ...self::BOOLEAN_FIELDS];
        OrderData::validateAllowedKeys($data, $keys);
        OrderData::validateRequiredKeys($data, $keys);
        PaymentStatus::from(OrderData::text($data['status']));
        $currency = OrderData::text($data['currency']);
        $registry = new StripeCurrencyRegistry();

        foreach (self::MONEY_FIELDS as $key) {
            if ($data[$key] !== null) {
                $money = $registry->toMoney($registry->fromDecimal(OrderData::text($data[$key]), $currency));
                $data[$key] = (string) $money->getAmount();
            }
        }

        foreach (self::TEXT_FIELDS as $key) {
            if ($data[$key] !== null) {
                OrderData::text($data[$key], 255);
            }
        }

        $references = [
            'stripePaymentIntentId' => 'pi_',
            'stripeChargeId' => 'ch_',
            'stripePaymentMethodId' => 'pm_',
        ];

        foreach ($references as $key => $prefix) {
            if ($data[$key] !== null && preg_match('/\A' . $prefix . '[A-Za-z0-9_]+\z/', OrderData::string($data[$key])) !== 1) {
                throw new OrderDataException();
            }
        }

        foreach (self::TIME_FIELDS as $key) {
            if ($data[$key] !== null && OrderData::integer($data[$key]) < 0) {
                throw new OrderDataException();
            }
        }

        foreach (self::BOOLEAN_FIELDS as $key) {
            if ($data[$key] !== null) {
                OrderData::boolean($data[$key]);
            }
        }

        if (
            ($data['nextAction'] === null) !== ($data['nextActionObservedAt'] === null)
            || ($data['nextAction'] === null) !== ($data['nextActionExpiresAt'] === null)
        ) {
            throw new OrderDataException();
        }

        $nextAction = null;
        $nextActionExpiresAt = null;

        if ($data['nextAction'] !== null) {
            // Restore historical evidence even past its deadline; dispatch and cleanup own expiry, not this frozen read value.
            $nextAction = PaymentAction::fromJson(OrderData::string($data['nextAction']));
            $data['nextAction'] = $nextAction->toJson();
            $nextActionExpiresAt = OrderData::date($data['nextActionExpiresAt']);
        }

        return new self(
            data: OrderData::map($data),
            nextAction: $nextAction,
            nextActionExpiresAt: $nextActionExpiresAt,
        );
    }

    public function status(): PaymentStatus
    {
        return PaymentStatus::from(OrderData::string($this->data['status']));
    }

    public function amount(): ?Money
    {
        return $this->money('amount');
    }

    public function amountReceived(): ?Money
    {
        return $this->money('amountReceived');
    }

    public function amountCaptured(): ?Money
    {
        return $this->money('amountCaptured');
    }

    public function stripePaymentIntentId(): ?string
    {
        return OrderData::nullableString($this->data['stripePaymentIntentId']);
    }

    public function stripeChargeId(): ?string
    {
        return OrderData::nullableString($this->data['stripeChargeId']);
    }

    public function stripePaymentMethodId(): ?string
    {
        return OrderData::nullableString($this->data['stripePaymentMethodId']);
    }

    public function paymentIntentStatus(): ?string
    {
        return OrderData::nullableString($this->data['paymentIntentStatus']);
    }

    public function chargeStatus(): ?string
    {
        return OrderData::nullableString($this->data['chargeStatus']);
    }

    public function methodType(): ?string
    {
        return OrderData::nullableString($this->data['methodType']);
    }

    public function failureCode(): ?string
    {
        return OrderData::nullableString($this->data['failureCode']);
    }

    public function createdAt(): ?int
    {
        return $this->data['createdAt'] === null ? null : OrderData::integer($this->data['createdAt']);
    }

    public function chargeCreatedAt(): ?int
    {
        return $this->data['chargeCreatedAt'] === null ? null : OrderData::integer($this->data['chargeCreatedAt']);
    }

    public function chargePaid(): ?bool
    {
        return $this->data['chargePaid'] === null ? null : OrderData::boolean($this->data['chargePaid']);
    }

    public function chargeCaptured(): ?bool
    {
        return $this->data['chargeCaptured'] === null ? null : OrderData::boolean($this->data['chargeCaptured']);
    }

    /** Historical provider action, not proof of payment or a general-purpose customer-instruction format. */
    public function nextAction(): ?PaymentAction
    {
        return $this->nextAction;
    }

    public function nextActionObservedAt(): ?int
    {
        return $this->data['nextActionObservedAt'] === null ? null : OrderData::integer($this->data['nextActionObservedAt']);
    }

    /** Local replay-retention deadline, not Stripe's expiry or a guarantee that the action is still usable. */
    public function nextActionExpiresAt(): ?DateTimeImmutable
    {
        return $this->nextActionExpiresAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    private function money(string $field): ?Money
    {
        return $this->data[$field] === null ? null : Money::of(OrderData::string($this->data[$field]), OrderData::string($this->data['currency']));
    }
}
