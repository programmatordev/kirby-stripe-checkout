<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

use InvalidArgumentException;
use Kirby\Cms\App;
use Kirby\Exception\ErrorPageException;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Money\MoneyFormatter;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\DisputeStatus;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderQueryException;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Order\RefundStatus;
use ProgrammatorDev\StripeCheckout\Translation\Catalogue;

/**
 * Presents the local Order listing through Kirby's native custom-section endpoint.
 *
 * @phpstan-type Badge array{group: string, status: string, theme: string}
 * @phpstan-type Status array{group: string, kind: 'status', status: string, theme: string}
 * @phpstan-type OrderRow array{
 *     attention: array{badges: list<Badge>, kind: 'attention'},
 *     createdAt: array{kind: 'datetime', value: string},
 *     customer: array{email: ?string, kind: 'customer', name: ?string},
 *     id: string,
 *     order: array{kind: 'link', link: string, text: string},
 *     paymentStatus: Status,
 *     total: ?string
 * }
 * @internal
 */
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
     *     error: ?string,
     *     filters: array{attention: string, checkoutStatus: string, dispute: string, paymentStatus: string, refundStatus: string, search: string},
     *     orders: list<OrderRow>,
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
                'error' => self::errorMessage($error),
                'filters' => self::filters($query),
                'orders' => [],
                'pagination' => self::emptyPagination(),
            ];
        }

        $moneyFormatter = new MoneyFormatter($kirby);
        $orders = [];

        foreach ($result->summaries() as $summary) {
            $orders[] = self::order($summary, $moneyFormatter);
        }

        return [
            'error' => null,
            'filters' => self::filters($query),
            'orders' => $orders,
            'pagination' => self::pagination($result),
        ];
    }

    private static function query(App $kirby, ?int $page = null): OrderListQuery
    {
        $query = $kirby->request()->query();
        $checkoutStatus = $query->get('checkoutStatus');
        $paymentStatus = $query->get('paymentStatus');
        $refundStatus = $query->get('refundStatus');
        $dispute = $query->get('dispute');
        $attention = $query->get('attention');

        return new OrderListQuery(
            page: $page ?? self::page($query->get('page')),
            search: self::string($query->get('search')),
            checkoutStatus: $checkoutStatus === 'all'
                ? null
                : (is_string($checkoutStatus) ? CheckoutStatus::tryFrom($checkoutStatus) : null) ?? CheckoutStatus::Complete,
            paymentStatus: is_string($paymentStatus) ? PaymentStatus::tryFrom($paymentStatus) : null,
            refundStatus: is_string($refundStatus) ? RefundStatus::tryFrom($refundStatus) : null,
            disputeFilter: is_string($dispute) ? OrderDisputeFilter::tryFrom($dispute) : null,
            attentionFilter: is_string($attention) ? OrderAttentionFilter::tryFrom($attention) : null,
        );
    }

    /** @return array{attention: string, checkoutStatus: string, dispute: string, paymentStatus: string, refundStatus: string, search: string} */
    private static function filters(OrderListQuery $query): array
    {
        return [
            'attention' => $query->attentionFilter()->value ?? 'all',
            'checkoutStatus' => $query->checkoutStatus()->value ?? 'all',
            'dispute' => $query->disputeFilter()->value ?? 'all',
            'paymentStatus' => $query->paymentStatus()->value ?? 'all',
            'refundStatus' => $query->refundStatus()->value ?? 'all',
            'search' => $query->search() ?? '',
        ];
    }

    /** @return OrderRow */
    private static function order(OrderSummary $summary, MoneyFormatter $moneyFormatter): array
    {
        return [
            'id' => $summary->pageUuid(),
            'order' => [
                'kind' => 'link',
                'link' => $summary->orderPage()->panel()->url(),
                'text' => $summary->orderNumber(),
            ],
            'createdAt' => [
                'kind' => 'datetime',
                'value' => $summary->createdAt()->format(DATE_ATOM),
            ],
            'customer' => [
                'kind' => 'customer',
                'email' => $summary->customerEmail(),
                'name' => $summary->customerName(),
            ],
            'paymentStatus' => self::status(
                group: 'payment',
                status: $summary->paymentStatus()->value,
                theme: self::paymentTheme($summary->paymentStatus()),
            ),
            'total' => $summary->total() === null
                ? null
                : $moneyFormatter->format($summary->total()),
            'attention' => [
                'kind' => 'attention',
                'badges' => self::attention($summary),
            ],
        ];
    }

    /**
     * @return Status
     */
    private static function status(string $group, string $status, string $theme): array
    {
        return [
            'group' => $group,
            'kind' => 'status',
            'status' => $status,
            'theme' => $theme,
        ];
    }

    /** @return list<Badge> */
    private static function attention(OrderSummary $summary): array
    {
        $badges = [[
            'group' => 'checkout',
            'status' => $summary->checkoutStatus()->value,
            'theme' => self::checkoutTheme($summary->checkoutStatus()),
        ]];

        if ($summary->refundStatus() !== RefundStatus::None) {
            $badges[] = [
                'group' => 'refund',
                'status' => $summary->refundStatus()->value,
                'theme' => self::refundTheme($summary),
            ];
        }

        if ($summary->disputeStatus() !== DisputeStatus::None) {
            $badges[] = [
                'group' => 'dispute',
                'status' => $summary->disputeStatus()->value,
                'theme' => self::disputeTheme($summary),
            ];
        }

        if ($summary->failedEvents()) {
            $badges[] = [
                'group' => 'attention',
                'status' => OrderAttentionFilter::FailedEvents->value,
                'theme' => 'negative',
            ];
        }

        if ($summary->failedHooks()) {
            $badges[] = [
                'group' => 'attention',
                'status' => OrderAttentionFilter::FailedHooks->value,
                'theme' => 'negative',
            ];
        }

        return $badges;
    }

    private static function checkoutTheme(CheckoutStatus $status): string
    {
        return match ($status) {
            CheckoutStatus::Complete => 'positive',
            CheckoutStatus::Open => 'info',
            CheckoutStatus::Creating => 'notice',
            CheckoutStatus::CreationUncertain => 'warning',
            CheckoutStatus::CreationFailed => 'negative',
            CheckoutStatus::Expired => 'passive',
        };
    }

    private static function paymentTheme(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Paid, PaymentStatus::NoPaymentRequired => 'positive',
            PaymentStatus::Pending => 'notice',
            PaymentStatus::Failed => 'negative',
            PaymentStatus::Unpaid => 'passive',
        };
    }

    private static function refundTheme(OrderSummary $summary): string
    {
        if ($summary->refundHasFailed()) {
            return 'negative';
        }

        if ($summary->refundRequiresAction()) {
            return 'warning';
        }

        return $summary->refundHasActive() ? 'notice' : 'info';
    }

    private static function disputeTheme(OrderSummary $summary): string
    {
        if ($summary->disputeHasLost()) {
            return 'negative';
        }

        if ($summary->disputeRequiresResponse()) {
            return 'warning';
        }

        return match ($summary->disputeStatus()) {
            DisputeStatus::ResolvedFavorable => 'positive',
            DisputeStatus::UnderReview => 'notice',
            DisputeStatus::Mixed => 'warning',
            default => 'passive',
        };
    }

    private static function errorMessage(InvalidArgumentException|OrderQueryException $error): string
    {
        $key = $error instanceof OrderQueryException
            ? 'error.' . $error->errorCode()
            : 'orders.index.error';
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
