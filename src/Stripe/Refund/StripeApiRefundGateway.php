<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Refund;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Internal\CheckoutSessionFailureClassifier;
use Stripe\StripeClient;
use Throwable;

/** @internal Pinned API reads for complete refund collections, with the existing sanitized read-failure policy. */
final class StripeApiRefundGateway implements RefundGatewayInterface
{
    // A safety bound aborts excessive histories; it never authorizes a partial collection.
    private const MAX_REFUNDS = 10000;
    private const REFUND_FIELDS = ['id', 'object', 'amount', 'currency', 'charge', 'payment_intent', 'status', 'reason', 'failure_reason', 'pending_reason', 'created'];

    public function __construct(
        private readonly StripeClient $client,
        private readonly CheckoutSessionFailureClassifier $failures = new CheckoutSessionFailureClassifier(),
    ) {}

    public function retrieve(string $refundId): array
    {
        try {
            return $this->selectRefundFacts($this->client->refunds->retrieve($refundId)->toArray());
        } catch (Throwable $error) {
            throw $this->failure($error);
        }
    }

    public function retrievePaymentIntent(string $paymentIntentId): array
    {
        try {
            return array_intersect_key($this->client->paymentIntents->retrieve($paymentIntentId)->toArray(), array_flip([
                'id', 'object', 'metadata', 'currency', 'livemode',
            ]));
        } catch (Throwable $error) {
            throw $this->failure($error);
        }
    }

    public function retrieveCharge(string $chargeId): array
    {
        try {
            return array_intersect_key($this->client->charges->retrieve($chargeId)->toArray(), array_flip([
                'id', 'object', 'payment_intent', 'currency', 'livemode',
            ]));
        } catch (Throwable $error) {
            throw $this->failure($error);
        }
    }

    public function allForPaymentIntent(string $paymentIntentId): array
    {
        try {
            $refunds = [];
            $ids = [];
            $startingAfter = null;

            // Charge.refunds is only a preview. Complete the dedicated filtered list or reject the entire observation.
            // https://docs.stripe.com/api/refunds/list
            for ($page = 0; $page < self::MAX_REFUNDS; $page++) {
                $parameters = [
                    'payment_intent' => $paymentIntentId,
                    'limit' => 100,
                ];

                if ($startingAfter !== null) {
                    $parameters['starting_after'] = $startingAfter;
                }

                $collection = $this->client->refunds->all($parameters)->toArray();
                $items = OrderData::list($collection['data'] ?? null);
                $hasMore = OrderData::boolean($collection['has_more'] ?? null);

                if ($items === [] && ($startingAfter !== null || $hasMore)) {
                    throw new OrderDataException();
                }

                foreach ($items as $item) {
                    if (is_array($item) === false) {
                        throw new OrderDataException();
                    }

                    $item = $this->selectRefundFacts($item);
                    $id = OrderData::string($item['id'] ?? null);

                    if (isset($ids[$id]) || count($refunds) >= self::MAX_REFUNDS) {
                        throw new OrderDataException();
                    }

                    $ids[$id] = true;
                    $refunds[] = $item;
                    $startingAfter = $id;
                }

                if ($hasMore === false) {
                    return $refunds;
                }

            }

            throw new OrderDataException();
        } catch (Throwable $error) {
            throw $this->failure($error);
        }
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    private function selectRefundFacts(array $data): array
    {
        // Discard unmodeled provider fields before applying the canonical scalar vocabulary.
        return OrderData::map(array_intersect_key($data, array_flip(self::REFUND_FIELDS)));
    }

    private function failure(Throwable $error): CheckoutSessionException
    {
        if ($error instanceof OrderDataException) {
            return new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE, previous: $error);
        }

        $failure = $this->failures->classify($error, mutation: false);

        return new CheckoutSessionException(
            errorCode: CheckoutErrorCode::forSessionFailure($failure->type()),
            retryable: $failure->isRetryable(),
            previous: $error,
        );
    }
}
