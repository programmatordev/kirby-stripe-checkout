<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Kirby\PersistenceErrorCode;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\RetentionPolicy;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionGatewayInterface;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Exception\CheckoutSessionGatewayException;

/** @internal Discovers missing Session associations for aged incomplete orders; never creates or deletes anything. */
final readonly class IncompleteOrderRecovery
{
    public function __construct(
        private OrderPageStore $orders,
        private CheckoutSessionGatewayInterface $gateway,
        private CheckoutSessionReconciler $reconciler,
        private RetentionPolicy $retentionPolicy,
        private CredentialMode $credentialMode,
        private string $credentialFingerprint,
    ) {}

    /**
     * Reads at most one discovery page, then reconciles a unique match only when the search is complete.
     *
     * Pass the returned progress to continue; null means the fresh order is not a discovery candidate.
     * To repeat a completed search later, start with null progress.
     *
     * A complete search without a match leaves creation unresolved. Scheduling and progress storage belong to housekeeping.
     */
    public function recover(
        string $pageUuid,
        DateTimeImmutable $checkedAt,
        ?SessionDiscoveryProgress $progress = null,
    ): ?SessionDiscoveryProgress {
        // A webhook may have repaired the association between pages; recheck current eligibility before resuming.
        $orderPage = $this->orders->order($pageUuid) ?? throw new OrderStorageException(PersistenceErrorCode::ORDER_UNAVAILABLE);
        $data = $this->orders->data($orderPage);

        if ($this->retentionPolicy->needsSessionDiscovery($data, $checkedAt) === false) {
            return null;
        }

        $checkoutAttempt = OrderData::map($data['checkoutAttempt']);

        if ($this->credentialMode === CredentialMode::Unknown || $checkoutAttempt['credentialMode'] !== $this->credentialMode->value) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }

        // Creation can finish after the initial local write. Search the whole saved Session lifetime,
        // not just the first request's second or the shorter mutation-retry window.
        // https://docs.stripe.com/api/checkout/sessions/list
        $createdFrom = OrderData::date($data['createdAt'])->getTimestamp();
        $createdBefore = OrderData::date($data['checkoutExpiresAt'])->getTimestamp();
        $progress ??= new SessionDiscoveryProgress(
            pageUuid: $pageUuid,
            credentialFingerprint: $this->credentialFingerprint,
            createdFrom: $createdFrom,
            createdBefore: $createdBefore,
        );

        // A remembered candidate and cursor belong to the same search.
        // Credential rotation requires a fresh search instead of combining observations across credentials.
        if ($progress->pageUuid !== $pageUuid || $progress->credentialFingerprint !== $this->credentialFingerprint) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }

        if ($progress->createdFrom !== $createdFrom || $progress->createdBefore !== $createdBefore) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }

        if ($progress->complete) {
            return $progress;
        }

        try {
            $page = $this->gateway->discoverForOrder(
                pageUuid: $pageUuid,
                createdFrom: $createdFrom,
                createdBefore: $createdBefore,
                startingAfter: $progress->startingAfter,
            );
        } catch (CheckoutSessionGatewayException $error) {
            throw new CheckoutSessionException(
                errorCode: CheckoutErrorCode::forSessionFailure($error->failure()->type()),
                retryable: $error->failure()->isRetryable(),
                previous: $error,
            );
        }

        // Return advanced progress only after any final reconciliation succeeds.
        // On failure the caller retains its previous immutable continuation and can retry that page.
        $progress = $progress->advance($page);

        if ($progress->complete && $progress->candidateSessionId !== null) {
            // Listing supplies only a reference. The existing complete read owns purchase correlation,
            // current-state reduction, concurrent-writer checks and post-commit lifecycle delivery.
            // No Event is invented for discovery, so Stripe Event attempts remain unchanged.
            $this->reconciler->reconcile($pageUuid, $progress->candidateSessionId);
        }

        return $progress;
    }
}
