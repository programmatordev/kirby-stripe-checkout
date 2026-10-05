<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Dispute;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Internal\CheckoutSessionFailureClassifier;
use Stripe\StripeClient;
use Throwable;

/** @internal Pinned API reads for complete dispute collections, with the existing sanitized read-failure policy. */
final class StripeApiDisputeGateway implements DisputeGatewayInterface
{
    // A safety bound aborts excessive histories; it never authorizes a partial collection.
    private const MAX_DISPUTES = 10000;
    private const DISPUTE_FIELDS = ['id', 'object', 'amount', 'currency', 'charge', 'payment_intent', 'status', 'reason', 'evidence_details', 'balance_transactions', 'livemode', 'created'];

    public function __construct(
        private readonly StripeClient $client,
        private readonly CheckoutSessionFailureClassifier $failures = new CheckoutSessionFailureClassifier(),
    ) {}

    public function retrieve(string $disputeId): array
    {
        try {
            return $this->selectDisputeFacts($this->client->disputes->retrieve($disputeId)->toArray());
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
            $disputes = [];
            $ids = [];
            $startingAfter = null;

            // Complete the dedicated PaymentIntent-filtered list; a triggering Dispute does not prove the entire collection.
            // https://docs.stripe.com/api/disputes/list
            for ($page = 0; $page < self::MAX_DISPUTES; $page++) {
                $parameters = [
                    'payment_intent' => $paymentIntentId,
                    'limit' => 100,
                ];

                if ($startingAfter !== null) {
                    $parameters['starting_after'] = $startingAfter;
                }

                $collection = $this->client->disputes->all($parameters)->toArray();
                $items = OrderData::list($collection['data'] ?? null);
                $hasMore = OrderData::boolean($collection['has_more'] ?? null);

                if ($items === [] && ($startingAfter !== null || $hasMore)) {
                    throw new OrderDataException();
                }

                foreach ($items as $item) {
                    if (is_array($item) === false) {
                        throw new OrderDataException();
                    }

                    $item = $this->selectDisputeFacts($item);
                    $id = OrderData::string($item['id'] ?? null);

                    if (isset($ids[$id]) || count($disputes) >= self::MAX_DISPUTES) {
                        throw new OrderDataException();
                    }

                    $ids[$id] = true;
                    $disputes[] = $item;
                    $startingAfter = $id;
                }

                if ($hasMore === false) {
                    return $disputes;
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
    private function selectDisputeFacts(array $data): array
    {
        // Discard unmodeled provider fields before applying the canonical scalar vocabulary.
        $data = array_intersect_key($data, array_flip(self::DISPUTE_FIELDS));

        if (is_array($data['evidence_details'] ?? null)) {
            $data['evidence_details'] = array_intersect_key($data['evidence_details'], array_flip([
                'due_by', 'has_evidence', 'past_due', 'submission_count',
            ]));
        }

        if (is_array($data['balance_transactions'] ?? null)) {
            $data['balance_transactions'] = array_map(static function (mixed $item): mixed {
                return is_array($item) ? array_intersect_key($item, array_flip([
                    'id', 'object', 'currency', 'amount', 'fee', 'net', 'created',
                ])) : $item;
            }, $data['balance_transactions']);
        }

        return OrderData::map($data);
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
