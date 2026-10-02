<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Order\PaymentAction;
use ProgrammatorDev\StripeCheckout\Plugin\PluginMetadata;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\PaymentIntent;

/** @internal Selected, order-correlated facts from a verified Event; never its raw provider graph. */
final readonly class ReconciliationEvent
{
    public const TYPES = [
        Event::CHECKOUT_SESSION_COMPLETED,
        Event::CHECKOUT_SESSION_ASYNC_PAYMENT_SUCCEEDED,
        Event::CHECKOUT_SESSION_ASYNC_PAYMENT_FAILED,
        Event::CHECKOUT_SESSION_EXPIRED,
        Event::PAYMENT_INTENT_REQUIRES_ACTION,
    ];

    private function __construct(
        public string $id,
        public string $type,
        public int $createdAt,
        public string $resourceId,
        public ?PaymentAction $nextAction,
    ) {}

    /** Signature verification belongs to the HTTP edge; this boundary checks ownership, mode and purchase identity. */
    public static function fromStripe(Event $event, OrderCreationContext $order, string $sessionId, CredentialMode $mode): self
    {
        $data = $event->toArray();
        $type = OrderData::text($data['type'] ?? null, 255);
        $id = OrderData::text($data['id'] ?? null, 255);
        $created = OrderData::integer($data['created'] ?? null);
        $eventData = $data['data'] ?? null;

        if (is_array($eventData) === false) {
            throw new OrderDataException();
        }

        $object = $eventData['object'] ?? null;

        if (is_array($object) === false) {
            throw new OrderDataException();
        }

        // Validate the selected envelope, not the full provider graph; action details use their own JSON boundary below.
        $metadata = OrderData::map($object['metadata'] ?? null);
        $liveMode = OrderData::boolean($data['livemode'] ?? null);
        $isAction = $type === Event::PAYMENT_INTENT_REQUIRES_ACTION;
        $resourceId = OrderData::text($object['id'] ?? null, 255);

        if (
            in_array($type, self::TYPES, true) === false
            || preg_match('/\Aevt_[A-Za-z0-9_]+\z/', $id) !== 1
            || $created < 0
            || ($data['object'] ?? null) !== Event::OBJECT_NAME
            || ($object['livemode'] ?? null) !== $liveMode
            || $mode === CredentialMode::Unknown
            || $liveMode !== ($mode === CredentialMode::Live)
            || ($metadata[PluginMetadata::OWNER_KEY] ?? null) !== PluginMetadata::NAME
            || ($metadata[PluginMetadata::ORDER_KEY] ?? null) !== $order->pageUuid()
            || ($object['object'] ?? null) !== ($isAction ? PaymentIntent::OBJECT_NAME : Session::OBJECT_NAME)
            || $isAction === false && ($resourceId !== $sessionId || ($object['client_reference_id'] ?? null) !== $order->pageUuid())
            || $isAction && (
                preg_match('/\Api_[A-Za-z0-9_]+\z/', $resourceId) !== 1
                || ($object['status'] ?? null) !== PaymentIntent::STATUS_REQUIRES_ACTION
                || strtoupper(OrderData::text($object['currency'] ?? null)) !== $order->currency()
            )
        ) {
            throw new OrderDataException();
        }

        $action = $isAction ? ($object['next_action'] ?? null) : null;

        if ($action !== null && is_array($action) === false) {
            throw new OrderDataException();
        }

        return new self($id, $type, $created, $resourceId, PaymentAction::fromArray($action));
    }
}
