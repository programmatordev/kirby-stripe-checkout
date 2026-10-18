<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use DateTimeImmutable;
use Kirby\Cms\Page;
use Kirby\Filesystem\F;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionDiscoveryProgress;
use ProgrammatorDev\StripeCheckout\Housekeeping\Exception\HousekeepingException;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingErrorCode;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingRunOutcome;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingRunStatus;
use ProgrammatorDev\StripeCheckout\Housekeeping\Internal\HousekeepingRunLock;
use ProgrammatorDev\StripeCheckout\Housekeeping\Internal\HousekeepingState;
use ProgrammatorDev\StripeCheckout\Housekeeping\Internal\HousekeepingStateStore;
use ProgrammatorDev\StripeCheckout\Housekeeping\Internal\OrderReferenceSelector;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;

final class HousekeepingFoundationTest extends KirbyTestCase
{
    public function testSelectsAStableBoundedReferenceBatchWithoutValidatingOrderContent(): void
    {
        $store = new OrderPageStore($this->kirby);
        $container = $store->initialize();

        foreach (['charlie', 'alpha', 'bravo'] as $slug) {
            $this->kirby->impersonate('kirby', fn(): Page => Page::create([
                'parent' => $container,
                'site' => $this->kirby->site(),
                'slug' => $slug,
                'template' => 'default',
                'isDraft' => true,
                'content' => ['title' => $slug],
            ]));
        }

        $selector = new OrderReferenceSelector($store);
        $first = $selector->select(afterPageUuid: null, limit: 2);
        $this->assertSame(['page://alpha', 'page://bravo'], $first->pageUuids());
        $this->assertSame('page://bravo', $first->continueAfterPageUuid());

        $second = $selector->select(afterPageUuid: $first->continueAfterPageUuid(), limit: 2);
        $this->assertSame(['page://charlie'], $second->pageUuids());
        $this->assertNull($second->continueAfterPageUuid());
    }

    public function testStateRoundTripsContinuationAndSanitizedOutcomes(): void
    {
        $progress = new SessionDiscoveryProgress(
            pageUuid: 'page://order-one',
            credentialFingerprint: 'credential-one',
            createdFrom: 100,
            createdBefore: 200,
            startingAfter: 'session-one',
            candidateSessionId: 'session-candidate',
        );
        $attemptedAt = new DateTimeImmutable('2026-10-18T10:00:00Z');
        $outcome = new HousekeepingRunOutcome(
            attemptedAt: $attemptedAt,
            completedAt: $attemptedAt->modify('+2 seconds'),
            status: HousekeepingRunStatus::Success,
            selectedOrders: 1,
            processedOrders: 1,
            prunedDeliveries: 2,
            deletedOrders: 0,
            recoveredOrders: 1,
            failedOrders: 0,
            errorCode: null,
        );
        $failedOutcome = new HousekeepingRunOutcome(
            attemptedAt: $attemptedAt->modify('+3 seconds'),
            completedAt: $attemptedAt->modify('+4 seconds'),
            status: HousekeepingRunStatus::Failed,
            selectedOrders: 0,
            processedOrders: 0,
            prunedDeliveries: 0,
            deletedOrders: 0,
            recoveredOrders: 0,
            failedOrders: 0,
            errorCode: HousekeepingErrorCode::WRITE_FAILED,
        );
        $oldCredentialProgress = new SessionDiscoveryProgress(
            pageUuid: 'page://order-one',
            credentialFingerprint: 'old-credential',
            createdFrom: 100,
            createdBefore: 200,
        );
        $state = (new HousekeepingState())
            ->withLastOrderPageUuid('page://order-one')
            ->withProgress($oldCredentialProgress)
            ->withProgress($progress)
            ->withOutcome($outcome)
            ->withOutcome($failedOutcome);
        $store = new HousekeepingStateStore($this->kirby);
        $store->write($state);
        $restored = $store->read();

        $this->assertSame('page://order-one', $restored->lastOrderPageUuid());
        $this->assertCount(1, $restored->discoveryProgress());
        $this->assertNull($restored->progress('page://order-one', 'old-credential'));
        $this->assertSame($progress->toArray(), $restored->progress('page://order-one', 'credential-one')?->toArray());
        $this->assertSame($failedOutcome->toArray(), $restored->lastAttempt()?->toArray());
        $this->assertSame($outcome->toArray(), $restored->lastSuccess()?->toArray());
    }

    public function testRejectsCorruptPersistedStateWithoutReplacingIt(): void
    {
        $path = $this->kirby->root('site') . '/storage/stripe-checkout/housekeeping.json';
        F::write($path, '{invalid');
        $store = new HousekeepingStateStore($this->kirby);

        try {
            $store->read();
            $this->fail('Expected invalid housekeeping state.');
        } catch (HousekeepingException $error) {
            $this->assertSame(HousekeepingErrorCode::STATE_INVALID, $error->errorCode());
            $this->assertSame('{invalid', F::read($path));
        }
    }

    public function testRejectsAnOverlappingRunWithoutWaiting(): void
    {
        $errorCode = HousekeepingRunLock::run($this->kirby, function (): string {
            try {
                HousekeepingRunLock::run($this->kirby, static fn(): null => null);
            } catch (HousekeepingException $error) {
                return $error->errorCode();
            }

            return 'missing-error';
        });

        $this->assertSame(HousekeepingErrorCode::LOCKED, $errorCode);
        $available = false;
        HousekeepingRunLock::run($this->kirby, static function () use (&$available): void {
            $available = true;
        });
        $this->assertTrue($available);
    }

    public function testRejectsAnExternallyHeldRunLock(): void
    {
        $path = $this->kirby->root('site') . '/storage/stripe-checkout/housekeeping.lock';
        HousekeepingRunLock::run($this->kirby, static fn(): null => null);
        $handle = fopen($path, 'c');
        $this->assertIsResource($handle);

        try {
            $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));

            try {
                HousekeepingRunLock::run($this->kirby, static fn(): null => null);
                $this->fail('Expected an overlapping housekeeping lock.');
            } catch (HousekeepingException $error) {
                $this->assertSame(HousekeepingErrorCode::LOCKED, $error->errorCode());
            }
        } finally {
            fclose($handle);
        }
    }
}
