<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Stripe;

use PHPUnit\Framework\TestCase;
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

        $this->assertSame(CheckoutSessionFailureType::Retryable, $retryable->type());
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
}
