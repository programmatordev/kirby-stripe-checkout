<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartMutator;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartStoreInterface;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use Throwable;

/** Clears an unchanged Cart after its same-browser Checkout completes without a browser return. */
final readonly class CompletedCheckoutCartClearer
{
    public function __construct(
        private CartStoreInterface $cartStore,
        private CartMutator $cartMutator,
        private BrowserAttemptStore $attemptStore,
        private BrowserAttemptContextResolver $attemptContextResolver,
        private OrderPageStore $orderPageStore,
    ) {}

    public function clearIfCheckoutCompleted(DateTimeImmutable $checkedAt): CartSnapshot
    {
        $cart = $this->cartStore->read();

        if ($cart->entries() === []) {
            return $cart;
        }

        try {
            $pageUuids = $this->attemptStore->acceptedOrderPageUuidsForCart($cart, $checkedAt);
        } catch (Throwable) {
            // Deferred cleanup is optional and must not prevent ordinary Cart access.
            return $cart;
        }

        foreach ($pageUuids as $pageUuid) {
            try {
                $page = $this->orderPageStore->order($pageUuid);

                if ($page === null) {
                    continue;
                }

                $orderData = $this->orderPageStore->data($page);

                // Reconciliation has already made the local Order authoritative; ordinary Cart access never queries Stripe.
                if (CheckoutStatus::from(OrderData::string($orderData['checkoutStatus'])) !== CheckoutStatus::Complete) {
                    continue;
                }

                $checkoutAttempt = OrderData::map($orderData['checkoutAttempt']);
                $context = $this->attemptContextResolver->forOrder(
                    pageUuid: $pageUuid,
                    orderData: $orderData,
                    checkoutAttempt: $checkoutAttempt,
                    uiMode: UiMode::from(OrderData::string($checkoutAttempt['uiMode'])),
                    checkedAt: $checkedAt,
                );

                if ($context === null) {
                    continue;
                }

                return $this->cartMutator->clearIfMatches($cart->id(), $cart->revision());
            } catch (Throwable) {
                // Ignore one unavailable or invalid Order; another accepted attempt may still match.
            }
        }

        return $cart;
    }
}
