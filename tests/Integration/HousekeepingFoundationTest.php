<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use DateTimeImmutable;
use InvalidArgumentException;
use Kirby\Cms\Page;
use Kirby\Data\Json;
use Kirby\Filesystem\F;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testRemovesDiscoveryProgressByOrderAcrossCredentialRotation(): void
    {
        $progress = new SessionDiscoveryProgress(
            pageUuid: 'page://order-one',
            credentialFingerprint: 'old-credential',
            createdFrom: 100,
            createdBefore: 200,
        );
        $state = (new HousekeepingState())
            ->withProgress($progress)
            ->withoutProgressForOrder('page://order-one');

        $this->assertSame([], $state->discoveryProgress());
    }

    public function testRejectsPersistedDiscoveryProgressForMultipleCredentialsOfOneOrder(): void
    {
        $progress = new SessionDiscoveryProgress(
            pageUuid: 'page://order-one',
            credentialFingerprint: 'first-credential',
            createdFrom: 100,
            createdBefore: 200,
        );
        $rotatedProgress = new SessionDiscoveryProgress(
            pageUuid: 'page://order-one',
            credentialFingerprint: 'second-credential',
            createdFrom: 100,
            createdBefore: 200,
        );

        $this->expectException(InvalidArgumentException::class);

        HousekeepingState::fromArray([
            'schemaVersion' => 1,
            'lastOrderPageUuid' => null,
            'discoveryProgress' => [$progress->toArray(), $rotatedProgress->toArray()],
            'lastAttempt' => null,
            'lastSuccess' => null,
        ]);
    }

    #[DataProvider('invalidRunOutcomeProvider')]
    public function testRejectsContradictoryRunOutcome(
        int $processedOrders,
        int $deletedOrders,
        int $recoveredOrders,
        int $failedOrders,
    ): void {
        $attemptedAt = new DateTimeImmutable('2026-10-18T10:00:00Z');

        $this->expectException(InvalidArgumentException::class);

        new HousekeepingRunOutcome(
            attemptedAt: $attemptedAt,
            completedAt: $attemptedAt,
            status: HousekeepingRunStatus::Success,
            selectedOrders: 1,
            processedOrders: $processedOrders,
            prunedDeliveries: 0,
            deletedOrders: $deletedOrders,
            recoveredOrders: $recoveredOrders,
            failedOrders: $failedOrders,
            errorCode: null,
        );
    }

    /** @return iterable<string, array{int, int, int, int}> */
    public static function invalidRunOutcomeProvider(): iterable
    {
        yield 'deleted orders exceed processed orders' => [1, 2, 0, 0];
        yield 'recovered orders exceed processed orders' => [1, 0, 2, 0];
        yield 'successful outcome contains a failed order' => [1, 0, 0, 1];
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

    public function testStateReadWaitsForAnActiveWriteLock(): void
    {
        $store = new HousekeepingStateStore($this->kirby);
        $store->write((new HousekeepingState())->withLastOrderPageUuid('page://order-one'));
        $path = $this->kirby->root('site') . '/storage/stripe-checkout/housekeeping.json';
        $handle = fopen($path, 'c+');
        $this->assertIsResource($handle);
        $process = null;
        $pipes = [];

        try {
            $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/Housekeeping/concurrent-read.php'], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);
            $this->assertIsResource($process);
            fwrite($pipes[0], json_encode(['roots' => $this->environment->workspace()->roots()], JSON_THROW_ON_ERROR) . "\n");
            $this->assertSame("ready\n", $this->readWorkerLine($pipes[1]));
            $this->assertTrue(flock($handle, LOCK_EX));
            fwrite($pipes[0], "go\n");
            $read = [$pipes[1]];
            $write = $except = [];
            // A reader must not observe the interval in which F::update() truncates and rewrites the state file.
            $this->assertSame(0, stream_select($read, $write, $except, 0, 100000));
            $replacement = Json::encode(
                (new HousekeepingState())->withLastOrderPageUuid('page://order-two')->toArray(),
                true,
            );
            $this->assertTrue(ftruncate($handle, 0));
            $this->assertTrue(rewind($handle));
            $this->assertSame(strlen($replacement), fwrite($handle, $replacement));
            $this->assertTrue(fflush($handle));
            flock($handle, LOCK_UN);
            $this->assertSame("page://order-two\n", $this->readWorkerLine($pipes[1]));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);

            if (is_resource($process)) {
                proc_terminate($process);

                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }

                proc_close($process);
            }
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

    /** @param resource $pipe */
    private function readWorkerLine($pipe): string|false
    {
        $read = [$pipe];
        $write = $except = [];
        $this->assertSame(1, stream_select($read, $write, $except, 5), 'The housekeeping test worker timed out.');

        return fgets($pipe);
    }
}
