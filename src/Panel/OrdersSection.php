<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

use InvalidArgumentException;
use Kirby\Cms\App;
use Kirby\Panel\Ui\Item\PageItem;
use Kirby\Toolkit\I18n;
use Kirby\Toolkit\Pagination;
use ProgrammatorDev\StripeCheckout\Money\MoneyFormatter;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\DisputeStatus;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderQueryException;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Order\RefundStatus;
use ProgrammatorDev\StripeCheckout\Translation\Catalogue;

/** @internal Presents the local Order listing through Kirby's native custom-section endpoint. */
final class OrdersSection
{
    /** @return array<string, mixed> */
    public static function definition(): array
    {
        return [
            // Kirby rebinds section callbacks, so resolve the active App when the endpoint executes.
            'toArray' => fn(): array => OrdersSection::response(App::instance()),
        ];
    }

    /**
     * @return array{
     *     data: list<array<mixed>>,
     *     error: ?string,
     *     filters: array{
     *         attention: list<string>,
     *         checkoutStatus: list<string>,
     *         dispute: list<string>,
     *         payment: list<string>,
     *         refundStatus: list<string>
     *     },
     *     options: array<string, mixed>,
     *     pagination: array<string, int>
     * }
     */
    public static function response(App $kirby): array
    {
        try {
            $query = self::query($kirby);
            $listing = new OrderListing($kirby);
            $result = $listing->query($query);
        } catch (InvalidArgumentException|OrderQueryException $error) {
            $query = new OrderListQuery();

            return [
                'data' => [],
                'error' => self::errorMessage($error),
                'filters' => self::filters($query),
                'options' => self::options($query),
                'pagination' => self::pagination(new Pagination([
                    'limit' => OrderListing::LIMIT,
                    'page' => 1,
                    'total' => 0,
                ])),
            ];
        }

        $moneyFormatter = new MoneyFormatter($kirby);
        $data = [];

        foreach ($result->summaries() as $summary) {
            $data[] = self::order($summary, $moneyFormatter);
        }

        return [
            'data' => $data,
            'error' => null,
            'filters' => self::filters($query),
            'options' => self::options($query),
            'pagination' => self::pagination($result->pagination()),
        ];
    }

    private static function query(App $kirby): OrderListQuery
    {
        $requestQuery = $kirby->request()->query();
        $checkoutStatus = $requestQuery->get('checkoutStatus');
        $payment = $requestQuery->get('payment');
        $refundStatus = $requestQuery->get('refundStatus');
        $dispute = $requestQuery->get('dispute');
        $attention = $requestQuery->get('attention');

        return new OrderListQuery(
            page: self::page($requestQuery->get('page')),
            search: self::string($requestQuery->get('searchterm')),
            checkoutStatuses: self::parseFilters(
                $checkoutStatus,
                static fn(string $value): ?CheckoutStatus => CheckoutStatus::tryFrom($value),
            ),
            paymentStatuses: self::parseFilters(
                $payment,
                static fn(string $value): ?PaymentStatus => PaymentStatus::tryFrom($value),
            ),
            refundStatuses: self::parseFilters(
                $refundStatus,
                static fn(string $value): ?RefundStatus => RefundStatus::tryFrom($value),
            ) ?? [],
            disputeStatuses: self::parseFilters(
                $dispute,
                static fn(string $value): ?DisputeStatus => DisputeStatus::tryFrom($value),
            ) ?? [],
            attentionFilters: self::parseFilters(
                $attention,
                static fn(string $value): ?OrderAttentionFilter => OrderAttentionFilter::tryFrom($value),
            ) ?? [],
        );
    }

    /**
     * @return array{
     *     attention: list<string>,
     *     checkoutStatus: list<string>,
     *     dispute: list<string>,
     *     payment: list<string>,
     *     refundStatus: list<string>
     * }
     */
    private static function filters(OrderListQuery $query): array
    {
        return [
            'attention' => array_map(
                static fn(OrderAttentionFilter $filter): string => $filter->value,
                $query->attentionFilters(),
            ),
            'checkoutStatus' => array_map(
                static fn(CheckoutStatus $status): string => $status->value,
                $query->checkoutStatuses(),
            ),
            'dispute' => array_map(
                static fn(DisputeStatus $status): string => $status->value,
                $query->disputeStatuses(),
            ),
            'payment' => array_map(
                static fn(PaymentStatus $status): string => $status->value,
                $query->paymentStatuses(),
            ),
            'refundStatus' => array_map(
                static fn(RefundStatus $status): string => $status->value,
                $query->refundStatuses(),
            ),
        ];
    }

    /** @return array<mixed> */
    private static function order(OrderSummary $summary, MoneyFormatter $moneyFormatter): array
    {
        // Preserve the native Pages section's row links, permissions and actions.
        $item = (new PageItem(
            page: $summary->orderPage(),
            image: false,
            layout: 'table',
            text: $summary->orderNumber(),
        ))->props();

        return [
            ...$item,
            'title' => [
                'href' => $summary->orderPage()->panel()->url(),
                'text' => $summary->orderNumber(),
            ],
            'createdAt' => $summary->createdAt()->format(DATE_ATOM),
            'customer' => self::customer($summary),
            'paymentStatus' => self::paymentStatusTags($summary),
            'total' => $summary->total() === null
                ? null
                : $moneyFormatter->format($summary->total()),
        ];
    }

