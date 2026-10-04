<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\App;
use Kirby\Http\Response;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\ReconciliationEvent;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationErrorCode;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Configuration\StripeConfiguration;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSerializer;
use ProgrammatorDev\StripeCheckout\Order\Internal\RefundSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\StripeEventLedger;
use ProgrammatorDev\StripeCheckout\Plugin\PluginMetadata;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Webhook\WebhookErrorCode;
use Stripe\Event;
use Stripe\Webhook;
use Throwable;
use UnexpectedValueException;

/** @internal Signed HTTP adapter; the reconciler owns purchase validation, provider reads and canonical commits. */
final class WebhookEndpoint
{
    public const HEADERS = ['Cache-Control' => 'no-store'];

    private const HTTP_NO_CONTENT = 204;
    private const HTTP_BAD_REQUEST = 400;
    private const HTTP_INTERNAL_SERVER_ERROR = 500;
    private const HTTP_SERVICE_UNAVAILABLE = 503;

    private readonly OrderPageStore $orders;

    public function __construct(private readonly App $kirby)
    {
        $this->orders = new OrderPageStore($kirby);
    }

    public function respond(): Response
    {
        try {
            /** @var array<string, mixed> $options */
            $options = $this->kirby->options();
            $stripe = (new ConfigurationResolver())->stripe($options);
            $secret = $stripe->webhookSecret();
        } catch (ConfigurationException) {
            return $this->response(self::HTTP_INTERNAL_SERVER_ERROR, WebhookErrorCode::PROCESSING_UNAVAILABLE);
        }

        if ($secret === null) {
            return $this->response(self::HTTP_SERVICE_UNAVAILABLE, WebhookErrorCode::PROCESSING_UNAVAILABLE);
        }

        // Body parsing/string conversion changes signed bytes; use Kirby's original contents exactly once.
        try {
            $body = $this->kirby->request()->body()->contents();
            $signature = $this->kirby->request()->header('Stripe-Signature');
        } catch (Throwable) {
            return $this->response(self::HTTP_INTERNAL_SERVER_ERROR, WebhookErrorCode::PROCESSING_UNAVAILABLE);
        }

        if (is_string($body) === false || is_string($signature) === false) {
            return $this->response(self::HTTP_BAD_REQUEST, WebhookErrorCode::SIGNATURE_INVALID);
        }

        // SDK 22 can emit PHP warnings for header fragments without '='.
        // Contain that reproduced parsing gap inside verification without retaining payload-bearing SDK errors.
        set_error_handler(static function (): never {
            throw new UnexpectedValueException();
        });

        try {
            // Keep Stripe's five-minute replay tolerance and support for overlapping v1 signatures.
            // https://docs.stripe.com/webhooks#preventing-replay-attacks
            $event = Webhook::constructEvent($body, $signature, $secret);
        } catch (Throwable) {
            return $this->response(self::HTTP_BAD_REQUEST, WebhookErrorCode::SIGNATURE_INVALID);
        } finally {
            restore_error_handler();
        }

        try {
            $httpStatus = $this->process($event, $stripe);
        } catch (Throwable) {
            return $this->response(self::HTTP_INTERNAL_SERVER_ERROR, WebhookErrorCode::PROCESSING_UNAVAILABLE);
        }

        return $this->response($httpStatus, $httpStatus === self::HTTP_BAD_REQUEST ? WebhookErrorCode::PAYLOAD_INVALID : null);
    }

