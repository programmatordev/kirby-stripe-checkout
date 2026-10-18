<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\ReconciliationOutcome;
use ProgrammatorDev\StripeCheckout\Kirby\PluginPermissions;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
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
        return [[
            'pattern' => 'stripe-checkout/orders/(:any)/sync',
            'method' => 'POST',
            // Kirby rebinds Panel route closures to the matched Route object.
            'action' => function (string $orderId) use ($kirby): array {
                return OperationalRequests::syncOrder($kirby, $orderId);
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
            return self::failure(OrderErrorCode::DATA_INVALID, self::HTTP_BAD_REQUEST);
        }

        $result = (new RuntimeFactory($kirby))->checkoutSessionReconciler()->syncOrder($pageUuid);
        $outcome = $result->outcome();

        if ($outcome === ReconciliationOutcome::Failed) {
            // Retryability only tells the Panel whether another manual invocation can help.
            // This request is never replayed automatically.
            return self::failure(
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

    /** @return array{code: int, outcome: string, errorCode: string, retryable: bool} */
    private static function failure(string $errorCode, int $httpStatus, bool $retryable = false): array
    {
        return [
            'code' => $httpStatus,
            'outcome' => ReconciliationOutcome::Failed->value,
            'errorCode' => $errorCode,
            'retryable' => $retryable,
        ];
    }
}
