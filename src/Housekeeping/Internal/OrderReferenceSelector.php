<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Housekeeping\Internal;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;

/** @internal Selects native Order identities without validating the full inventory first. */
final readonly class OrderReferenceSelector
{
    private const INVALID_SELECTION_MESSAGE = 'Invalid Order reference selection.';

    public function __construct(private OrderPageStore $orders) {}

    public function select(?string $afterPageUuid, int $limit): OrderReferenceBatch
    {
        if ($afterPageUuid !== null) {
            OrderData::uuid($afterPageUuid);
        }

        if ($limit < 1) {
            throw new InvalidArgumentException(self::INVALID_SELECTION_MESSAGE);
        }

        $pageUuids = [];

        foreach ($this->orders->container()?->childrenAndDrafts() ?? [] as $page) {
            $pageUuids[] = OrderData::pageUuid($page->slug());
        }

        sort($pageUuids, SORT_STRING);
        $selectedPageUuids = [];
        $hasMore = false;
        $lastPageUuid = null;

        foreach ($pageUuids as $pageUuid) {
            if ($afterPageUuid !== null && strcmp($pageUuid, $afterPageUuid) <= 0) {
                continue;
            }

            if (count($selectedPageUuids) === $limit) {
                $hasMore = true;
                break;
            }

            $selectedPageUuids[] = $pageUuid;
            $lastPageUuid = $pageUuid;
        }

        return new OrderReferenceBatch(
            pageUuids: $selectedPageUuids,
            continueAfterPageUuid: $hasMore ? $lastPageUuid : null,
        );
    }
}
