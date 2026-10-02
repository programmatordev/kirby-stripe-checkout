<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Checkout;

use ProgrammatorDev\StripeCheckout\Order\Internal\PaymentAction;

/**
 * @internal Provider read with every line-item page and the payment/rate expansions needed for reconciliation.
 * The gateway must finish these reads before returning; purchase correlation remains the retriever's responsibility.
 */
final readonly class CheckoutSessionReconciliationRecord
{
    /**
     * @param list<array<string, mixed>> $lineItems All provider line items, not the Session's expanded preview.
     * @param array<string, mixed>|null $paymentSource Selected expanded PaymentIntent/Charge facts;
     *                                               null means Stripe returned no PaymentIntent, not that it was left unread.
     */
    public function __construct(
        public CheckoutSessionRecord $session,
        public array $lineItems,
        public ?array $paymentSource,
        public ?PaymentAction $nextAction,
    ) {}
}
