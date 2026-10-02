<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Support\Stripe;

use Closure;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionGatewayInterface;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionReconciliationRecord;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionRecord;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Exception\CheckoutSessionGatewayException;
use RuntimeException;

/** Records deterministic Checkout Session creation and retrieval for offline tests. */
final class FakeCheckoutSessionGateway implements CheckoutSessionGatewayInterface
{
    /** @var list<SessionRequest> */
    public array $requests = [];

    /** @var list<string> */
    public array $idempotencyKeys = [];

    /** @var list<string> */
    public array $retrievals = [];

    /** @var list<string> */
    public array $reconciliationRetrievals = [];

    public ?CheckoutSessionGatewayException $creationFailure = null;

    public ?CheckoutSessionGatewayException $retrievalFailure = null;

    /** @var Closure(SessionRequest, string): void|null */
    public ?Closure $beforeCreate = null;

    /**
     * @param list<CheckoutSessionRecord> $results
     * @param array<string, CheckoutSessionRecord> $retrievalResults
     * @param array<string, CheckoutSessionReconciliationRecord> $reconciliationResults
     */
    public function __construct(
        private array $results = [],
        private array $retrievalResults = [],
        private array $reconciliationResults = [],
    ) {}

    public function create(
        SessionRequest $request,
        string $idempotencyKey,
    ): CheckoutSessionRecord {
        $this->requests[] = $request;
        $this->idempotencyKeys[] = $idempotencyKey;
        ($this->beforeCreate)?->__invoke($request, $idempotencyKey);

        if ($this->creationFailure !== null) {
            throw $this->creationFailure;
        }

        return array_shift($this->results)
            ?? throw new RuntimeException('No fake Checkout Session result is available.');
    }

    public function retrieve(string $sessionId): CheckoutSessionRecord
    {
        $this->retrievals[] = $sessionId;

        if ($this->retrievalFailure !== null) {
            throw $this->retrievalFailure;
        }

        return $this->retrievalResults[$sessionId]
            ?? throw new RuntimeException('No fake Checkout Session retrieval result is available.');
    }

    public function retrieveForReconciliation(string $sessionId): CheckoutSessionReconciliationRecord
    {
        $this->reconciliationRetrievals[] = $sessionId;

        if ($this->retrievalFailure !== null) {
            throw $this->retrievalFailure;
        }

        return $this->reconciliationResults[$sessionId]
            ?? throw new RuntimeException('No fake Checkout Session reconciliation result is available.');
    }
}
