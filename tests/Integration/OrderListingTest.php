<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use DateTimeImmutable;
use InvalidArgumentException;
use Kirby\Exception\PermissionException;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPage;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleErrorCode;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\DisputeStatus;
use ProgrammatorDev\StripeCheckout\Order\Internal\DisputeSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\PaymentSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\RefundSnapshot;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Order\Payment;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Order\RefundStatus;
use ProgrammatorDev\StripeCheckout\Panel\OrderAttentionFilter;
use ProgrammatorDev\StripeCheckout\Panel\OrderListing;
use ProgrammatorDev\StripeCheckout\Panel\OrderListQuery;
use ProgrammatorDev\StripeCheckout\Panel\OrdersSection;
use ProgrammatorDev\StripeCheckout\Test\Support\CheckoutAttemptFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\OrderFixture;

final class OrderListingTest extends KirbyTestCase
{
    private int $sequence = 0;

    public function testDefaultAndNativePartialSearchUseValidatedCanonicalFacts(): void
    {
        $older = $this->createOrder(new DateTimeImmutable('2026-10-01T10:00:00Z'));
        $completed = $this->complete(
            $this->createOrder(new DateTimeImmutable('2026-10-02T10:00:00Z')),
            PaymentStatus::Paid,
            [
                'stripeCheckoutSessionId' => 'cs_listing',
                'stripeCustomerId' => 'cus_listing',
                'customer' => [
                    'email' => 'Buyer@Example.test',
                    'individualName' => 'Ana Silva',
                    'businessName' => null,
                    'phone' => null,
                    'taxIds' => [],
                ],
            ],
        );
        $listing = new OrderListing($this->kirby);

        $default = $listing->query(new OrderListQuery());
        $this->assertCount(1, $default->summaries());
        $summary = $default->summaries()[0];
        $this->assertSame($completed->uuid()->toString(), $summary->pageUuid());
        $this->assertSame('Ana Silva', $summary->customerName());
        $this->assertSame('Buyer@Example.test', $summary->customerEmail());
        $this->assertSame(PaymentStatus::Paid, $summary->paymentStatus());
        $this->assertSame('32.00', (string) $summary->total()?->getAmount());
        $this->assertFalse($summary->refundHasActive());
        $this->assertFalse($summary->refundRequiresAction());
        $this->assertFalse($summary->refundHasFailed());
        $this->assertFalse($summary->disputeRequiresResponse());
        $this->assertFalse($summary->disputeHasLost());

        $all = $listing->query(new OrderListQuery(
            checkoutStatuses: [],
            paymentStatuses: [],
        ));
        $this->assertSame(
            [$completed->uuid()->toString(), $older->uuid()->toString()],
            array_map(static fn($item): string => $item->pageUuid(), $all->summaries()),
        );
        $this->assertSame(
            [$older->uuid()->toString()],
            array_map(
                static fn($item): string => $item->pageUuid(),
                $listing->query(new OrderListQuery(
                    checkoutStatuses: [CheckoutStatus::Creating],
                    paymentStatuses: [],
                ))->summaries(),
            ),
        );

        $searches = [
            $summary->orderNumber(),
            substr($summary->orderNumber(), 4, 8),
            substr($completed->uuid()->toString(), 7, 8),
            'cs_list',
            'CUS_LIST',
            'buyer@EXAMPLE',
            'ana sil',
        ];

        foreach ($searches as $search) {
            $result = $listing->query(new OrderListQuery(search: $search));
            $this->assertSame([$completed->uuid()->toString()], array_map(static fn($item): string => $item->pageUuid(), $result->summaries()));
        }

        $this->assertSame([], $listing->query(new OrderListQuery(search: 'x'))->summaries());
        $this->assertSame([], $listing->query(new OrderListQuery(search: 'missing'))->summaries());
        $broken = $this->createOrder(new DateTimeImmutable('2026-10-03T10:00:00Z'));
        $broken->version('latest')->update(['subtotal' => 'invalid'], 'default');
        $this->assertCount(2, $listing->query(new OrderListQuery(
            checkoutStatuses: [],
            paymentStatuses: [],
        ))->summaries());
    }

