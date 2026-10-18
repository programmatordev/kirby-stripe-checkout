<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\ReconciliationOutcome;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Housekeeping\Exception\HousekeepingException;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingErrorCode;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingRunStatus;
use ProgrammatorDev\StripeCheckout\Kirby\OrderHookDispatcher;
use ProgrammatorDev\StripeCheckout\Kirby\PluginPermissions;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\OrderErrorCode;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use Throwable;

/**
 * Authenticated Panel boundary for explicit operator actions.
 * Domain operations remain responsible for eligibility and mutation rules.
 * Every action maps its operation-specific output into the same response envelope.
 *
 * @internal
 */
final class OperationalRequests
{
    private const HTTP_BAD_REQUEST = 400;
    private const HTTP_CONFLICT = 409;
    private const HTTP_OK = 200;
    private const HTTP_SERVICE_UNAVAILABLE = 503;
    private const STATUS_FAILED = 'failed';
    private const STATUS_SUCCESS = 'success';

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
        ], [
            'pattern' => 'stripe-checkout/housekeeping/run',
            'method' => 'POST',
            'action' => function () use ($kirby): array {
                return OperationalRequests::runHousekeeping($kirby);
            },
        ]];
    }

    /** @return array{code: int, status: string, result: array<string, mixed>|null, error: array{code: string, retryable: bool}|null} */
    public static function syncOrder(App $kirby, string $orderId): array
    {
        PluginPermissions::require($kirby, 'orders.read');
        PluginPermissions::require($kirby, 'orders.reconcile');

        try {
            $pageUuid = OrderData::pageUuid($orderId);
        } catch (OrderDataException) {
            return self::operationalFailure(OrderErrorCode::DATA_INVALID, self::HTTP_BAD_REQUEST);
        }

        try {
            $result = (new RuntimeFactory($kirby))->checkoutSessionReconciler()->syncOrder($pageUuid);
        } catch (ConfigurationException $error) {
            return self::operationalFailure($error->errorCode(), self::HTTP_CONFLICT);
        }

        $outcome = $result->outcome();

        if ($outcome === ReconciliationOutcome::Failed) {
            // Retryability only tells the Panel whether another manual invocation can help.
            // This request is never replayed automatically.
            return self::operationalFailure(
                errorCode: $result->errorCodeOrFail(),
                code: $result->isRetryable() ? self::HTTP_SERVICE_UNAVAILABLE : self::HTTP_CONFLICT,
                retryable: $result->isRetryable(),
            );
        }

        return self::operationalResponse(
            code: self::HTTP_OK,
            status: self::STATUS_SUCCESS,
            result: ['outcome' => $outcome->value],
        );
    }

    /** @return array{code: int, status: string, result: array<string, mixed>|null, error: array{code: string, retryable: bool}|null} */
    public static function retryFailedDelivery(App $kirby, string $orderId, string $deliveryId): array
    {
        PluginPermissions::require($kirby, 'orders.read');
        PluginPermissions::require($kirby, 'lifecycle.retry');

        try {
            $pageUuid = OrderData::pageUuid($orderId);
            $deliveryId = OrderData::text($deliveryId, 255);
        } catch (OrderDataException) {
            return self::operationalFailure(OrderErrorCode::DATA_INVALID, self::HTTP_BAD_REQUEST);
        }

        $result = (new OrderHookDispatcher($kirby))->retryFailed($pageUuid, $deliveryId);
        $errorCode = $result->errorCode();

        if ($errorCode !== null) {
            return self::operationalFailure($errorCode, self::HTTP_CONFLICT);
        }

        return self::operationalResponse(
            code: self::HTTP_OK,
            status: self::STATUS_SUCCESS,
            result: ['delivered' => true],
        );
    }

    /** @return array{code: int, status: string, result: array<string, mixed>|null, error: array{code: string, retryable: bool}|null} */
    public static function deleteOrder(App $kirby, string $orderId): array
    {
        PluginPermissions::require($kirby, 'orders.read');
        PluginPermissions::require($kirby, 'orders.delete');

        try {
            $pageUuid = OrderData::pageUuid($orderId);
        } catch (OrderDataException) {
            return self::operationalFailure(OrderErrorCode::DATA_INVALID, self::HTTP_BAD_REQUEST);
        }

        try {
            $deleted = (new RuntimeFactory($kirby))->orderPageStore()->deleteManually($pageUuid);
        } catch (OrderStorageException $error) {
            return self::operationalFailure($error->errorCode(), self::HTTP_CONFLICT);
        }

        if ($deleted === false) {
            // The store has reloaded and rejected the order under its write lock.
            // No earlier Panel observation can override that decision.
            return self::operationalFailure(OrderErrorCode::DELETION_NOT_ALLOWED, self::HTTP_CONFLICT);
        }

        return self::operationalResponse(
            code: self::HTTP_OK,
            status: self::STATUS_SUCCESS,
            result: ['deleted' => true],
        );
    }

    /** @return array{code: int, status: string, result: array<string, mixed>|null, error: array{code: string, retryable: bool}|null} */
    public static function runHousekeeping(App $kirby): array
    {
        PluginPermissions::require($kirby, 'housekeeping.run');

        try {
            $outcome = (new RuntimeFactory($kirby))->housekeepingRunner()->run();
        } catch (ConfigurationException $error) {
            return self::operationalFailure($error->errorCode(), self::HTTP_CONFLICT);
        } catch (HousekeepingException $error) {
            $code = $error->errorCode() === HousekeepingErrorCode::LOCKED
                ? self::HTTP_CONFLICT
                : self::HTTP_SERVICE_UNAVAILABLE;

            return self::operationalFailure(
                errorCode: $error->errorCode(),
                code: $code,
                retryable: $error->errorCode() === HousekeepingErrorCode::LOCKED,
            );
        } catch (Throwable) {
            error_log('Stripe Checkout: ' . HousekeepingErrorCode::RUN_FAILED);

            return self::operationalFailure(HousekeepingErrorCode::RUN_FAILED, self::HTTP_SERVICE_UNAVAILABLE);
        }

        $result = $outcome->toArray();
        $errorCode = $result['errorCode'];
        // Promote the runner's control fields into the shared HTTP envelope;
        // only its sanitized timestamps and counters remain operation-specific.
        unset($result['status'], $result['errorCode']);

        return self::operationalResponse(
            code: $outcome->status() === HousekeepingRunStatus::Failed
                ? self::HTTP_SERVICE_UNAVAILABLE
                : self::HTTP_OK,
            status: $outcome->status()->value,
            result: $result,
            error: is_string($errorCode) ? self::operationalError($errorCode) : null,
        );
    }

    /** @return array{code: int, status: string, result: null, error: array{code: string, retryable: bool}} */
    private static function operationalFailure(string $errorCode, int $code, bool $retryable = false): array
    {
        return [
            'code' => $code,
            'status' => self::STATUS_FAILED,
            'result' => null,
            'error' => self::operationalError($errorCode, $retryable),
        ];
    }

    /**
     * @param array<string, mixed>                       $result
     * @param array{code: string, retryable: bool}|null $error
     * @return array{code: int, status: string, result: array<string, mixed>, error: array{code: string, retryable: bool}|null}
     */
    private static function operationalResponse(
        int $code,
        string $status,
        array $result,
        ?array $error = null,
    ): array {
        return [
            'code' => $code,
            'status' => $status,
            'result' => $result,
            'error' => $error,
        ];
    }

    /** @return array{code: string, retryable: bool} */
    private static function operationalError(string $errorCode, bool $retryable = false): array
    {
        return [
            'code' => $errorCode,
            'retryable' => $retryable,
        ];
    }
}
