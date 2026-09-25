<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Lifecycle;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEvent;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEventType;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\DisputeStatus;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Order\RefundStatus;

final class LifecycleEventTest extends TestCase
{
    #[DataProvider('invalidLifecycleFacts')]
    public function testLifecycleFactsMustMatchTheirSnapshot(string $kind): void
    {
        $snapshot = $this->snapshot();

        if ($kind === 'snapshot') {
            $snapshot['paymentStatus'] = 'paid';
        }

        $this->expectException(OrderDataException::class);
        new LifecycleEvent(
            deliveryId: 'delivery',
            type: LifecycleEventType::OrderCreated,
            pageUuid: 'page://Abc123def456GHI7',
            occurredAt: new DateTimeImmutable('2026-09-05T10:20:30Z'),
            revision: $kind === 'revision' ? 0 : 1,
            languageCode: $kind === 'language' ? 'pt' : 'en',
            checkoutStatus: CheckoutStatus::Creating,
            paymentStatus: PaymentStatus::Unpaid,
            refundStatus: RefundStatus::None,
            disputeStatus: DisputeStatus::None,
            triggerType: $kind === 'trigger' ? 'event.type' : null,
            triggerId: null,
            orderSnapshot: $snapshot,
        );
    }

    /** @return iterable<array{string}> */
    public static function invalidLifecycleFacts(): iterable
    {
        $kinds = ['snapshot', 'revision', 'language', 'trigger'];

        foreach ($kinds as $kind) {
            yield [$kind];
        }
    }

    public function testLifecyclePayloadIsImmutablePortableAndDoesNotInventAnEvent(): void
    {
        $data = $this->snapshot();
        $data['saleschannel'] = 'website';
        $event = new LifecycleEvent(
            deliveryId: 'delivery-1',
            type: LifecycleEventType::OrderCreated,
            pageUuid: 'page://Abc123def456GHI7',
            occurredAt: new DateTimeImmutable('2026-09-05T11:20:30.123+01:00'),
            revision: 1,
            languageCode: 'en',
            checkoutStatus: CheckoutStatus::Creating,
            paymentStatus: PaymentStatus::Unpaid,
            refundStatus: RefundStatus::None,
            disputeStatus: DisputeStatus::None,
            triggerType: null,
            triggerId: null,
            orderSnapshot: $data,
        );
        $data['saleschannel'] = 'changed';
        $this->assertSame('website', $event->orderSnapshot()['saleschannel']);
        $this->assertSame('delivery-1', $event->deliveryId());
        $this->assertSame(LifecycleEventType::OrderCreated, $event->type());
        $this->assertSame('page://Abc123def456GHI7', $event->pageUuid());
        $this->assertSame('2026-09-05T10:20:30Z', OrderData::timestamp($event->occurredAt()));
        $this->assertSame(1, $event->revision());
        $this->assertSame('en', $event->languageCode());
        $this->assertSame(CheckoutStatus::Creating, $event->checkoutStatus());
        $this->assertSame(PaymentStatus::Unpaid, $event->paymentStatus());
        $this->assertSame(RefundStatus::None, $event->refundStatus());
        $this->assertSame(DisputeStatus::None, $event->disputeStatus());
        $this->assertNull($event->triggerType());
        $this->assertNull($event->triggerId());
        $this->assertSame($event->toArray(), json_decode(json_encode($event->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame('order.created', $event->toArray()['type']);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        // LifecycleEvent checks identity/state agreement, not the storage schema.
        return [
            'uuid' => 'Abc123def456GHI7',
            'languageCode' => 'en',
            'checkoutStatus' => 'creating',
            'paymentStatus' => 'unpaid',
            'refundStatus' => 'none',
            'disputeStatus' => 'none',
        ];
    }
}
