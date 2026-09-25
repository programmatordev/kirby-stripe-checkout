<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;

/** Immutable provider references established when one Checkout Session is associated. */
final readonly class CheckoutSessionAssociation
{
    /** @var list<string> */
    private array $shippingRateIds;

    /**
     * @param array<mixed> $shippingRateIds
     */
    public function __construct(
        private string $sessionId,
        array $shippingRateIds,
        SessionRequest $request,
    ) {
        if (preg_match('/\Acs_[A-Za-z0-9_]+\z/D', $sessionId) !== 1 || array_is_list($shippingRateIds) === false) {
            throw new OrderDataException();
        }

        $expectedOptions = $request->parameters()['shipping_options'] ?? null;

        if ($expectedOptions === null) {
            if ($shippingRateIds !== []) {
                throw new OrderDataException();
            }
        } elseif (
            is_array($expectedOptions) === false
            || array_is_list($expectedOptions) === false
            || count($shippingRateIds) !== count($expectedOptions)
        ) {
            throw new OrderDataException();
        }

        foreach ($shippingRateIds as $shippingRateId) {
            if (
                is_string($shippingRateId) === false
                || preg_match('/\Ashr_[A-Za-z0-9_]+\z/D', $shippingRateId) !== 1
            ) {
                throw new OrderDataException();
            }
        }

        if (count(array_unique($shippingRateIds)) !== count($shippingRateIds)) {
            throw new OrderDataException();
        }

        $this->shippingRateIds = $shippingRateIds;
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    /** @param array<string, mixed> $data */
    public static function fromOrderData(array $data, SessionRequest $request): self
    {
        return new self(
            sessionId: OrderData::text($data['stripeCheckoutSessionId'] ?? null, 255),
            shippingRateIds: is_array($data['stripeShippingRateIds'] ?? null)
                ? $data['stripeShippingRateIds']
                : throw new OrderDataException(),
            request: $request,
        );
    }

    /** @return list<string> */
    public function shippingRateIds(): array
    {
        return $this->shippingRateIds;
    }

    /** @return array{stripeCheckoutSessionId: string, stripeShippingRateIds: list<string>} */
    public function toOrderData(): array
    {
        return [
            'stripeCheckoutSessionId' => $this->sessionId,
            'stripeShippingRateIds' => $this->shippingRateIds,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->sessionId === $other->sessionId
            && $this->shippingRateIds === $other->shippingRateIds;
    }
}
