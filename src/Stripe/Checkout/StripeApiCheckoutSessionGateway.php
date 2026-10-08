<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Checkout;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\ProductRequestNormalizer;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\PaymentAction;
use ProgrammatorDev\StripeCheckout\Plugin\PluginMetadata;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Exception\CheckoutSessionGatewayException;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Internal\CheckoutSessionFailureClassifier;
use Stripe\Charge;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Throwable;

/** Adapts Checkout Session creation and retrieval through the pinned Stripe API client. */
final class StripeApiCheckoutSessionGateway implements CheckoutSessionGatewayInterface
{
    public function __construct(
        private readonly StripeClient $client,
        private readonly CheckoutSessionFailureClassifier $failures = new CheckoutSessionFailureClassifier(),
    ) {}

    public function create(
        SessionRequest $request,
        string $idempotencyKey,
    ): CheckoutSessionRecord {
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('A Checkout Session idempotency key is required.');
        }

        try {
            $session = $this->client->checkout->sessions->create(
                // SessionRequest deliberately supports the SDK's complete scalar/map vocabulary, which is wider than its generated array shape.
                // @phpstan-ignore-next-line argument.type
                $request->parameters(),
                // Supplying our persisted key lets both SDK retries and a later PHP request address the same Stripe mutation.
                ['idempotency_key' => $idempotencyKey],
            );
        } catch (Throwable $error) {
            throw new CheckoutSessionGatewayException(
                failure: $this->failures->classify($error, mutation: true),
                error: $error,
            );
        }