    private function process(Event $event, StripeConfiguration $stripe): int
    {
        $data = $event->toArray();
        $id = $data['id'] ?? null;
        $type = $data['type'] ?? null;
        $eventData = $data['data'] ?? null;

        if (($data['object'] ?? null) !== Event::OBJECT_NAME) {
            return self::HTTP_BAD_REQUEST;
        }

        if (is_string($id) === false || $id === '') {
            return self::HTTP_BAD_REQUEST;
        }

        if (is_string($type) === false || $type === '') {
            return self::HTTP_BAD_REQUEST;
        }

        if (in_array($type, ReconciliationEvent::TYPES, true) === false) {
            return self::HTTP_NO_CONTENT;
        }

        if (is_array($eventData) === false) {
            return self::HTTP_BAD_REQUEST;
        }

        $object = $eventData['object'] ?? null;

        if (is_array($object) === false) {
            return self::HTTP_BAD_REQUEST;
        }

        if (in_array($type, ReconciliationEvent::REFUND_TYPES, true)) {
            return $this->processRefund($event, $stripe);
        }

        $metadata = $object['metadata'] ?? [];

        if (is_array($metadata) === false) {
            return self::HTTP_BAD_REQUEST;
        }

        if (($metadata[PluginMetadata::OWNER_KEY] ?? null) !== PluginMetadata::NAME) {
            return self::HTTP_NO_CONTENT;
        }

        $pageUuid = $metadata[PluginMetadata::ORDER_KEY] ?? null;
        $pageUuid = is_string($pageUuid) ? $pageUuid : null;

        try {
            $page = $pageUuid === null ? null : $this->orders->order($pageUuid);

            if ($page === null) {
                return $this->failure($event, $pageUuid, self::HTTP_INTERNAL_SERVER_ERROR);
            }

            if ($type === Event::PAYMENT_INTENT_REQUIRES_ACTION) {
                $data = $this->orders->data($page);
                $sessionId = $data['stripeCheckoutSessionId'] ?? null;

                // An early action needs the saved Session backlink, not completion or a pending-payment transition.
                if ($sessionId === null) {
                    $attempt = OrderData::map($data['checkoutAttempt']);
                    // Missing association is temporary only if the selected Event facts fit the saved purchase.
                    // The action envelope does not need a Session ID; its eventual backlink remains the reconciler's rule.
                    ReconciliationEvent::fromStripe(
                        event: $event,
                        order: OrderSerializer::context($data),
                        sessionId: null,
                        mode: CredentialMode::from(OrderData::string($attempt['credentialMode'])),
                    );

                    return $this->failure($event, $pageUuid, self::HTTP_SERVICE_UNAVAILABLE);
                }
            } else {
                $sessionId = $object['id'] ?? null;
            }

            if (is_string($sessionId) === false || $sessionId === '') {
                return $this->failure($event, $pageUuid, self::HTTP_INTERNAL_SERVER_ERROR);
            }

            (new RuntimeFactory($this->kirby))->checkoutSessionReconciler($stripe)->reconcile($pageUuid, $sessionId, $event);

            return self::HTTP_NO_CONTENT;
        } catch (Throwable $error) {
            return $this->failure($event, $pageUuid, $this->httpStatusForFailure($error));
        }
    }

    private function processRefund(Event $event, StripeConfiguration $stripe): int
    {
        $pageUuid = null;

        try {
            $reconciler = (new RuntimeFactory($this->kirby))->checkoutSessionReconciler($stripe);
            $correlation = $reconciler->refundCorrelation($event);

            if ($correlation === null) {
                return self::HTTP_NO_CONTENT;
            }

            $pageUuid = $correlation->pageUuid;
            $reconciler->reconcileRefund($correlation);

            return self::HTTP_NO_CONTENT;
        } catch (Throwable $error) {
            return $this->failure($event, $pageUuid, $this->httpStatusForFailure($error));
        }
    }

    private function httpStatusForFailure(Throwable $error): int
    {
        if ($error instanceof ConfigurationException) {
            return $error->errorCode() === ConfigurationErrorCode::CREDENTIAL_MISSING
                ? self::HTTP_SERVICE_UNAVAILABLE : self::HTTP_INTERNAL_SERVER_ERROR;
        }

        if ($error instanceof CheckoutSessionException) {
            return $error->isRetryable() ? self::HTTP_SERVICE_UNAVAILABLE : self::HTTP_INTERNAL_SERVER_ERROR;
        }

        if ($error instanceof OrderStorageException) {
            $recoverable = in_array($error->errorCode(), [PersistenceErrorCode::BUSY, PersistenceErrorCode::WRITE_FAILED, PersistenceErrorCode::VERIFY_FAILED], true);

            return $recoverable ? self::HTTP_SERVICE_UNAVAILABLE : self::HTTP_INTERNAL_SERVER_ERROR;
        }

        return self::HTTP_INTERNAL_SERVER_ERROR;
    }

