<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\CheckoutLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\CheckoutSessionAssociation;
use ProgrammatorDev\StripeCheckout\Order\Internal\CheckoutSessionSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\PaymentSnapshot;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Plugin\PluginMetadata;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionGatewayInterface;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionObservation;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionReconciliationRecord;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionRecord;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Exception\CheckoutSessionGatewayException;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Internal\CheckoutSessionSnapshotNormalizer;
use Stripe\Charge;
use Stripe\Checkout\Session;
use Stripe\LineItem;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Price;
use Throwable;

/**
 * @internal Reads and correlates a complete provider observation against frozen purchase evidence.
 * No current product/settings resolution, persistence, state reduction or observer dispatch occurs here.
 */
final class CheckoutSessionRetriever
{
    public function __construct(
        private readonly CheckoutSessionGatewayInterface $gateway,
        private readonly CheckoutSessionSnapshotNormalizer $snapshots = new CheckoutSessionSnapshotNormalizer(),
        private readonly CheckoutSessionFactory $sessionFactory = new CheckoutSessionFactory(),
    ) {}

    /**
     * $order and $request are the saved attempt's frozen purchase evidence,
     * not a fresh projection of the current storefront.
     */
    public function retrieve(
        string $sessionId,
        OrderCreationContext $order,
        SessionRequest $request,
        CredentialMode $credentialMode,
        ?CheckoutSessionAssociation $association = null,
    ): CheckoutSessionObservation {
        try {
            $reconciliationRecord = $this->gateway->retrieveForReconciliation($sessionId);
        } catch (CheckoutSessionGatewayException $error) {
            throw new CheckoutSessionException(
                errorCode: CheckoutErrorCode::forSessionFailure($error->failure()->type()),
                retryable: $error->failure()->isRetryable(),
                previous: $error,
            );
        }

        try {
            $parameters = $request->parameters();
            $sessionRecord = $reconciliationRecord->session;

            if ($sessionRecord->id !== $sessionId) {
                throw new OrderDataException();
            }

            $currentAssociation = $this->sessionFactory->association(
                record: $sessionRecord,
                order: $order,
                request: $request,
                liveMode: match ($credentialMode) {
                    CredentialMode::Test => false,
                    CredentialMode::Live => true,
                    CredentialMode::Unknown => null,
                },
            );

            // A read can precede local association, but an existing association's Session and generated Rate references must never be replaced.
            if ($association !== null && $association->equals($currentAssociation) === false) {
                throw new OrderDataException();
            }

            $lineItems = $this->lineItems($reconciliationRecord, $order, $parameters);
            $snapshot = $this->snapshots->normalizeForReconciliation($reconciliationRecord);
            $this->validateShipping($sessionRecord, $snapshot, $currentAssociation, $parameters);
            $subtotal = $this->amount($sessionRecord->amountSubtotal, $order->currency());
            $total = $this->amount($sessionRecord->amountTotal, $order->currency());
            $lineSubtotal = Money::zero($order->currency());
            $lineTotal = Money::zero($order->currency());

            foreach ($lineItems as $lineItem) {
                $lineSubtotal = $lineSubtotal->plus($lineItem->subtotal());
                $lineTotal = $lineTotal->plus($lineItem->total());
            }

            $shippingTotal = $snapshot->shippingTotal();

            if ($shippingTotal !== null) {
                $lineTotal = $lineTotal->plus(Money::of($shippingTotal, $order->currency()));
            }

            // Compare returned allocations, not a locally reconstructed VAT equation.
            if ($subtotal->isEqualTo($lineSubtotal) === false || $total->isEqualTo($lineTotal) === false) {
                throw new OrderDataException();
            }

            return new CheckoutSessionObservation(
                association: $currentAssociation,
                status: $sessionRecord->status ?? throw new OrderDataException(),
                paymentStatus: $sessionRecord->paymentStatus ?? throw new OrderDataException(),
                liveMode: $sessionRecord->liveMode ?? throw new OrderDataException(),
                createdAt: $sessionRecord->createdAt ?? throw new OrderDataException(),
                expiresAt: $sessionRecord->expiresAt ?? throw new OrderDataException(),
                requestId: $sessionRecord->requestId,
                stripeInvoiceId: $sessionRecord->invoiceId === null ? null : $this->id($sessionRecord->invoiceId, 'in_'),
                subtotal: $subtotal,
                total: $total,
                lineItems: $lineItems,
                payment: $this->payment($reconciliationRecord, $order, $parameters, $total),
                snapshot: $snapshot,
            );
        } catch (Throwable $error) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE, previous: $error);
        }
    }

    /** @param array<string, mixed> $parameters
     * @return list<CheckoutLineItemSnapshot>
     */
    private function lineItems(CheckoutSessionReconciliationRecord $record, OrderCreationContext $order, array $parameters): array
    {
        $lines = $record->lineItems;
        $expectedLines = OrderData::list($parameters['line_items'] ?? null);
        $initiatingLines = $order->lineItems();

        if (count($lines) !== count($expectedLines) || count($lines) !== count($initiatingLines)) {
            throw new OrderDataException();
        }

        // Metadata identifies the initiating position even when provider pages arrive in another order or several lines reference the same product.
        $expectedByIdentity = [];

        foreach ($expectedLines as $index => $expectedLine) {
            $expectedLine = $this->map($expectedLine);
            $metadata = $this->map($expectedLine['metadata'] ?? null);
            $identity = OrderData::text($metadata[PluginMetadata::LINE_KEY] ?? null);

            if (isset($expectedByIdentity[$identity])) {
                throw new OrderDataException();
            }

            $expectedByIdentity[$identity] = $index;
        }

        $result = [];
        $ids = [];

        foreach ($lines as $line) {
            $line = $this->map($line);
            $lineId = $this->id($line['id'] ?? null, 'li_');
            $metadata = $this->map($line['metadata'] ?? null);
            $identity = OrderData::text($metadata[PluginMetadata::LINE_KEY] ?? null);
            $index = $expectedByIdentity[$identity] ?? null;

            if ($index === null || isset($result[$index]) || isset($ids[$lineId]) || ($line['object'] ?? null) !== LineItem::OBJECT_NAME) {
                throw new OrderDataException();
            }

            $ids[$lineId] = true;
            $expected = $this->map($expectedLines[$index]);
            $this->metadata($metadata, $expected['metadata'] ?? null, $order->pageUuid());
            $price = $this->map($line['price'] ?? null);
            $priceId = $this->id($price['id'] ?? null, 'price_');
            $productId = $this->id($price['product'] ?? null, 'prod_');
            $initiatingLine = $initiatingLines[$index];
            $providerAmounts = $this->map($initiatingLine['providerAmounts']);

            $this->validateLinePrice($price);

            if (
                strtoupper(OrderData::text($line['currency'] ?? null)) !== $order->currency()
                || strtoupper(OrderData::text($price['currency'] ?? null)) !== $order->currency()
            ) {
                throw new OrderDataException();
            }

            if (($line['quantity'] ?? null) !== $expected['quantity']) {
                throw new OrderDataException();
            }

            // The initiating price and subtotal are fixed; discounts and taxes are retained from the returned allocations below.
            if (
                ($price['unit_amount'] ?? null) !== $providerAmounts['price']
                || ($line['amount_subtotal'] ?? null) !== $providerAmounts['subtotal']
            ) {
                throw new OrderDataException();
            }

            // Inline-price purchases have no initiating Price/Product IDs; their correlation uses line metadata and the fixed amounts above.
            if (isset($expected['price']) && $priceId !== $expected['price']) {
                throw new OrderDataException();
            }

            if ($initiatingLine['stripeProductId'] !== null && $productId !== $initiatingLine['stripeProductId']) {
                throw new OrderDataException();
            }

            $currency = $order->currency();
            $discount = $this->amount($line['amount_discount'] ?? null, $currency);
            $discounts = $this->snapshots->normalizeDiscounts(
                OrderData::list($line['discounts'] ?? []),
                OrderData::integer($line['amount_discount'] ?? null),
                $currency,
            );
            $result[$index] = new CheckoutLineItemSnapshot(
                stripeLineItemId: $lineId,
                initiatingIndex: $index,
                stripePriceId: $priceId,
                stripeProductId: $productId,
                quantity: OrderData::integer($line['quantity']),
                // Stripe descriptions are arbitrary display text, not identifiers;
                // preserve line breaks and whitespace while checking type/UTF-8.
                // https://docs.stripe.com/api/checkout/sessions/object#checkout_session_object-line_items-data-description
                description: OrderData::nullableString($line['description'] ?? null),
                price: $this->amount($price['unit_amount'], $currency),
                subtotal: $this->amount($line['amount_subtotal'], $currency),
                discount: $discount,
                tax: $this->amount($line['amount_tax'] ?? null, $currency),
                total: $this->amount($line['amount_total'] ?? null, $currency),
                checkoutDiscounts: $discounts,
            );
        }

        ksort($result);

        return array_values($result);
    }

    /** @param array<string, mixed> $price */
    private function validateLinePrice(array $price): void
    {
        // Price/Product active flags describe today's catalogue, not whether the fixed purchase was valid.
        // Never re-resolve or require active resources.
        if (($price['object'] ?? null) !== Price::OBJECT_NAME) {
            throw new OrderDataException();
        }

        if (
            ($price['type'] ?? null) !== Price::TYPE_ONE_TIME
            || ($price['billing_scheme'] ?? null) !== Price::BILLING_SCHEME_PER_UNIT
            || ($price['recurring'] ?? null) !== null
            || ($price['transform_quantity'] ?? null) !== null
        ) {
            throw new OrderDataException();
        }
    }

    /** @param array<string, mixed> $parameters */
    private function validateShipping(
        CheckoutSessionRecord $record,
        CheckoutSessionSnapshot $snapshot,
        CheckoutSessionAssociation $association,
        array $parameters,
    ): void {
        $selectedId = $snapshot->stripeShippingRateId();

        if ($selectedId === null) {
            // Open/expired Sessions need not have a selected rate.
            // A completed shipping checkout must have its authoritative selection available.
            if ($record->status === Session::STATUS_COMPLETE && $association->shippingRateIds() !== []) {
                throw new OrderDataException();
            }

            return;
        }

        $index = array_search($selectedId, $association->shippingRateIds(), true);

        if ($index === false) {
            throw new OrderDataException();
        }

        // Association preserves request option order, linking a generated Rate to the exact saved quote without consulting current shipping settings.
        $options = OrderData::list($parameters['shipping_options'] ?? null);
        $expectedRate = $this->map($this->map($options[$index])['shipping_rate_data'] ?? null);
        $returnedRate = $this->map($this->map($record->orderSnapshotSource['shipping_cost'] ?? null)['shipping_rate'] ?? null);
        $this->metadata($returnedRate['metadata'] ?? null, $expectedRate['metadata'] ?? null, $record->clientReferenceId ?? '');

        $returnedAmount = $this->map($returnedRate['fixed_amount'] ?? null);
        $expectedAmount = $this->map($expectedRate['fixed_amount'] ?? null);

        // Correlate the quoted facts, not Stripe's complete response shape:
        // the provider can add fields such as currency_options to fixed_amount.
        if (
            ($returnedAmount['amount'] ?? null) !== ($expectedAmount['amount'] ?? null)
            || ($returnedAmount['currency'] ?? null) !== ($expectedAmount['currency'] ?? null)
        ) {
            throw new OrderDataException();
        }

        $address = $snapshot->shippingAddress()?->toArray();
        $collection = $this->map($parameters['shipping_address_collection'] ?? null);

        if ($address !== null && in_array($address['country'], OrderData::list($collection['allowed_countries'] ?? null), true) === false) {
            throw new OrderDataException();
        }
    }

    /** @param array<string, mixed> $parameters */
    private function payment(CheckoutSessionReconciliationRecord $record, OrderCreationContext $order, array $parameters, Money $total): PaymentSnapshot
    {
        $source = $record->paymentSource;

        if ($source === null) {
            // A completed, non-zero payment-mode Session must have a payment observation even while an asynchronous payment remains unpaid.
            // https://docs.stripe.com/api/checkout/sessions/object#checkout_session_object-payment_intent
            if ($record->session->status === Session::STATUS_COMPLETE && $total->isZero() === false) {
                throw new OrderDataException();
            }

            // The Session total is known, but it is not evidence that a PaymentIntent exists or has an amount of its own.
            return new PaymentSnapshot();
        }

        $payment = $this->map($source);
        $paymentIntentId = $this->id($payment['id'] ?? null, 'pi_');
        $this->metadata($payment['metadata'] ?? null, $this->map($parameters['payment_intent_data'] ?? null)['metadata'] ?? null, $order->pageUuid());
        $currency = $order->currency();
        $amount = $this->amount($payment['amount'] ?? null, $currency);
        $received = $this->amount($payment['amount_received'] ?? null, $currency);
        $status = OrderData::text($payment['status'] ?? null, 255);
        $statuses = [PaymentIntent::STATUS_CANCELED, PaymentIntent::STATUS_PROCESSING, PaymentIntent::STATUS_REQUIRES_ACTION, PaymentIntent::STATUS_REQUIRES_CONFIRMATION, PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD, PaymentIntent::STATUS_SUCCEEDED];

        if (($payment['object'] ?? null) !== PaymentIntent::OBJECT_NAME) {
            throw new OrderDataException();
        }

        if (($payment['livemode'] ?? null) !== $record->session->liveMode) {
            throw new OrderDataException();
        }

        if (strtoupper(OrderData::text($payment['currency'] ?? null)) !== $currency) {
            throw new OrderDataException();
        }

        if ($amount->isEqualTo($total) === false) {
            throw new OrderDataException();
        }

        if (in_array($status, $statuses, true) === false) {
            throw new OrderDataException();
        }

        if (in_array($payment['capture_method'] ?? null, [PaymentIntent::CAPTURE_METHOD_AUTOMATIC, PaymentIntent::CAPTURE_METHOD_AUTOMATIC_ASYNC], true) === false) {
            throw new OrderDataException();
        }

        $method = isset($payment['payment_method']) ? $this->map($payment['payment_method']) : null;
        $methodId = $method === null ? null : $this->id($method['id'] ?? null, 'pm_');
        $methodType = $method === null ? null : OrderData::text($method['type'] ?? null, 255);

        if ($method !== null && ($method['object'] ?? null) !== PaymentMethod::OBJECT_NAME) {
            throw new OrderDataException();
        }

        // No Charge is not evidence of failure.
        // Leave charge-specific facts absent instead of inferring them from PaymentIntent status or amounts.
        $charge = isset($payment['latest_charge']) ? $this->map($payment['latest_charge']) : null;
        $chargeId = null;
        $chargeStatus = null;
        $chargeCreatedAt = null;
        $chargePaid = null;
        $chargeCaptured = null;
        $amountCaptured = null;
        $failureCode = OrderData::nullableSingleLine($payment['failure_code'] ?? null, 255);

        if ($charge !== null) {
            $chargeId = $this->id($charge['id'] ?? null, 'ch_');
            $chargeStatus = OrderData::text($charge['status'] ?? null, 255);
            $chargeMethodType = OrderData::nullableSingleLine($charge['method_type'] ?? null, 255);
            $chargeMethodId = ($charge['payment_method'] ?? null) === null
                ? null
                : $this->id($charge['payment_method'], 'pm_');

            if (
                ($charge['object'] ?? null) !== Charge::OBJECT_NAME
                || ($charge['payment_intent'] ?? null) !== $paymentIntentId
            ) {
                throw new OrderDataException();
            }

            if (($charge['livemode'] ?? null) !== $record->session->liveMode) {
                throw new OrderDataException();
            }

            if (strtoupper(OrderData::text($charge['currency'] ?? null)) !== $currency) {
                throw new OrderDataException();
            }

            if ($this->amount($charge['amount'] ?? null, $currency)->isEqualTo($amount) === false) {
                throw new OrderDataException();
            }

            if (in_array($chargeStatus, [Charge::STATUS_FAILED, Charge::STATUS_PENDING, Charge::STATUS_SUCCEEDED], true) === false) {
                throw new OrderDataException();
            }

            // The latest Charge can describe a previous failed attempt while the PaymentIntent already has a different method for its next try.
            // Prefer the current method; use Charge details only when it is absent.
            // https://docs.stripe.com/api/payment_intents/object#payment_intent_object-latest_charge
            if ($method === null) {
                $methodId = $chargeMethodId;
                $methodType = $chargeMethodType;
            }

            $chargeCreatedAt = $this->timestamp($charge['created'] ?? null);
            $chargePaid = OrderData::boolean($charge['paid'] ?? null);
            // Automatic asynchronous capture can leave capture facts incomplete after payment succeeds.
            // Preserve them; do not infer capture from paid.
            // https://docs.stripe.com/payments/payment-intents/asynchronous-capture
            $chargeCaptured = OrderData::boolean($charge['captured'] ?? null);
            $amountCaptured = $this->amount($charge['amount_captured'] ?? null, $currency);
            $failureCode ??= OrderData::nullableSingleLine($charge['failure_code'] ?? null, 255);
        }

        return new PaymentSnapshot(
            amount: $amount,
            amountReceived: $received,
            stripePaymentIntentId: $paymentIntentId,
            stripeChargeId: $chargeId,
            stripePaymentMethodId: $methodId,
            paymentIntentStatus: $status,
            chargeStatus: $chargeStatus,
            methodType: $methodType,
            createdAt: $this->timestamp($payment['created'] ?? null),
            chargeCreatedAt: $chargeCreatedAt,
            chargePaid: $chargePaid,
            chargeCaptured: $chargeCaptured,
            amountCaptured: $amountCaptured,
            failureCode: $failureCode,
            nextAction: $record->nextAction,
        );
    }

    private function timestamp(mixed $value): int
    {
        $timestamp = OrderData::integer($value);

        return $timestamp < 0 ? throw new OrderDataException() : $timestamp;
    }

    private function amount(mixed $value, string $currency): Money
    {
        $amount = OrderData::integer($value);

        if ($amount < 0) {
            throw new OrderDataException();
        }

        $registry = new StripeCurrencyRegistry();

        return $registry->toMoney($registry->fromProviderAmount($amount, $currency));
    }

    private function id(mixed $value, string $prefix): string
    {
        $id = OrderData::text($value, 255);

        return preg_match('/\A' . $prefix . '[A-Za-z0-9_]+\z/D', $id) === 1
            ? $id
            : throw new OrderDataException();
    }

    private function metadata(mixed $actual, mixed $expected, string $orderUuid): void
    {
        $actual = $this->map($actual);
        $expected = $this->map($expected);

        if (($actual[PluginMetadata::OWNER_KEY] ?? null) !== PluginMetadata::NAME || ($actual[PluginMetadata::ORDER_KEY] ?? null) !== $orderUuid) {
            throw new OrderDataException();
        }

        foreach ($expected as $key => $value) {
            if (str_starts_with($key, PluginMetadata::KEY_PREFIX) && ($actual[$key] ?? null) !== $value) {
                throw new OrderDataException();
            }
        }
    }

    /** @return array<string, mixed> */
    private function map(mixed $value): array
    {
        if (is_array($value) === false) {
            throw new OrderDataException();
        }

        foreach (array_keys($value) as $key) {
            if (is_string($key) === false) {
                throw new OrderDataException();
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
