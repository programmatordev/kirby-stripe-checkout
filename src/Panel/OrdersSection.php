<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

use InvalidArgumentException;
use Kirby\Cms\App;
use Kirby\Exception\ErrorPageException;
use Kirby\Panel\Ui\Item\PageItem;
use Kirby\Toolkit\I18n;
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
     *     filters: array{attention: string, checkoutStatus: string, dispute: string, paymentStatus: string, refundStatus: string},
     *     options: array<string, mixed>,
     *     pagination: array<string, int>
     * }
     */
    public static function response(App $kirby): array
    {
        try {
            $query = self::query($kirby);
            $listing = new OrderListing($kirby);

            try {
                $result = $listing->query($query);
            } catch (ErrorPageException) {
                // A previously valid shared URL can outlive the page it referenced as Orders are removed.
                $query = self::query($kirby, 1);
                $result = $listing->query($query);
            }
        } catch (InvalidArgumentException|OrderQueryException $error) {
            $query = new OrderListQuery();

            return [
                'data' => [],
                'error' => self::errorMessage($error),
                'filters' => self::filters($query),
                'options' => self::options($query),
                'pagination' => self::emptyPagination(),
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
            'pagination' => self::pagination($result),
        ];
    }

    private static function query(App $kirby, ?int $page = null): OrderListQuery
    {
        $requestQuery = $kirby->request()->query();
        $checkoutStatus = $requestQuery->get('checkoutStatus');
        $paymentStatus = $requestQuery->get('paymentStatus');
        $refundStatus = $requestQuery->get('refundStatus');
        $dispute = $requestQuery->get('dispute');
        $attention = $requestQuery->get('attention');

        return new OrderListQuery(
            page: $page ?? self::page($requestQuery->get('page')),
            search: self::string($requestQuery->get('searchterm')),
            checkoutStatus: $checkoutStatus === 'all'
                ? null
                : (is_string($checkoutStatus) ? CheckoutStatus::tryFrom($checkoutStatus) : null) ?? CheckoutStatus::Complete,
            paymentStatus: is_string($paymentStatus) ? PaymentStatus::tryFrom($paymentStatus) : null,
            refundStatus: is_string($refundStatus) ? RefundStatus::tryFrom($refundStatus) : null,
            disputeFilter: is_string($dispute) ? OrderDisputeFilter::tryFrom($dispute) : null,
            attentionFilter: is_string($attention) ? OrderAttentionFilter::tryFrom($attention) : null,
        );
    }

    /** @return array{attention: string, checkoutStatus: string, dispute: string, paymentStatus: string, refundStatus: string} */
    private static function filters(OrderListQuery $query): array
    {
        return [
            'attention' => $query->attentionFilter()->value ?? 'all',
            'checkoutStatus' => $query->checkoutStatus()->value ?? 'all',
            'dispute' => $query->disputeFilter()->value ?? 'all',
            'paymentStatus' => $query->paymentStatus()->value ?? 'all',
            'refundStatus' => $query->refundStatus()->value ?? 'all',
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
            'paymentStatus' => self::translation('orders.index.status.payment.' . $summary->paymentStatus()->value),
            'total' => $summary->total() === null
                ? null
                : $moneyFormatter->format($summary->total()),
            'attention' => self::attention($summary),
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
                    'width' => '1/6',
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
                    'type' => 'tag',
                    'width' => '1/8',
                ],
                'total' => [
                    'align' => 'end',
                    'label' => self::translation('orders.fields.total'),
                    'width' => '1/8',
                ],
                'attention' => [
                    'label' => self::translation('orders.index.attention.label'),
                    'type' => 'tags',
                    'width' => '1/4',
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
            || $query->checkoutStatus() !== CheckoutStatus::Complete
            || $query->paymentStatus() !== null
            || $query->refundStatus() !== null
            || $query->disputeFilter() !== null
            || $query->attentionFilter() !== null;
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

    /** @return list<string> */
    private static function attention(OrderSummary $summary): array
    {
        $attention = [self::translation(
            'orders.index.status.checkout.' . $summary->checkoutStatus()->value,
        )];

        if ($summary->refundStatus() !== RefundStatus::None) {
            // Mixed collections can hide actionable entries.
            // Prefer their aggregate flags in text-only native tags.
            $refundStatus = match (true) {
                $summary->refundHasFailed() => RefundStatus::Failed,
                $summary->refundRequiresAction() => RefundStatus::Pending,
                default => $summary->refundStatus(),
            };
            $attention[] = self::translation('orders.index.status.refund.' . $refundStatus->value);
        }

        if ($summary->disputeStatus() !== DisputeStatus::None) {
            $disputeStatus = match (true) {
                $summary->disputeHasLost() => DisputeStatus::ResolvedLost,
                $summary->disputeRequiresResponse() => DisputeStatus::NeedsResponse,
                default => $summary->disputeStatus(),
            };
            $attention[] = self::translation('orders.index.status.dispute.' . $disputeStatus->value);
        }

        if ($summary->failedEvents()) {
            $attention[] = self::translation(
                'orders.index.status.attention.' . OrderAttentionFilter::FailedEvents->value,
            );
        }

        if ($summary->failedHooks()) {
            $attention[] = self::translation(
                'orders.index.status.attention.' . OrderAttentionFilter::FailedHooks->value,
            );
        }

        return $attention;
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

    /** @return array{end: int, firstPage: int, lastPage: int, limit: int, offset: int, page: int, pages: int, start: int, total: int} */
    private static function pagination(OrderListResult $result): array
    {
        $pagination = $result->pagination();

        return [
            'end' => $pagination->end(),
            'firstPage' => $pagination->firstPage(),
            'lastPage' => $pagination->lastPage(),
            'limit' => $pagination->limit(),
            'offset' => $pagination->offset(),
            'page' => $pagination->page(),
            'pages' => $pagination->pages(),
            'start' => $pagination->start(),
            'total' => $pagination->total(),
        ];
    }

    /** @return array{end: int, firstPage: int, lastPage: int, limit: int, offset: int, page: int, pages: int, start: int, total: int} */
    private static function emptyPagination(): array
    {
        return [
            'end' => 0,
            'firstPage' => 0,
            'lastPage' => 0,
            'limit' => OrderListing::LIMIT,
            'offset' => 0,
            'page' => 0,
            'pages' => 0,
            'start' => 0,
            'total' => 0,
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
