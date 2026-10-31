<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

use Brick\Money\Money;
use Kirby\Cms\App;
use Kirby\Toolkit\Collection;
use Kirby\Uuid\Uuids;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPage;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Kirby\PersistenceErrorCode;
use ProgrammatorDev\StripeCheckout\Kirby\PluginPermissions;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\DisputeStatus;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderQueryException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSchema;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Order\RefundStatus;

/** @internal Reads the native Order inventory once and owns the fixed Panel list semantics. */
final readonly class OrderListing
{
    public const LIMIT = 25;

    private const STRIPE_REFERENCE_FIELDS = [
        ...OrderSchema::REFERENCES,
        'stripePaymentMethodId',
        'stripeRefundId',
        'stripeDisputeId',
        'stripeBalanceTransactionId',
        'stripePriceId',
        'stripeProductId',
        'discountId',
        'couponId',
        'promotionCodeId',
    ];

    private const SEARCH_REFERENCE_SNAPSHOTS = [
        'payment',
        'refunds',
        'disputes',
        'initiatingLineItems',
        'lineItems',
        'discounts',
    ];

    private OrderPageStore $orders;

    private StripeCurrencyRegistry $currencyRegistry;

    public function __construct(private App $kirby)
    {
        $this->orders = new OrderPageStore($kirby);
        $this->currencyRegistry = new StripeCurrencyRegistry();
    }

    public function query(OrderListQuery $query): OrderListResult
    {
        PluginPermissions::require($this->kirby, 'orders.read');
        $summaries = $this->summaries();
        $summaries = array_values(array_filter(
            $summaries,
            fn(OrderSummary $summary): bool => $this->matches($summary, $query),
        ));

        usort($summaries, static function (OrderSummary $left, OrderSummary $right): int {
            $createdAt = $right->createdAt()->getTimestamp() <=> $left->createdAt()->getTimestamp();

            // Persisted timestamps have second precision; UUIDs keep page boundaries stable when Orders share one second.
            return $createdAt !== 0
                ? $createdAt
                : strcmp($left->pageUuid(), $right->pageUuid());
        });

        $indexed = [];

        foreach ($summaries as $summary) {
            $indexed[$summary->pageUuid()] = $summary;
        }

        /** @var Collection<OrderSummary> $collection */
        $collection = new Collection($indexed);
        $page = $collection->paginate(self::LIMIT, $query->page());
        $pagination = $collection->pagination();

        if ($pagination === null) {
            throw new OrderQueryException();
        }

        /** @var list<OrderSummary> $pageSummaries */
        $pageSummaries = $page->values();

        return new OrderListResult($pageSummaries, $pagination);
    }

    /** @return list<OrderSummary> */
    private function summaries(): array
    {
        try {
            if (Uuids::enabled() === false) {
                throw new OrderStorageException(PersistenceErrorCode::UUID_UNAVAILABLE);
            }

            $summaries = [];

            foreach ($this->orders->container()?->childrenAndDrafts() ?? [] as $page) {
                try {
                    $data = $this->orders->data($page);
                } catch (OrderStorageException) {
                    // Match trusted Order queries: malformed children remain invisible but do not hide valid siblings.
                    error_log('Stripe Checkout: ' . PersistenceErrorCode::ORDER_INVALID);
                    continue;
                }

                /** @var OrderPage $page The store rejects every other Page model above. */
                $summaries[] = $this->summary($page, $data);
            }

            return $summaries;
        } catch (OrderStorageException) {
            throw new OrderQueryException();
        }
    }

    /** @param array<string, mixed> $data */
    private function summary(OrderPage $page, array $data): OrderSummary
    {
        $customerName = null;
        $customerEmail = null;

        if (isset($data['customer'])) {
            /** @var array{businessName: ?string, individualName: ?string, email: ?string} $customer */
            $customer = $data['customer'];
            $customerName = match (true) {
                $customer['businessName'] !== null && $customer['businessName'] !== '' => $customer['businessName'],
                $customer['individualName'] !== null && $customer['individualName'] !== '' => $customer['individualName'],
                default => null,
            };
            $customerEmail = $customer['email'] === '' ? null : $customer['email'];
        }

        /** @var string $uuid */
        $uuid = $data['uuid'];
        /** @var string $orderNumber */
        $orderNumber = $data['orderNumber'];
        /** @var string $createdAt */
        $createdAt = $data['createdAt'];
        /** @var string $checkoutStatus */
        $checkoutStatus = $data['checkoutStatus'];
        /** @var string $paymentStatus */
        $paymentStatus = $data['paymentStatus'];
        /** @var string $refundStatus */
        $refundStatus = $data['refundStatus'];
        /** @var string $disputeStatus */
        $disputeStatus = $data['disputeStatus'];
        /** @var bool $refundHasActive */
        $refundHasActive = $data['refundHasActive'];
        /** @var bool $refundRequiresAction */
        $refundRequiresAction = $data['refundRequiresAction'];
        /** @var bool $refundHasFailed */
        $refundHasFailed = $data['refundHasFailed'];
        /** @var bool $disputeRequiresResponse */
        $disputeRequiresResponse = $data['disputeRequiresResponse'];
        /** @var bool $disputeHasLost */
        $disputeHasLost = $data['disputeHasLost'];
        /** @var list<array<string, mixed>> $events */
        $events = $data['events'] ?? [];
        /** @var list<array<string, mixed>> $lifecycleDeliveries */
        $lifecycleDeliveries = $data['lifecycleDeliveries'] ?? [];
        $pageUuid = OrderData::pageUuid($uuid);

        return new OrderSummary(
            orderPage: $page,
            pageUuid: $pageUuid,
            orderNumber: $orderNumber,
            createdAt: OrderData::date($createdAt),
            customerName: $customerName,
            customerEmail: $customerEmail,
            checkoutStatus: CheckoutStatus::from($checkoutStatus),
            paymentStatus: PaymentStatus::from($paymentStatus),
            refundStatus: RefundStatus::from($refundStatus),
            disputeStatus: DisputeStatus::from($disputeStatus),
            total: $this->total($data),
            refundHasActive: $refundHasActive,
            refundRequiresAction: $refundRequiresAction,
            refundHasFailed: $refundHasFailed,
            disputeRequiresResponse: $disputeRequiresResponse,
            disputeHasLost: $disputeHasLost,
            failedEvents: $this->hasFailedEntry($events),
            failedHooks: $this->hasFailedEntry($lifecycleDeliveries),
            references: $this->references($pageUuid, $orderNumber, $data),
        );
    }

    /** @param array<string, mixed> $data */
    private function total(array $data): ?Money
    {
        if (isset($data['total']) === false) {
            return null;
        }

        return $this->currencyRegistry->toMoney($this->currencyRegistry->fromDecimal(
            OrderData::string($data['total']),
            OrderData::string($data['currency']),
        ));
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function references(string $pageUuid, string $orderNumber, array $data): array
    {
        $references = [
            $pageUuid => true,
            $orderNumber => true,
        ];

        foreach (OrderSchema::REFERENCES as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $references[$data[$field]] = true;
            }
        }

        /** @var list<string> $stripeShippingRateIds */
        $stripeShippingRateIds = $data['stripeShippingRateIds'] ?? [];

        foreach ($stripeShippingRateIds as $stripeShippingRateId) {
            $references[$stripeShippingRateId] = true;
        }

        // Search current canonical facts only; lifecycle payloads are historical, duplicated and eventually pruned.
        foreach (self::SEARCH_REFERENCE_SNAPSHOTS as $field) {
            $this->collectStripeReferences($data[$field] ?? null, $references);
        }

        /** @var list<array{id: string, resourceId: string}> $events */
        $events = $data['events'] ?? [];

        foreach ($events as $event) {
            $references[$event['id']] = true;
            $references[$event['resourceId']] = true;
            $this->collectStripeReferences($event, $references);
        }

        /** @var list<string> $result */
        $result = array_keys($references);

        return $result;
    }

    /** @param array<string, true> $references */
    private function collectStripeReferences(mixed $value, array &$references): void
    {
        if (is_array($value) === false) {
            return;
        }

        foreach ($value as $field => $item) {
            if (
                is_string($field)
                && in_array($field, self::STRIPE_REFERENCE_FIELDS, true)
                && is_string($item)
                && $item !== ''
            ) {
                $references[$item] = true;
            }

            $this->collectStripeReferences($item, $references);
        }
    }

    /** @param list<array<string, mixed>> $entries */
    private function hasFailedEntry(array $entries): bool
    {
        foreach ($entries as $entry) {
            if ($entry['status'] === 'failed') {
                return true;
            }
        }

        return false;
    }

    private function matches(OrderSummary $summary, OrderListQuery $query): bool
    {
        if ($query->search() !== null && $summary->matchesSearch($query->search()) === false) {
            return false;
        }

        if ($query->checkoutStatus() !== null && $summary->checkoutStatus() !== $query->checkoutStatus()) {
            return false;
        }

        if ($query->paymentStatus() !== null && $summary->paymentStatus() !== $query->paymentStatus()) {
            return false;
        }

        if ($query->refundStatus() !== null && $summary->refundStatus() !== $query->refundStatus()) {
            return false;
        }

        if ($query->disputeFilter() === OrderDisputeFilter::Any && $summary->disputeStatus() === DisputeStatus::None) {
            return false;
        }

        if ($query->disputeFilter() === OrderDisputeFilter::RequiresResponse && $summary->disputeRequiresResponse() === false) {
            return false;
        }

        return match ($query->attentionFilter()) {
            OrderAttentionFilter::FailedEvents => $summary->failedEvents(),
            OrderAttentionFilter::FailedHooks => $summary->failedHooks(),
            null => true,
        };
    }
}
