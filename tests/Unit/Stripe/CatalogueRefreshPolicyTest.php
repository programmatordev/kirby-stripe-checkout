<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Stripe;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Stripe\CatalogueRefreshPolicy;

final class CatalogueRefreshPolicyTest extends TestCase
{
    #[DataProvider('refreshStates')]
    public function testUsesCatalogueAgeAndFailureCooldown(
        ?int $refreshAge,
        ?int $failureAge,
        bool $expected,
    ): void {
        $now = time();

        $this->assertSame($expected, CatalogueRefreshPolicy::shouldRefresh(
            refreshedAt: $refreshAge === null ? null : $now - $refreshAge,
            failedAt: $failureAge === null ? null : $now - $failureAge,
            refreshAfterSeconds: 60,
            failureCooldownSeconds: 15,
        ));
    }

    /** @return iterable<string, array{?int, ?int, bool}> */
    public static function refreshStates(): iterable
    {
        yield 'missing catalogue' => [null, null, true];
        yield 'failed first fetch in cooldown' => [null, 0, false];
        yield 'failed first fetch may retry' => [null, 15, true];
        yield 'fresh catalogue' => [0, null, false];
        yield 'refresh threshold reached' => [60, null, true];
        yield 'old catalogue in cooldown' => [120, 0, false];
        yield 'old catalogue may retry' => [120, 15, true];
        yield 'old failure alone does not expire fresh catalogue' => [0, 120, false];
    }
}