    public function testFixedCommerceAndAttentionFiltersRemainIndependent(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(hooks: [
            'programmatordev.stripe-checkout.order.created' => function (OrderPage $order): void {
                if ($order->slug() === 'listing-order-01') {
                    throw new \RuntimeException('Expected listener failure.');
                }
            },
        ]);
        $this->kirby = $this->environment->app();

        $partial = $this->completeFinancial(
            $this->createOrder(new DateTimeImmutable('2026-10-01T10:00:00Z')),
            refundAmount: '16.00',
            dispute: true,
        );
        $full = $this->completeFinancial(
            $this->createOrder(new DateTimeImmutable('2026-10-02T10:00:00Z')),
            refundAmount: '32.00',
            dispute: false,
        );
        $pending = $this->complete($this->createOrder(new DateTimeImmutable('2026-10-03T10:00:00Z')), PaymentStatus::Pending);
        $failed = $this->complete($this->createOrder(new DateTimeImmutable('2026-10-04T10:00:00Z')), PaymentStatus::Failed);
        $free = $this->complete($this->createOrder(new DateTimeImmutable('2026-10-05T10:00:00Z')), PaymentStatus::NoPaymentRequired);
        $this->addFailedEvent($partial);
        $listing = new OrderListing($this->kirby);

        $cases = [
            [new OrderListQuery(paymentStatuses: [PaymentStatus::Pending]), $pending],
            [new OrderListQuery(paymentStatuses: [PaymentStatus::Failed]), $failed],
            [new OrderListQuery(paymentStatuses: [PaymentStatus::NoPaymentRequired]), $free],
            [new OrderListQuery(refundStatuses: [RefundStatus::Partial]), $partial],
            [new OrderListQuery(refundStatuses: [RefundStatus::Full]), $full],
            [new OrderListQuery(disputeStatuses: [DisputeStatus::NeedsResponse]), $partial],
            [new OrderListQuery(attentionFilters: [OrderAttentionFilter::FailedEvents]), $partial],
            [new OrderListQuery(attentionFilters: [OrderAttentionFilter::FailedHooks]), $partial],
        ];

        foreach ($cases as [$query, $expected]) {
            $result = $listing->query($query);
            $this->assertSame([$expected->uuid()->toString()], array_map(static fn($item): string => $item->pageUuid(), $result->summaries()));
        }

        $this->assertSame(
            [$full->uuid()->toString(), $partial->uuid()->toString()],
            array_map(
                static fn($item): string => $item->pageUuid(),
                $listing->query(new OrderListQuery(
                    paymentStatuses: [PaymentStatus::Paid],
                ))->summaries(),
            ),
        );
        $this->assertSame(
            [$free->uuid()->toString(), $full->uuid()->toString(), $partial->uuid()->toString()],
            array_map(
                static fn($item): string => $item->pageUuid(),
                $listing->query(new OrderListQuery(paymentStatuses: [
                    PaymentStatus::Paid,
                    PaymentStatus::NoPaymentRequired,
                ]))->summaries(),
            ),
        );

        foreach (['pi_listing-order-01', 'pm_listing-order-01', 're_listing-order-01', 'du_listing-order-01', 'evt_listing'] as $search) {
            $result = $listing->query(new OrderListQuery(search: $search));
            $this->assertSame([$partial->uuid()->toString()], array_map(static fn($item): string => $item->pageUuid(), $result->summaries()));
        }
    }