        return $this->sessionRecord($session);
    }

    public function retrieve(string $sessionId): CheckoutSessionRecord
    {
        try {
            $session = $this->client->checkout->sessions->retrieve($sessionId, []);
        } catch (Throwable $error) {
            throw new CheckoutSessionGatewayException(
                failure: $this->failures->classify($error, mutation: false),
                error: $error,
            );
        }

        return $this->sessionRecord($session);
    }

    public function retrieveForReconciliation(string $sessionId): CheckoutSessionReconciliationRecord
    {
        try {
            $session = $this->client->checkout->sessions->retrieve($sessionId, [
                'expand' => [
                    'payment_intent.latest_charge',
                    'payment_intent.payment_method',
                    'shipping_cost.shipping_rate',
                    // Shipping allocations are includable separately from the Rate.
                    // https://docs.stripe.com/api/checkout/sessions/object#checkout_session_object-shipping_cost-taxes
                    'shipping_cost.taxes',
                    'total_details.breakdown',
                ],
            ]);
            $lineItems = $this->completeLineItems($sessionId);
            $record = new CheckoutSessionReconciliationRecord(
                session: $this->sessionRecord($session),
                lineItems: $lineItems,
                paymentSource: $this->paymentSource($session->payment_intent ?? null),
                nextAction: $this->nextAction($session->payment_intent ?? null),
            );
        } catch (OrderDataException $error) {
            throw new CheckoutSessionGatewayException(
                failure: new CheckoutSessionFailure(CheckoutSessionFailureType::Incompatible, false),
                error: $error,
            );
        } catch (Throwable $error) {
            throw new CheckoutSessionGatewayException(
                failure: $this->failures->classify($error, mutation: false),
                error: $error,
            );
        }

        return $record;
    }

    public function sessionForPaymentIntent(string $paymentIntentId): ?string
    {
        try {
            // Two results detect ambiguity; never pick the first match or use eventually consistent Search.
            // https://docs.stripe.com/api/checkout/sessions/list
            $collection = $this->client->checkout->sessions->all([
                'payment_intent' => $paymentIntentId,
                'limit' => 2,
            ])->toArray();
            $items = OrderData::list($collection['data'] ?? null);

            if (OrderData::boolean($collection['has_more'] ?? null) || count($items) > 1) {
                throw new OrderDataException();
            }

            if ($items === []) {
                return null;
            }

            $item = $items[0];

            if (is_array($item) === false) {
                throw new OrderDataException();
            }

            // Inspect only the lookup's correlation facts; unrelated provider fields are outside the canonical scalar contract.
            if (($item['object'] ?? null) !== Session::OBJECT_NAME || ($item['payment_intent'] ?? null) !== $paymentIntentId) {
                throw new OrderDataException();
            }

            // The subsequent Session read validates the full result against the saved purchase.
            return OrderData::string($item['id'] ?? null);
        } catch (OrderDataException $error) {
            throw new CheckoutSessionGatewayException(new CheckoutSessionFailure(CheckoutSessionFailureType::Incompatible, false), $error);
        } catch (Throwable $error) {
            throw new CheckoutSessionGatewayException($this->failures->classify($error, mutation: false), $error);
        }
    }

    public function discoverForOrder(
        string $pageUuid,
        int $createdFrom,
        int $createdBefore,
        ?string $startingAfter = null,
    ): CheckoutSessionDiscoveryPage {
        try {
            // The list API has no metadata filter. Inspect one bounded page and expose its cursor,
            // so a busy account's creation window need not be scanned in one PHP request.
            // https://docs.stripe.com/api/checkout/sessions/list
            $parameters = [
                'created' => [
                    'gte' => $createdFrom,
                    'lt' => $createdBefore,
                ],
                'limit' => 100,
            ];

            if ($startingAfter !== null) {
                $parameters['starting_after'] = $startingAfter;
            }

            $collection = $this->client->checkout->sessions->all($parameters)->toArray();
            $items = OrderData::list($collection['data'] ?? null);
            $hasMore = OrderData::boolean($collection['has_more'] ?? null);
            $sessionIds = [];
            $lastId = null;
            $seen = [];

            foreach ($items as $item) {
                if (is_array($item) === false || ($item['object'] ?? null) !== Session::OBJECT_NAME) {
                    throw new OrderDataException();
                }

                $id = OrderData::nonEmptyString($item['id'] ?? null);

                if ($id === $startingAfter || isset($seen[$id])) {
                    throw new OrderDataException();
                }

                $seen[$id] = true;
                // Advance past every provider result, including unrelated Sessions.
                // Using only a matching ID would repeat or stall pages that contain no matches.
                $lastId = $id;
                $metadata = $item['metadata'] ?? null;

                if (
                    is_array($metadata)
                    && ($metadata[PluginMetadata::OWNER_KEY] ?? null) === PluginMetadata::NAME
                    && ($metadata[PluginMetadata::ORDER_KEY] ?? null) === $pageUuid
                ) {
                    $sessionIds[] = $id;
                }
            }

            if ($hasMore && $lastId === null) {
                throw new OrderDataException();
            }

            return new CheckoutSessionDiscoveryPage($sessionIds, $hasMore ? $lastId : null);
        } catch (OrderDataException $error) {
            throw new CheckoutSessionGatewayException(new CheckoutSessionFailure(CheckoutSessionFailureType::Incompatible, false), $error);
        } catch (Throwable $error) {
            throw new CheckoutSessionGatewayException($this->failures->classify($error, mutation: false), $error);
        }
    }

    /** @return list<array<string, mixed>> */
    private function completeLineItems(string $sessionId): array
    {
        $items = [];
        $ids = [];
        $startingAfter = null;

        // Expanded Session lines are only a preview.
        // Read the dedicated endpoint to completion, rejecting repeated cursors rather than returning a prefix.
        // https://docs.stripe.com/api/checkout/sessions/line_items
        for ($page = 0; $page < ProductRequestNormalizer::MAX_ENTRIES; $page++) {
            $parameters = [
                'limit' => 100,
                'expand' => ['data.discounts.discount', 'data.taxes.rate'],
            ];

            if ($startingAfter !== null) {
                $parameters['starting_after'] = $startingAfter;
            }

            $collection = $this->client->checkout->sessions->allLineItems($sessionId, $parameters);
            $collectionData = $collection->toArray();
            $data = $collectionData['data'] ?? null;
            $hasMore = $collectionData['has_more'] ?? null;

            if (is_array($data) === false || array_is_list($data) === false || is_bool($hasMore) === false) {
                throw new OrderDataException();
            }

            foreach ($data as $lineItem) {
                if (
                    is_array($lineItem) === false
                    || is_string($lineItem['id'] ?? null) === false
                    || $lineItem['id'] === ''
                    || isset($ids[$lineItem['id']])
                    || count($items) >= ProductRequestNormalizer::MAX_ENTRIES
                ) {
                    throw new OrderDataException();
                }

                $ids[$lineItem['id']] = true;
                $items[] = $this->lineItemSource($lineItem);
                $startingAfter = $lineItem['id'];
            }

            if ($hasMore === false) {
                return $items;
            }

            if ($data === []) {
                throw new OrderDataException();
            }
        }

        throw new OrderDataException();
    }

    /**
     * Projects selected provider fields without certifying purchase correlation.
     * Missing required facts remain missing/null for the retriever to reject.
     *
     * @param array<array-key, mixed> $lineItem
     * @return array<string, mixed>
     */
    private function lineItemSource(array $lineItem): array
    {
        $source = array_intersect_key($lineItem, array_flip([
            'id', 'object', 'metadata', 'quantity', 'currency', 'description',
            'amount_subtotal', 'amount_discount', 'amount_tax', 'amount_total', 'discounts', 'taxes',
        ]));
        $price = $lineItem['price'] ?? null;
        $source['price'] = is_array($price)
            ? array_intersect_key($price, array_flip([
                'id', 'object', 'product', 'currency', 'unit_amount', 'unit_amount_decimal',
                'billing_scheme', 'type', 'recurring', 'transform_quantity',
            ]))
            : null;

        return $source;
    }

    private function sessionRecord(Session $session): CheckoutSessionRecord
    {
        $metadata = $session->metadata;
        $lastResponse = $session->getLastResponse();
        $metadata = $metadata instanceof StripeObject ? $metadata->toArray() : [];
        $sessionData = $session->toArray();
        $orderSnapshotSource = [];
        $snapshotFields = [
            'customer',
            'customer_details',
            'collected_information',
            'custom_fields',
            'consent',
            'total_details',
            'automatic_tax',
            'line_items',
            'shipping_cost',
        ];

        // Keep only the provider fields owned by the accepted order snapshots.
        // The strict normalizer deliberately sees malformed nested values instead of silently treating them as absent,
        // while SDK objects stop at this edge.
        foreach ($snapshotFields as $field) {
            if (array_key_exists($field, $sessionData)) {
                $value = $sessionData[$field];

                // An expanded Customer can contain far more than the one stable reference owned by the order.
                // Keep only that ID at this edge.
                if ($field === 'customer' && is_array($value)) {
                    $value = ['id' => $value['id'] ?? null];
                }

                $orderSnapshotSource[$field] = $value;
            }
        }

        /** @var array<string, mixed> $metadata */
        return new CheckoutSessionRecord(
            id: $this->nullableString($session->id),
            createdAt: $this->nullableInteger($session->created),
            expiresAt: $this->nullableInteger($session->expires_at),
            status: $this->nullableString($session->status),
            paymentStatus: $this->nullableString($session->payment_status),
            liveMode: $this->nullableBoolean($session->livemode),
            mode: $this->nullableString($session->mode),
            uiMode: $this->nullableString($session->ui_mode),
            currency: $this->nullableString($session->currency),
            clientReferenceId: $this->nullableString($session->client_reference_id),
            integrationIdentifier: $this->nullableString($session->integration_identifier),
            metadata: $metadata,
            requestId: $this->nullableString($lastResponse?->headers['request-id'] ?? null),
            url: $this->nullableString($session->url ?? null),
            clientSecret: $this->nullableString($session->client_secret ?? null),
            orderSnapshotSource: $orderSnapshotSource,
            shippingOptions: $this->shippingOptions($sessionData['shipping_options'] ?? null),
            amountSubtotal: $this->nullableInteger($sessionData['amount_subtotal'] ?? null),
            amountTotal: $this->nullableInteger($sessionData['amount_total'] ?? null),
            invoiceId: $this->nullableString($sessionData['invoice'] ?? null),
        );
    }

    /** @return array<string, mixed>|null */
    private function paymentSource(mixed $paymentIntent): ?array
    {
        if ($paymentIntent === null) {
            return null;
        }

        // These expansions are required by this read contract.
        // An ID alone is not a partial payment observation that can be silently accepted.
        if ($paymentIntent instanceof PaymentIntent === false) {
            throw new OrderDataException();
        }

        $payment = $this->selectedFields($paymentIntent, [
            'id', 'object', 'livemode', 'currency', 'metadata', 'created', 'status',
            'amount', 'amount_received', 'capture_method',
        ]);
        $payment['failure_code'] = $paymentIntent->last_payment_error->code ?? null;
        $method = $paymentIntent->payment_method ?? null;

        // Retain the method reference/type, not its card or bank details.
        if ($method instanceof StripeObject) {
            $payment['payment_method'] = $this->selectedFields($method, ['id', 'object', 'type']);
        } elseif ($method !== null) {
            throw new OrderDataException();
        }

        $charge = $paymentIntent->latest_charge ?? null;

        if ($charge instanceof Charge) {
            $payment['latest_charge'] = $this->selectedFields($charge, [
                'id', 'object', 'livemode', 'payment_intent', 'currency', 'amount', 'created',
                'status', 'paid', 'captured', 'amount_captured', 'payment_method',
            ]);
            $payment['latest_charge']['method_type'] = $charge->payment_method_details->type ?? null;
            $payment['latest_charge']['failure_code'] = $charge->failure_code ?? null;
        } elseif ($charge !== null) {
            throw new OrderDataException();
        }

        return $payment;
    }

    private function nextAction(mixed $paymentIntent): ?PaymentAction
    {
        if ($paymentIntent instanceof PaymentIntent === false) {
            return null;
        }

        // Unlike selected payment facts, this branch can contain authentication directives.
        // Keep it separate from the canonical order snapshot source.
        $nextAction = $paymentIntent->toArray()['next_action'] ?? null;

        if ($nextAction !== null && is_array($nextAction) === false) {
            throw new OrderDataException();
        }

        return PaymentAction::fromArray($nextAction);
    }

    /** @param list<string> $fields
     * @return array<string, mixed>
     */
    private function selectedFields(StripeObject $object, array $fields): array
    {
        return array_intersect_key($object->toArray(), array_flip($fields));
    }

    private function shippingOptions(mixed $shippingOptions): mixed
    {
        if (is_array($shippingOptions) === false) {
            return $shippingOptions;
        }

        foreach ($shippingOptions as $key => $shippingOption) {
            if (is_array($shippingOption) && is_array($shippingOption['shipping_rate'] ?? null)) {
                // Request filters can expand Rates.
                // Keep only their references, preserving keys and malformed values for request-bound validation.
                // https://docs.stripe.com/api/checkout/sessions/object#checkout_session_object-shipping_options-shipping_rate
                $shippingOption['shipping_rate'] = $shippingOption['shipping_rate']['id'] ?? null;
                $shippingOptions[$key] = $shippingOption;
            }
        }

        return $shippingOptions;
    }

    private function nullableBoolean(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
