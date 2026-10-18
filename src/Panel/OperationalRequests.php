<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\ReconciliationOutcome;
use ProgrammatorDev\StripeCheckout\Kirby\OrderHookDispatcher;
use ProgrammatorDev\StripeCheckout\Kirby\PluginPermissions;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\OrderErrorCode;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;

/**
 * Authenticated Panel boundary for explicit operator actions.
 * Domain operations remain responsible for eligibility and mutation rules.
 *
 * @internal
 */
final class OperationalRequests
{
    private const HTTP_BAD_REQUEST = 400;
    private const HTTP_CONFLICT = 409;
    private const HTTP_OK = 200;
    private const HTTP_SERVICE_UNAVAILABLE = 503;

    /** @return list<array<string, mixed>> */
    public static function definition(App $kirby): array
    {
        // Kirby rebinds Panel route closures to the matched Route object.
        return [[
            'pattern' => 'stripe-checkout/orders/(:any)/sync',
            'method' => 'POST',
            'action' => function (string $orderId) use ($kirby): array {
                return OperationalRequests::syncOrder($kirby, $orderId);
            },
        ], [
            'pattern' => 'stripe-checkout/orders/(:any)/deliveries/(:any)/retry',
            'method' => 'POST',
            'action' => function (string $orderId, string $deliveryId) use ($kirby): array {
                return OperationalRequests::retryFailedDelivery($kirby, $orderId, $deliveryId);
            },
        ], [
            'pattern' => 'stripe-checkout/orders/(:any)',
            'method' => 'DELETE',
            'action' => function (string $orderId) use ($kirby): array {
                return OperationalRequests::deleteOrder($kirby, $orderId);
            },
        ]];
    }

    /** @return array{code: int, outcome: string, errorCode: string|null, retryable: bool} */
    public static function syncOrder(App $kirby, string $orderId): array
    {
        PluginPermissions::require($kirby, 'orders.read');
        PluginPermissions::require($kirby, 'orders.reconcile');

        try {
            $pageUuid = OrderData::pageUuid($orderId);
        } catch (OrderDataException) {
            return self::reconciliationFailure(OrderErrorCode::DATA_INVALID, self::HTTP_BAD_REQUEST);
        }

        $result = (new RuntimeFactory($kirby))->checkoutSessionReconciler()->syncOrder($pageUuid);
        $outcome = $result->outcome();

        if ($outcome === ReconciliationOutcome::Failed) {
            // Retryability only tells the Panel whether another manual invocation can help.
            // This request is never replayed automatically.
            return self::reconciliationFailure(
                errorCode: $result->errorCodeOrFail(),
                httpStatus: $result->isRetryable() ? self::HTTP_SERVICE_UNAVAILABLE : self::HTTP_CONFLICT,
                retryable: $result->isRetryable(),
            );
        }

        return [
            'code' => self::HTTP_OK,
            'outcome' => $outcome->value,
            'errorCode' => null,
            'retryable' => false,
        ];
    }

    /** @return array{code: int, delivered: bool, errorCode: string|null} */
    public static function retryFailedDelivery(App $kirby, string $orderId, string $deliveryId): array
    {
        PluginPermissions::require($kirby, 'orders.read');
        PluginPermissions::require($kirby, 'lifecycle.retry');

        try {
            $pageUuid = OrderData::pageUuid($orderId);
            $deliveryId = OrderData::text($deliveryId, 255);
        } catch (OrderDataException) {
            return self::deliveryFailure(OrderErrorCode::DATA_INVALID, self::HTTP_BAD_REQUEST);
        }

        $result = (new OrderHookDispatcher($kirby))->retryFailed($pageUuid, $deliveryId);
        $errorCode = $result->errorCode();

        if ($errorCode !== null) {
            return self::deliveryFailure($errorCode, self::HTTP_CONFLICT);
        }

        return [
            'code' => self::HTTP_OK,
            'delivered' => true,
            'errorCode' => null,
        ];
    }

    /** @return array{code: int, deleted: bool, errorCode: string|null} */
    public static function deleteOrder(App $kirby, string $orderId): array
    {
        PluginPermissions::require($kirby, 'orders.read');
        PluginPermissions::require($kirby, 'orders.delete');

        try {
            $pageUuid = OrderData::pageUuid($orderId);
        } catch (OrderDataException) {
            return self::deletionFailure(OrderErrorCode::DATA_INVALID, self::HTTP_BAD_REQUEST);
        }

        try {
            $deleted = (new RuntimeFactory($kirby))->orderPageStore()->deleteManually($pageUuid);
        } catch (OrderStorageException $error) {
            return self::deletionFailure($error->errorCode(), self::HTTP_CONFLICT);
        }

        if ($deleted === false) {
            // The store has reloaded and rejected the order under its write lock.
            // No earlier Panel observation can override that decision.
            return self::deletionFailure(OrderErrorCode::DELETION_NOT_ALLOWED, self::HTTP_CONFLICT);
        }

        return [
            'code' => self::HTTP_OK,
            'deleted' => true,
            'errorCode' => null,
        ];
    }

    /** @return array{code: int, outcome: string, errorCode: string, retryable: bool} */
    private static function reconciliationFailure(string $errorCode, int $httpStatus, bool $retryable = false): array
    {
        return [
            'code' => $httpStatus,
            'outcome' => ReconciliationOutcome::Failed->value,
            'errorCode' => $errorCode,
            'retryable' => $retryable,
        ];
    }

    /** @return array{code: int, delivered: false, errorCode: string} */
    private static function deliveryFailure(string $errorCode, int $httpStatus): array
    {
        return [
            'code' => $httpStatus,
            'delivered' => false,
            'errorCode' => $errorCode,
        ];
    }

    /** @return array{code: int, deleted: false, errorCode: string} */
    private static function deletionFailure(string $errorCode, int $httpStatus): array
    {
        return [
            'code' => $httpStatus,
            'deleted' => false,
            'errorCode' => $errorCode,
        ];
    }
}