    public function testOrdersSectionPresentsNativeOrderRowsWithoutProviderReads(): void
    {
        $order = $this->complete(
            $this->createOrder(new DateTimeImmutable('2026-10-02T10:00:00Z')),
            PaymentStatus::Paid,
            ['customer' => [
                'email' => 'buyer@example.test',
                'individualName' => 'Ana Silva',
                'businessName' => null,
                'phone' => null,
                'taxIds' => [],
            ]],
        );
        $response = OrdersSection::response($this->kirby);

        $this->assertNull($response['error']);
        $this->assertSame(1, $response['pagination']['total']);
        $this->assertCount(1, $response['data']);
        $row = $response['data'][0];
        $this->assertSame($order->uuid()->toString(), $row['uuid']);
        $this->assertSame([
            'href' => $order->panel()->url(),
            'text' => $order->title()->value(),
        ], $row['title']);
        $this->assertSame('Ana Silva · buyer@example.test', $row['customer']);
        $this->assertSame([
            'text' => 'Paid',
            'theme' => 'positive',
        ], $row['paymentStatus']);
        $this->assertArrayNotHasKey('attention', $row);

        $options = $response['options'];
        $columns = $options['columns'] ?? null;
        $this->assertIsArray($columns);
        $paymentStatusColumn = $columns['paymentStatus'] ?? null;
        $this->assertIsArray($paymentStatusColumn);
        $this->assertSame(
            'stripe-checkout-payment-status',
            $paymentStatusColumn['type'] ?? null,
        );
        $titleColumn = $columns['title'] ?? null;
        $this->assertIsArray($titleColumn);
        $this->assertArrayNotHasKey('width', $titleColumn);
        $this->assertArrayNotHasKey('attention', $columns);
    }

    public function testOrdersSectionAppliesMultiplePaymentStatuses(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(request: [
            'method' => 'GET',
            'query' => ['payment' => 'paid,no_payment_required'],
            'url' => 'https://kirby-stripe-checkout.test/panel/stripe-checkout?tab=orders&payment=paid,no_payment_required',
        ]);
        $this->kirby = $this->environment->app();
        $paid = $this->complete(
            $this->createOrder(new DateTimeImmutable('2026-10-01T10:00:00Z')),
            PaymentStatus::Paid,
        );
        $free = $this->complete(
            $this->createOrder(new DateTimeImmutable('2026-10-02T10:00:00Z')),
            PaymentStatus::NoPaymentRequired,
        );
        $this->complete(
            $this->createOrder(new DateTimeImmutable('2026-10-03T10:00:00Z')),
            PaymentStatus::Pending,
        );

        $response = OrdersSection::response($this->kirby);

        $this->assertSame(['paid', 'no_payment_required'], $response['filters']['payment']);
        $this->assertSame(
            [$free->uuid()->toString(), $paid->uuid()->toString()],
            array_column($response['data'], 'uuid'),
        );
    }

    public function testNativePaginationUsesTwentyFiveNewestOrdersPerPage(): void
    {
        $oldest = null;

        for ($day = 1; $day <= 26; $day++) {
            $order = $this->createOrder(new DateTimeImmutable(sprintf('2026-10-%02dT10:00:00Z', $day)));
            $oldest ??= $order;
        }

        $listing = new OrderListing($this->kirby);
        $first = $listing->query(new OrderListQuery(
            checkoutStatuses: [],
            paymentStatuses: [],
        ));
        $second = $listing->query(new OrderListQuery(
            page: 2,
            checkoutStatuses: [],
            paymentStatuses: [],
        ));

        $this->assertCount(OrderListing::LIMIT, $first->summaries());
        $this->assertCount(1, $second->summaries());
        $this->assertSame(26, $first->pagination()->total());
        $this->assertSame(2, $first->pagination()->pages());
        $this->assertSame(1, $first->pagination()->page());
        $this->assertSame(2, $second->pagination()->page());
        $this->assertSame($oldest->uuid()->toString(), $second->summaries()[0]->pageUuid());
    }

    public function testOrdersSectionReturnsToTheFirstPageWhenASharedPageIsStale(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(request: [
            'method' => 'GET',
            'query' => ['page' => '2'],
            'url' => 'https://kirby-stripe-checkout.test/panel/stripe-checkout?tab=orders&page=2',
        ]);
        $this->kirby = $this->environment->app();
        $order = $this->complete(
            $this->createOrder(new DateTimeImmutable('2026-10-02T10:00:00Z')),
            PaymentStatus::Paid,
        );
        $response = OrdersSection::response($this->kirby);

        $this->assertSame(1, $response['pagination']['page']);
        $this->assertSame($order->uuid()->toString(), $response['data'][0]['uuid']);
    }