    private function failure(Event $event, ?string $pageUuid, int $httpStatus): int
    {
        // A concurrent processor may have committed before this processor failed or lost its response.
        // Acknowledge that durable outcome; Stripe retries every non-2xx response, including 400/500.
        // https://docs.stripe.com/webhooks#automatic-retries
        if ($this->isEventProcessed($event, $pageUuid)) {
            return self::HTTP_NO_CONTENT;
        }

        $errorCode = $httpStatus === self::HTTP_SERVICE_UNAVAILABLE ? WebhookErrorCode::PROCESSING_UNAVAILABLE : WebhookErrorCode::CORRELATION_INVALID;
        error_log('Stripe Checkout: ' . $errorCode);

        return $httpStatus;
    }

    /**
     * Exceptional-path check; the reconciler owns normal duplicate handling.
     * Reload current order evidence through the shared store because another processor may have committed since the initial lookup.
     */
    private function isEventProcessed(Event $event, ?string $pageUuid): bool
    {
        try {
            $page = $pageUuid === null ? null : $this->orders->order($pageUuid);

            if ($page === null) {
                return false;
            }

            $data = $this->orders->data($page);
            $attempt = OrderData::map($data['checkoutAttempt']);
            $mode = CredentialMode::from(OrderData::string($attempt['credentialMode']));
            $type = $event->toArray()['type'] ?? null;

            if (in_array($type, ReconciliationEvent::REFUND_TYPES, true)) {
                $envelope = ReconciliationEvent::refundEnvelope($event, $mode);

                foreach (OrderData::list($data['refunds'] ?? []) as $item) {
                    $refund = RefundSnapshot::fromArray(OrderData::map($item));

                    if ($refund->stripeRefundId() === $envelope->resourceId) {
                        $trigger = ReconciliationEvent::fromRefund($event, $refund, $mode);
                        /** @var list<array<string, mixed>> $entries */
                        $entries = $data['events'] ?? [];

                        return StripeEventLedger::isComplete($entries, $trigger);
                    }
                }

                return false;
            }

            $eventData = $event->toArray()['data'] ?? null;
            $object = is_array($eventData) ? $eventData['object'] ?? null : null;
            $resourceId = is_array($object) ? $object['id'] ?? null : null;
            $sessionId = $data['stripeCheckoutSessionId'] ?? $resourceId;

            if (is_string($sessionId) === false) {
                return false;
            }

            // Reuse the owning boundary before acknowledging exceptional-path evidence.
            // Matching only an Event ID would hide contradictions in a reused ID's time, mode or purchase identity.
            $trigger = ReconciliationEvent::fromStripe($event, OrderSerializer::context($data), $sessionId, $mode);

            if ($trigger->type === Event::PAYMENT_INTENT_REQUIRES_ACTION && $trigger->resourceId !== ($data['stripePaymentIntentId'] ?? null)) {
                return false;
            }

            /** @var list<array<string, mixed>> $entries Validated by the persistence boundary. */
            $entries = $data['events'] ?? [];

            return StripeEventLedger::isComplete($entries, $trigger);
        } catch (Throwable) {
            // Without validated durable evidence, preserve the processing failure for Stripe's retry.
        }

        return false;
    }

    private function response(int $httpStatus, ?string $errorCode = null): Response
    {
        if ($errorCode !== null) {
            error_log('Stripe Checkout: ' . $errorCode);
        }

        return new Response('', code: $httpStatus, headers: self::HEADERS);
    }
}
