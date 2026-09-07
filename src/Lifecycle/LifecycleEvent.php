<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Lifecycle;

use DateTimeImmutable;
use Kirby\Uuid\Uri;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\DisputeStatus;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Order\RefundStatus;

/** Event-time facts for hooks/queues, distinct from a live mutable order Page. */
final readonly class LifecycleEvent
{
    private DateTimeImmutable $occurredAt;

    /** @var array<string, mixed> */
    private array $orderSnapshot;

    /** @param array<string, mixed> $orderSnapshot Verified default-language order content. */
    public function __construct(
        private string $deliveryId,
        private LifecycleEventType $type,
        private string $pageUuid,
        DateTimeImmutable $occurredAt,
        private int $revision,
        private ?string $languageCode,
        private CheckoutStatus $checkoutStatus,
        private PaymentStatus $paymentStatus,
        private RefundStatus $refundStatus,
        private DisputeStatus $disputeStatus,
        private ?string $triggerType,
        private ?string $triggerId,
        array $orderSnapshot,
    ) {
        OrderData::text($deliveryId, 255);
        OrderData::uuid($pageUuid);

        if ($revision < 1 || ($triggerType === null) !== ($triggerId === null)) {
            throw new OrderDataException();
        }

        $optionalFacts = [$languageCode, $triggerType, $triggerId];

        foreach ($optionalFacts as $value) {
            if ($value !== null) {
                OrderData::text($value, 255);
            }
        }

        // The writer owns canonical schema/privacy validation. Here we detach
        // references and verify the event header agrees with its event-time snapshot.
        $snapshot = OrderData::map($orderSnapshot);
        $snapshotReference = new Uri([
            'scheme' => 'page',
            'host' => OrderData::text($snapshot['uuid'] ?? null),
        ]);

        if ($snapshotReference->toString() !== $pageUuid) {
            throw new OrderDataException();
        }

        $expectedFacts = [
            'languageCode' => $languageCode,
            'checkoutStatus' => $checkoutStatus->value,
            'paymentStatus' => $paymentStatus->value,
            'refundStatus' => $refundStatus->value,
            'disputeStatus' => $disputeStatus->value,
        ];

        foreach ($expectedFacts as $key => $value) {
            if (($snapshot[$key] ?? null) !== $value) {
                throw new OrderDataException();
            }
        }

        $this->orderSnapshot = $snapshot;
        $this->occurredAt = OrderData::date(OrderData::timestamp($occurredAt));
    }

    public function deliveryId(): string
    {
        return $this->deliveryId;
    }

    public function type(): LifecycleEventType
    {
        return $this->type;
    }

    public function pageUuid(): string
    {
        return $this->pageUuid;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function revision(): int
    {
        return $this->revision;
    }

    public function languageCode(): ?string
    {
        return $this->languageCode;
    }

    public function checkoutStatus(): CheckoutStatus
    {
        return $this->checkoutStatus;
    }

    public function paymentStatus(): PaymentStatus
    {
        return $this->paymentStatus;
    }

    public function refundStatus(): RefundStatus
    {
        return $this->refundStatus;
    }

    public function disputeStatus(): DisputeStatus
    {
        return $this->disputeStatus;
    }

    public function triggerType(): ?string
    {
        return $this->triggerType;
    }

    public function triggerId(): ?string
    {
        return $this->triggerId;
    }

    /** @return array<string, mixed> */
    public function orderSnapshot(): array
    {
        return $this->orderSnapshot;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'deliveryId' => $this->deliveryId,
            'type' => $this->type->value,
            'pageUuid' => $this->pageUuid,
            'occurredAt' => OrderData::timestamp($this->occurredAt),
            'revision' => $this->revision,
            'languageCode' => $this->languageCode,
            'checkoutStatus' => $this->checkoutStatus->value,
            'paymentStatus' => $this->paymentStatus->value,
            'refundStatus' => $this->refundStatus->value,
            'disputeStatus' => $this->disputeStatus->value,
            'triggerType' => $this->triggerType,
            'triggerId' => $this->triggerId,
            'orderSnapshot' => $this->orderSnapshot,
        ];
    }
}