    public function testQueryRejectsUnsupportedOrUnboundedInputs(): void
    {
        foreach ([
            static fn(): OrderListQuery => new OrderListQuery(page: 0),
            static fn(): OrderListQuery => new OrderListQuery(search: str_repeat('a', 2049)),
        ] as $createQuery) {
            try {
                $createQuery();
                $this->fail('Expected an invalid Order list query.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testListingRequiresOrdersReadPermission(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            roles: [[
                'name' => 'operator',
                'permissions' => ['programmatordev.stripe-checkout' => [
                    'orders.read' => false,
                ]],
            ]],
            users: [[
                'id' => 'operator',
                'email' => 'operator@example.test',
                'role' => 'operator',
            ]],
            impersonate: 'operator',
        );
        $this->kirby = $this->environment->app();

        $this->expectException(PermissionException::class);
        (new OrderListing($this->kirby))->query(new OrderListQuery());
    }

    private function createOrder(DateTimeImmutable $createdAt): OrderPage
    {
        $this->sequence++;
        $uuid = sprintf('listing-order-%02d', $this->sequence);
        $context = new OrderCreationContext(
            uuid: $uuid,
            orderNumber: 'ORDER-' . $this->sequence,
            checkoutSource: CheckoutSource::Direct,
            cartRevision: null,
            userUuid: null,
            languageCode: 'en',
            uiMode: UiMode::Hosted,
            currency: 'EUR',
            lineItems: [OrderFixture::lineItem()],
        );

        return (new OrderPageStore($this->kirby))->create(
            context: $context,
            checkoutAttempt: CheckoutAttemptFactory::create($context, $createdAt),
            createdAt: $createdAt,
        );
    }

    /** @param array<string, mixed> $facts */
    private function complete(OrderPage $orderPage, PaymentStatus $paymentStatus, array $facts = []): OrderPage
    {
        $stripeCheckoutSessionId = $facts['stripeCheckoutSessionId'] ?? 'cs_' . $orderPage->slug();

        return (new OrderPageStore($this->kirby))->update($orderPage->uuid()->toString(), static function (array $data) use ($paymentStatus, $facts, $stripeCheckoutSessionId): array {
            $completed = [
                ...$data,
                'checkoutStatus' => CheckoutStatus::Complete->value,
                'paymentStatus' => $paymentStatus->value,
                'stripeCheckoutSessionId' => $stripeCheckoutSessionId,
                'stripeShippingRateIds' => [],
                'checkoutCompletedAt' => $data['createdAt'],
                'discountTotal' => '0',
                'customFields' => [],
                'discounts' => [],
                'shippingTotal' => '0',
                'taxTotal' => '0',
                'total' => $paymentStatus === PaymentStatus::NoPaymentRequired ? '0' : '32.00',
                ...$facts,
            ];

            if (in_array($paymentStatus, [PaymentStatus::Paid, PaymentStatus::NoPaymentRequired], true)) {
                $completed['paidAt'] = $data['createdAt'];
            }

            if ($paymentStatus === PaymentStatus::Failed) {
                $completed['paymentFailedAt'] = $data['createdAt'];
            }

            return $completed;
        });
    }