    /** @return array<string, mixed> */
    private static function options(OrderListQuery $query): array
    {
        return [
            'add' => false,
            'batch' => false,
            'columns' => [
                'title' => [
                    'label' => self::translation('orders.fields.orderNumber'),
                    'mobile' => true,
                    'type' => 'url',
                ],
                'createdAt' => [
                    'display' => 'DD MMM YYYY',
                    'label' => self::translation('orders.fields.createdAt'),
                    'time' => ['display' => 'HH:mm'],
                    'type' => 'date',
                    'width' => '1/6',
                ],
                'customer' => [
                    'label' => self::translation('orders.fields.customer'),
                    'type' => 'text',
                    'width' => '1/4',
                ],
                'paymentStatus' => [
                    'label' => self::translation('orders.fields.paymentStatus'),
                    'type' => 'stripe-checkout-payment-status',
                    'width' => '1/5',
                ],
                'total' => [
                    'align' => 'end',
                    'label' => self::translation('orders.fields.total'),
                    'width' => '1/8',
                ],
            ],
            'empty' => self::translation(self::isFiltered($query)
                ? 'orders.index.emptyFiltered'
                : 'orders.index.empty'),
            'headline' => self::translation('orders.title'),
            'help' => null,
            'layout' => 'table',
            'link' => null,
            'max' => null,
            'min' => null,
            'search' => true,
            'size' => 'auto',
            'sortable' => false,
        ];
    }

    private static function isFiltered(OrderListQuery $query): bool
    {
        return $query->search() !== null
            || $query->checkoutStatuses() !== [CheckoutStatus::Complete]
            || $query->paymentStatuses() !== [
                PaymentStatus::Paid,
                PaymentStatus::NoPaymentRequired,
            ]
            || $query->refundStatuses() !== []
            || $query->disputeStatuses() !== []
            || $query->attentionFilters() !== [];
    }

    private static function customer(OrderSummary $summary): string
    {
        $customer = array_filter([
            $summary->customerName(),
            $summary->customerEmail(),
        ], static fn(?string $value): bool => $value !== null && $value !== '');

        return $customer === []
            ? self::translation('orders.index.customerUnavailable')
            : implode(' · ', $customer);
    }

    /** @return list<array{key: string, text: string, theme: string}> */
    private static function paymentStatusTags(OrderSummary $summary): array
    {
        $paymentStatus = $summary->paymentStatus();
        // Payment remains the primary state; related financial states follow only when they apply.
        $tags = [[
            'key' => 'payment-' . $paymentStatus->value,
            'text' => self::translation('orders.index.status.payment.' . $paymentStatus->value),
            'theme' => match ($paymentStatus) {
                PaymentStatus::Paid,
                PaymentStatus::NoPaymentRequired => 'positive',
                PaymentStatus::Pending => 'notice',
                PaymentStatus::Failed => 'negative',
                PaymentStatus::Unpaid => 'passive',
            },
        ]];

        $refundStatus = $summary->refundStatus();

        if ($refundStatus !== RefundStatus::None) {
            $tags[] = [
                'key' => 'refund-' . $refundStatus->value,
                'text' => self::translation('orders.index.status.refund.' . $refundStatus->value),
                'theme' => match ($refundStatus) {
                    RefundStatus::Pending,
                    RefundStatus::Partial => 'notice',
                    RefundStatus::Full => 'passive',
                    RefundStatus::Failed => 'negative',
                },
            ];
        }

        $disputeStatus = $summary->disputeStatus();

        if ($disputeStatus !== DisputeStatus::None) {
            $tags[] = [
                'key' => 'dispute-' . $disputeStatus->value,
                'text' => self::translation('orders.index.status.dispute.' . $disputeStatus->value),
                'theme' => match ($disputeStatus) {
                    DisputeStatus::NeedsResponse,
                    DisputeStatus::ResolvedLost => 'negative',
                    DisputeStatus::UnderReview,
                    DisputeStatus::Mixed => 'notice',
                    DisputeStatus::ResolvedFavorable => 'positive',
                },
            ];
        }

        return $tags;
    }

    /**
     * @template T
     * @param callable(string): ?T $parse
     * @return list<T>|null
     */
    private static function parseFilters(mixed $value, callable $parse): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = $value === '' ? [] : explode(',', $value);
        }

        if (is_array($value) === false) {
            return null;
        }

        $filters = [];

        foreach (array_values($value) as $entry) {
            if (is_string($entry) === false) {
                return null;
            }

            $filter = $parse($entry);

            if ($filter === null) {
                return null;
            }

            $filters[$entry] = $filter;
        }

        return array_values($filters);
    }

    private static function errorMessage(InvalidArgumentException|OrderQueryException $error): string
    {
        $key = $error instanceof OrderQueryException
            ? 'error.' . $error->errorCode()
            : 'orders.index.error';

        return self::translation($key);
    }

    private static function translation(string $key): string
    {
        $translation = I18n::translate(Catalogue::PREFIX . $key);

        return is_string($translation) ? $translation : $key;
    }

    /** @return array{limit: int, offset: int, page: int, total: int} */
    private static function pagination(Pagination $pagination): array
    {
        return [
            'limit' => $pagination->limit(),
            'offset' => $pagination->offset(),
            'page' => $pagination->page(),
            'total' => $pagination->total(),
        ];
    }

    private static function page(mixed $value): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : 1;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
