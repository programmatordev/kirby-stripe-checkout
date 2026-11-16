<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutFailureMapper;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;

final class CheckoutFailureMapperTest extends KirbyTestCase
{
    #[DataProvider('sessionFailures')]
    public function testMapsSessionFailuresToTheirHttpContract(
        string $errorCode,
        bool $retryable,
        int $httpStatus,
        bool $requiresExistingAction,
    ): void {
        $failure = (new CheckoutFailureMapper($this->kirby))->map(new CheckoutSessionException(
            errorCode: $errorCode,
            retryable: $retryable,
        ));

        $this->assertSame($errorCode, $failure->error()->code());
        $this->assertSame($retryable, $failure->error()->retryable());
        $this->assertSame($httpStatus, $failure->httpStatus());
        $this->assertSame($requiresExistingAction, $failure->requiresExistingAction());
    }

    /** @return iterable<string, array{string, bool, int, bool}> */
    public static function sessionFailures(): iterable
    {
        yield 'rejected' => [CheckoutErrorCode::SESSION_REJECTED, false, 502, false];
        yield 'unavailable without retry' => [CheckoutErrorCode::SESSION_UNAVAILABLE, false, 503, true];
        yield 'unavailable with retry' => [CheckoutErrorCode::SESSION_UNAVAILABLE, true, 503, true];
        yield 'uncertain without retry' => [CheckoutErrorCode::SESSION_UNCERTAIN, false, 202, true];
        yield 'uncertain with retry' => [CheckoutErrorCode::SESSION_UNCERTAIN, true, 202, true];
    }
}
