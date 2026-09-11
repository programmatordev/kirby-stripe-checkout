<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Support\Stripe;

use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionGatewayInterface;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionRecord;
use RuntimeException;

/** Records deterministic Checkout Session creations for offline tests. */
final class FakeCheckoutSessionGateway implements CheckoutSessionGatewayInterface
{
    /** @var list<SessionRequest> */
    public array $requests = [];

    /** @var list<string> */
    public array $idempotencyKeys = [];

    public ?RuntimeException $failure = null;

    /** @param list<CheckoutSessionRecord> $results */
    public function __construct(private array $results = []) {}

    public function create(
        SessionRequest $request,
        string $idempotencyKey,
    ): CheckoutSessionRecord {
        $this->requests[] = $request;
        $this->idempotencyKeys[] = $idempotencyKey;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return array_shift($this->results)
            ?? throw new RuntimeException('No fake Checkout Session result is available.');
    }
}