    private function completeFinancial(OrderPage $orderPage, string $refundAmount, bool $dispute): OrderPage
    {
        $store = new OrderPageStore($this->kirby);
        $createdAt = OrderData::date($store->data($orderPage)['createdAt']);
        $paymentIntentId = 'pi_' . $orderPage->slug();
        $chargeId = 'ch_' . $orderPage->slug();
        $payment = Payment::fromSnapshot(new PaymentSnapshot(
            amount: Money::of('32.00', 'EUR'),
            amountReceived: Money::of('32.00', 'EUR'),
            stripePaymentIntentId: $paymentIntentId,
            stripeChargeId: $chargeId,
            stripePaymentMethodId: 'pm_' . $orderPage->slug(),
            paymentIntentStatus: 'succeeded',
            chargeStatus: 'succeeded',
            methodType: 'card',
            createdAt: $createdAt->getTimestamp(),
            chargeCreatedAt: $createdAt->getTimestamp(),
            chargePaid: true,
            chargeCaptured: true,
            amountCaptured: Money::of('32.00', 'EUR'),
        ), PaymentStatus::Paid, 'EUR');
        $refund = RefundSnapshot::fromStripe([
            'id' => 're_' . $orderPage->slug(),
            'object' => 'refund',
            'amount' => (int) Money::of($refundAmount, 'EUR')->getMinorAmount()->toInt(),
            'currency' => 'EUR',
            'payment_intent' => $paymentIntentId,
            'charge' => $chargeId,
            'created' => $createdAt->getTimestamp(),
            'status' => 'succeeded',
        ], $paymentIntentId)->observed(null, $createdAt);
        $facts = [
            'stripePaymentIntentId' => $paymentIntentId,
            'stripeChargeId' => $chargeId,
            'payment' => $payment->toArray(),
            'refundStatus' => $refundAmount === '32.00' ? RefundStatus::Full->value : RefundStatus::Partial->value,
            'refundedTotal' => $refundAmount,
            'refundUpdatedAt' => OrderData::timestamp($createdAt),
            'refunds' => [$refund->toArray()],
        ];

        if ($dispute) {
            $disputeSnapshot = DisputeSnapshot::fromStripe([
                'id' => 'du_' . $orderPage->slug(),
                'object' => 'dispute',
                'amount' => 1600,
                'currency' => 'EUR',
                'payment_intent' => $paymentIntentId,
                'charge' => $chargeId,
                'created' => $createdAt->getTimestamp(),
                'status' => 'needs_response',
                'reason' => 'fraudulent',
                'evidence_details' => [
                    'due_by' => $createdAt->modify('+1 day')->getTimestamp(),
                    'has_evidence' => false,
                    'past_due' => false,
                    'submission_count' => 0,
                ],
                'balance_transactions' => [],
            ], $paymentIntentId)->observed(null, $createdAt);
            $facts = [
                ...$facts,
                'disputeStatus' => DisputeStatus::NeedsResponse->value,
                'disputeRequiresResponse' => true,
                'disputeUpdatedAt' => OrderData::timestamp($createdAt),
                'disputes' => [$disputeSnapshot->toArray()],
            ];
        }

        return $this->complete($orderPage, PaymentStatus::Paid, $facts);
    }

    private function addFailedEvent(OrderPage $orderPage): void
    {
        $store = new OrderPageStore($this->kirby);
        $store->update($orderPage->uuid()->toString(), static function (array $data): array {
            $data['events'] = [[
                'id' => 'evt_listing',
                'type' => 'checkout.session.completed',
                'createdAt' => OrderData::date($data['createdAt'])->getTimestamp(),
                'resourceId' => $data['stripeCheckoutSessionId'],
                'status' => 'failed',
                'attempts' => 1,
                'lastAttemptAt' => $data['createdAt'],
                'errorCode' => CheckoutErrorCode::SESSION_UNAVAILABLE,
            ]];

            return $data;
        });

        // The failing creation hook above produced this outcome through the real dispatcher.
        // Assert the fixture still carries that independently persisted attention signal.
        $deliveries = OrderData::list($store->data($store->order($orderPage->uuid()->toString()) ?? throw new \LogicException())['lifecycleDeliveries']);
        $delivery = OrderData::map($deliveries[0]);

        if ($delivery['status'] !== 'failed' || $delivery['errorCode'] !== LifecycleErrorCode::LISTENER_FAILED) {
            throw new \LogicException('Expected failed creation-hook fixture.');
        }
    }
}
