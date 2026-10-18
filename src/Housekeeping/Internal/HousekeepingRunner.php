<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Housekeeping\Internal;

use DateTimeImmutable;
use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSessionReconciler;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\IncompleteOrderRecovery;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionDiscoveryProgress;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingErrorCode;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingRunOutcome;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingRunStatus;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Kirby\PersistenceErrorCode;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderQueryException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\RetentionPolicy;

/** @internal Runs one non-overlapping housekeeping batch and persists its continuation. */
final readonly class HousekeepingRunner
{
    public function __construct(
        private App $kirby,
        private OrderPageStore $orders,
        private OrderReferenceSelector $orderReferenceSelector,
        private HousekeepingStateStore $stateStore,
        private RetentionPolicy $retentionPolicy,
        private IncompleteOrderRecovery $incompleteOrderRecovery,
        private CheckoutSessionReconciler $checkoutSessionReconciler,
        private int $batchSize,
        private string $credentialFingerprint,
    ) {}

    public function run(): HousekeepingRunOutcome
    {
        $attemptedAt = new DateTimeImmutable();

        return HousekeepingRunLock::run(
            $this->kirby,
            fn(): HousekeepingRunOutcome => $this->runLocked($attemptedAt),
        );
    }

    private function runLocked(DateTimeImmutable $attemptedAt): HousekeepingRunOutcome
    {
        $state = $this->stateStore->read();

        try {
            $batch = $this->orderReferenceSelector->select(
                afterPageUuid: $state->continueAfterPageUuid(),
                limit: $this->batchSize,
            );
        } catch (OrderStorageException|OrderQueryException) {
            return $this->finishFailedRun($state, $attemptedAt);
        }

        $pageUuids = $batch->pageUuids();
        $processedOrders = 0;
        $prunedDeliveries = 0;
        $deletedOrders = 0;
        $recoveredOrders = 0;
        $failedOrders = 0;

        foreach ($pageUuids as $pageUuid) {
            $processedOrders++;
            $orderFailed = false;

            try {
                $prunedDeliveries += $this->orders->pruneLifecycleDeliveryPayloads($pageUuid, $attemptedAt);
            } catch (OrderStorageException $error) {
                if ($error->errorCode() === PersistenceErrorCode::ORDER_UNAVAILABLE) {
                    $state = $state->withoutProgressForOrder($pageUuid);
                } else {
                    $orderFailed = true;
                }
            } catch (OrderDataException|OrderQueryException) {
                $orderFailed = true;
            }

            try {
                $state = $this->recoverOrder(
                    pageUuid: $pageUuid,
                    checkedAt: $attemptedAt,
                    state: $state,
                    recoveredOrders: $recoveredOrders,
                );
            } catch (OrderStorageException $error) {
                if ($error->errorCode() === PersistenceErrorCode::ORDER_UNAVAILABLE) {
                    $state = $state->withoutProgressForOrder($pageUuid);
                } else {
                    $orderFailed = true;
                }
            } catch (CheckoutSessionException|OrderDataException|OrderQueryException) {
                $orderFailed = true;
            }

            // A provider failure cannot erase already-confirmed terminal facts.
            // Deletion remains independent and rechecks those facts under the order lock.
            try {
                if ($this->orders->deleteEligible($pageUuid, $this->retentionPolicy, $attemptedAt)) {
                    $deletedOrders++;
                    $state = $state->withoutProgressForOrder($pageUuid);
                }
            } catch (OrderStorageException $error) {
                if ($error->errorCode() === PersistenceErrorCode::ORDER_UNAVAILABLE) {
                    $state = $state->withoutProgressForOrder($pageUuid);
                } else {
                    $orderFailed = true;
                }
            } catch (OrderDataException|OrderQueryException) {
                $orderFailed = true;
            }

            if ($orderFailed) {
                $failedOrders++;
            }

            // Persist after each selected order so a terminated process resumes after its last completed candidate.
            $state = $state->withContinueAfterPageUuid($pageUuid);
            $this->stateStore->write($state);
        }

        $status = $failedOrders === 0
            ? HousekeepingRunStatus::Success
            : HousekeepingRunStatus::PartialFailure;
        $outcome = new HousekeepingRunOutcome(
            attemptedAt: $attemptedAt,
            completedAt: new DateTimeImmutable(),
            status: $status,
            selectedOrders: count($pageUuids),
            processedOrders: $processedOrders,
            prunedDeliveries: $prunedDeliveries,
            deletedOrders: $deletedOrders,
            recoveredOrders: $recoveredOrders,
            failedOrders: $failedOrders,
            errorCode: $status === HousekeepingRunStatus::Success ? null : HousekeepingErrorCode::PARTIAL_FAILURE,
        );
        $state = $state
            ->withContinueAfterPageUuid($batch->continueAfterPageUuid())
            ->withOutcome($outcome);
        $this->stateStore->write($state);

        return $outcome;
    }

    private function recoverOrder(
        string $pageUuid,
        DateTimeImmutable $checkedAt,
        HousekeepingState $state,
        int &$recoveredOrders,
    ): HousekeepingState {
        $orderPage = $this->orders->order($pageUuid);

        if ($orderPage === null) {
            return $state->withoutProgressForOrder($pageUuid);
        }

        $data = $this->orders->data($orderPage);

        if ($this->retentionPolicy->needsCheckoutRecovery($data, $checkedAt) === false) {
            return $state->withoutProgressForOrder($pageUuid);
        }

        $sessionId = isset($data['stripeCheckoutSessionId'])
            ? OrderData::string($data['stripeCheckoutSessionId'])
            : null;
        // Missing associations advance at most one persisted discovery page;
        // known Sessions can use the direct authoritative read immediately.
        $progress = $sessionId === null
            ? $this->incompleteOrderRecovery->recover(
                pageUuid: $pageUuid,
                checkedAt: $checkedAt,
                progress: $state->progress($pageUuid, $this->credentialFingerprint),
            )
            : null;

        if ($sessionId !== null) {
            $this->checkoutSessionReconciler->reconcile($pageUuid, $sessionId);
        }

        $recoveredPage = $this->orders->order($pageUuid);

        if ($recoveredPage === null) {
            return $state->withoutProgressForOrder($pageUuid);
        }

        $recoveredData = $this->orders->data($recoveredPage);
        $associationRecovered = $sessionId === null && isset($recoveredData['stripeCheckoutSessionId']);
        $knownSessionResolved = $sessionId !== null && $this->retentionPolicy->needsCheckoutRecovery($recoveredData, $checkedAt) === false;

        if ($associationRecovered || $knownSessionResolved) {
            $recoveredOrders++;

            return $state->withoutProgressForOrder($pageUuid);
        }

        return $progress instanceof SessionDiscoveryProgress
            ? $state->withProgress($progress)
            : $state->withoutProgressForOrder($pageUuid);
    }

    private function finishFailedRun(HousekeepingState $state, DateTimeImmutable $attemptedAt): HousekeepingRunOutcome
    {
        $outcome = new HousekeepingRunOutcome(
            attemptedAt: $attemptedAt,
            completedAt: new DateTimeImmutable(),
            status: HousekeepingRunStatus::Failed,
            selectedOrders: 0,
            processedOrders: 0,
            prunedDeliveries: 0,
            deletedOrders: 0,
            recoveredOrders: 0,
            failedOrders: 0,
            errorCode: HousekeepingErrorCode::RUN_FAILED,
        );
        $this->stateStore->write($state->withOutcome($outcome));

        return $outcome;
    }
}
