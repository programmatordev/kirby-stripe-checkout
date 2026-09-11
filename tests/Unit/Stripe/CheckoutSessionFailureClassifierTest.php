<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Stripe;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailure;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailureType;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Internal\CheckoutSessionFailureClassifier;
use RuntimeException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;

final class CheckoutSessionFailureClassifierTest extends TestCase
{
    public function testClassifiesKnownMutationFailuresWithoutPersistingProviderMessages(): void
    {
        $classifier = new CheckoutSessionFailureClassifier();
        $rateLimit = RateLimitException::factory(
            'PRIVATE rate detail',
            429,
            null,
            ['error' => ['type' => 'rate_limit_error']],
            ['Request-Id' => 'req_rate'],
            'rate_limit',
        );
        $rejected = InvalidRequestException::factory(
            'PRIVATE parameter detail',
            400,
            null,
            ['error' => ['type' => 'invalid_request_error']],
            ['Request-Id' => 'req_rejected'],
            'parameter_invalid_integer',
        );

        $retryable = $classifier->classify($rateLimit, mutation: true);
        $definitive = $classifier->classify($rejected, mutation: true);

        $this->assertSame(CheckoutSessionFailureType::Unavailable, $retryable->type());
        $this->assertTrue($retryable->isRetryable());
        $this->assertSame('req_rate', $retryable->requestId());
        $this->assertSame('rate_limit', $retryable->providerCode());
        $this->assertSame('rate_limit_error', $retryable->providerType());
        $this->assertSame(CheckoutSessionFailureType::Rejected, $definitive->type());
        $this->assertStringNotContainsString('PRIVATE', json_encode([$retryable->toArray(), $definitive->toArray()], JSON_THROW_ON_ERROR));
    }

    public function testConnectionAndUnknownFailuresReflectMutationUncertainty(): void
    {
        $classifier = new CheckoutSessionFailureClassifier();
        $connection = ApiConnectionException::factory('PRIVATE connection detail');

        $this->assertSame(
            CheckoutSessionFailureType::Uncertain,
            $classifier->classify($connection, mutation: true)->type(),
        );
        $this->assertSame(
            CheckoutSessionFailureType::Unavailable,
            $classifier->classify($connection, mutation: false)->type(),
        );
        $this->assertSame(
            CheckoutSessionFailureType::Uncertain,
            $classifier->classify(new RuntimeException('PRIVATE'), mutation: true)->type(),
        );
        $this->assertSame(
            CheckoutSessionFailureType::Unavailable,
            $classifier->classify(new RuntimeException('PRIVATE'), mutation: false)->type(),
        );
    }

    public function testConflictRemainsRetryableAfterStripeClientRetriesAreExhausted(): void
    {
        $conflict = InvalidRequestException::factory(
            'PRIVATE conflict detail',
            409,
            null,
            ['error' => ['type' => 'invalid_request_error']],
            ['Request-Id' => 'req_conflict'],
            'idempotency_error',
        );

        $failure = (new CheckoutSessionFailureClassifier())->classify($conflict, mutation: true);

        $this->assertSame(CheckoutSessionFailureType::Unavailable, $failure->type());
        $this->assertTrue($failure->isRetryable());
    }

    public function testStripeRetryDirectiveOverridesTheStatusFallback(): void
    {
        $retry = InvalidRequestException::factory(
            'PRIVATE transient detail',
            400,
            null,
            ['error' => ['type' => 'invalid_request_error']],
            ['Stripe-Should-Retry' => 'true'],
            'lock_timeout',
        );
        $doNotRetry = InvalidRequestException::factory(
            'PRIVATE conflict detail',
            409,
            null,
            ['error' => ['type' => 'invalid_request_error']],
            ['stripe-should-retry' => 'false'],
            'idempotency_error',
        );
        $classifier = new CheckoutSessionFailureClassifier();
        $retryable = $classifier->classify($retry, mutation: true);
        $rejected = $classifier->classify($doNotRetry, mutation: true);

        $this->assertSame(CheckoutSessionFailureType::Unavailable, $retryable->type());
        $this->assertTrue($retryable->isRetryable());
        $this->assertSame(CheckoutSessionFailureType::Rejected, $rejected->type());
        $this->assertFalse($rejected->isRetryable());
    }

    public function testDoNotRetryDirectivePreservesAnUncertainMutationOutcome(): void
    {
        $failure = InvalidRequestException::factory(
            'PRIVATE server detail',
            500,
            null,
            ['error' => ['type' => 'api_error']],
            ['Stripe-Should-Retry' => 'false'],
            'api_error',
        );

        $classified = (new CheckoutSessionFailureClassifier())->classify($failure, mutation: true);

        $this->assertSame(CheckoutSessionFailureType::Uncertain, $classified->type());
        $this->assertFalse($classified->isRetryable());
    }

    public function testUnknownLocalFailuresAreNotAssumedRetryable(): void
    {
        $failure = (new CheckoutSessionFailureClassifier())->classify(
            new RuntimeException('PRIVATE'),
            mutation: true,
        );

        $this->assertSame(CheckoutSessionFailureType::Uncertain, $failure->type());
        $this->assertFalse($failure->isRetryable());
    }

    public function testDefinitiveOutcomeCannotBeMarkedRetryable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CheckoutSessionFailure(
            type: CheckoutSessionFailureType::Rejected,
            retryable: true,
        );
    }

    public function testUnsafeProviderFactsAreDiscarded(): void
    {
        $classifier = new CheckoutSessionFailureClassifier();
        $failure = InvalidRequestException::factory(
            'PRIVATE',
            400,
            null,
            ['error' => ['type' => "invalid\nrequest"]],
            ['Request-Id' => "req\nunsafe"],
            "code\0unsafe",
        );
        $classified = $classifier->classify($failure, mutation: true);

        $this->assertNull($classified->requestId());
        $this->assertNull($classified->providerCode());
        $this->assertNull($classified->providerType());
    }

    public function testSurroundingWhitespaceAndUnicodeSeparatorsAreDiscarded(): void
    {
        $failure = CheckoutSessionFailure::fromProvider(
            type: CheckoutSessionFailureType::Rejected,
            retryable: false,
            requestId: ' req_space ',
            providerCode: "code\u{2028}unsafe",
            providerType: 'invalid_request_error',
        );

        $this->assertNull($failure->requestId());
        $this->assertNull($failure->providerCode());
        $this->assertSame('invalid_request_error', $failure->providerType());
    }
}
