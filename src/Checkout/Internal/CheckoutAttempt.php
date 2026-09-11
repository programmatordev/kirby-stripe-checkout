<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use DateInterval;
use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;

/** Complete immutable evidence required to create or safely repeat one Session mutation. */
final readonly class CheckoutAttempt
{
    public const OPERATION = 'checkout.sessions.create';

    // Stripe may prune idempotency results after 24 hours. Stop automatic
    // mutation retries one hour earlier rather than risk creating a new Session.
    public const RETRY_WINDOW = 'PT23H';

    public const SESSION_LIFETIME = 'PT24H';

    private string $idempotencyKey;

    private DateTimeImmutable $retryUntil;

    public function __construct(
        private OrderCreationContext $order,
        private SessionRequestContext $context,
        private SessionRequest $request,
        private AttemptBinding $binding,
        private AttemptToken $token,
        private ?string $guestReference,
        private string $stripeApiVersion,
        private CredentialMode $credentialMode,
        private string $credentialFingerprint,
        DateTimeImmutable $createdAt,
    ) {
        if ($context->order() !== $order) {
            throw new OrderDataException();
        }

        // The token and context must reserve the same future Kirby identity.
        // This invariant ensures that one token can address only one Order.
        if ($token->orderUuid() !== $order->uuid()) {
            throw new OrderDataException();
        }

        OrderData::text($stripeApiVersion, 80);

        if (preg_match('/\A[a-f0-9]{64}\z/', $credentialFingerprint) !== 1) {
            throw new OrderDataException();
        }

        if (($order->userUuid() === null) === ($guestReference === null)) {
            throw new OrderDataException();
        }

        $binding->assertCompatible($order, $guestReference);

        if ($guestReference !== null) {
            OrderData::text($guestReference, 128);
        }

        $this->idempotencyKey = 'stripe-checkout/session/' . $order->uuid();
        $this->retryUntil = $createdAt->add(new DateInterval(self::RETRY_WINDOW));

        if ($context->expiresAt() != $createdAt->add(new DateInterval(self::SESSION_LIFETIME))) {
            throw new OrderDataException();
        }
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->context->expiresAt();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tokenHash' => $this->token->hash(),
            // The binding identifies the customer action before an Order exists;
            // the request fingerprint protects the exact later Stripe mutation.
            'bindingFingerprint' => $this->binding->fingerprint(),
            'requestFingerprint' => $this->request->fingerprint(),
            'sessionRequest' => $this->request->parameters(),
            'idempotencyKey' => $this->idempotencyKey,
            // An exact retry must retain the API semantics used for the first POST.
            'stripeApiVersion' => $this->stripeApiVersion,
            // Mode drives transport/presentation policy. The opaque fingerprint
            // separately prevents reuse with another credential in the same mode.
            'credentialMode' => $this->credentialMode->value,
            'credentialFingerprint' => $this->credentialFingerprint,
            'operation' => self::OPERATION,
            'retryUntil' => OrderData::timestamp($this->retryUntil),
            'source' => $this->order->checkoutSource()->value,
            'cartRevision' => $this->order->cartRevision(),
            'guestReference' => $this->guestReference,
            'uiMode' => $this->order->uiMode()->value,
            'initiatingUrl' => $this->context->initiatingUrl(),
            'successUrl' => $this->context->successDestination(),
            'cancelUrl' => $this->context->cancelDestination(),
            'returnUrl' => $this->context->returnDestination(),
            'providerFailure' => null,
        ];
    }
}
