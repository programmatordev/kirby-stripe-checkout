<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Order;

use Brick\Money\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSerializer;
use ProgrammatorDev\StripeCheckout\Order\Internal\RetentionPolicy;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Test\Support\CheckoutAttemptFactory;

final class RetentionPolicyTest extends TestCase
{
    #[DataProvider('states')]
    public function testOnlyAuthoritativeTerminalUnpaidOrdersBecomeEligible(string $checkout, string $payment, bool $eligible): void
    {
        $policy = $this->policy();
        $data = $this->data($checkout, $payment);
        $this->assertSame($eligible, $policy->isEligible($data, new DateTimeImmutable('2026-12-01T00:00:00Z')));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function states(): iterable
    {
        yield 'creating' => ['creating', 'unpaid', false];
        yield 'uncertain' => ['creation_uncertain', 'unpaid', false];
        yield 'open' => ['open', 'unpaid', false];
        yield 'creation failure' => ['creation_failed', 'unpaid', true];
        yield 'expired' => ['expired', 'unpaid', true];
        yield 'failed' => ['complete', 'failed', true];
        yield 'pending' => ['complete', 'pending', false];
        yield 'paid' => ['complete', 'paid', false];
        yield 'free' => ['complete', 'no_payment_required', false];
    }

    public function testAgeBoundaryUsesWholeUtcDaysAndDisabledCategoriesRemainIndependent(): void
    {
        $failure = $this->data('creation_failed', 'unpaid');
        $expired = $this->data('expired', 'unpaid');
        $this->assertFalse($this->policy()->isEligible($failure, new DateTimeImmutable('2026-09-07T23:59:59Z')));
        $this->assertTrue($this->policy()->isEligible($failure, new DateTimeImmutable('2026-09-08T01:00:00+01:00')));
        $this->assertFalse($this->policy()->isEligible($expired, new DateTimeImmutable('2026-09-30T23:59:59Z')));
        $this->assertTrue($this->policy()->isEligible($expired, new DateTimeImmutable('2026-10-01T00:00:00Z')));
        $now = new DateTimeImmutable('2026-12-01T00:00:00Z');
        $this->assertFalse($this->policy(['cleanupCreationFailures' => false])->isEligible($failure, $now));
        $this->assertTrue($this->policy(['cleanupCreationFailures' => false])->isEligible($expired, $now));
        $this->assertFalse($this->policy(['cleanupUnpaidOrders' => false])->isEligible($expired, $now));
        $this->assertTrue($this->policy(['cleanupUnpaidOrders' => false])->isEligible($failure, $now));
        $this->assertFalse($this->policy(['creationFailureRetentionDays' => PHP_INT_MAX])->isEligible($failure, $now));
    }

    public function testLaterPaymentFailureControlsTheCompletedFailureRetentionClock(): void
    {
        $data = $this->data('complete', 'failed');
        $data['updatedAt'] = $data['paymentFailedAt'] = '2026-09-20T00:00:00Z';
        $this->assertFalse($this->policy()->isEligible($data, new DateTimeImmutable('2026-10-01T00:00:00Z')));
        $this->assertTrue($this->policy()->isEligible($data, new DateTimeImmutable('2026-10-20T00:00:00Z')));
    }

    /** @param array<string, mixed> $settings */
    private function policy(array $settings = []): RetentionPolicy
    {
        return new RetentionPolicy((new ConfigurationResolver())->resolve([
            'programmatordev.stripe-checkout' => ['settings' => $settings],
        ])->configurationOrFail()->settings());
    }

    /** @return array<string, mixed> */
    private function data(string $checkout, string $payment): array
    {
        $price = Money::of('16', 'EUR');
        $product = new Product(new ProductRequest('product', 1), 'Product', false, new Price($price));
        $context = new OrderCreationContext(
            uuid: 'example',
            orderNumber: 'ORD-EXAMPLE',
            checkoutSource: CheckoutSource::Direct,
            cartRevision: null,
            userUuid: null,
            languageCode: null,
            uiMode: UiMode::Hosted,
            currency: 'EUR',
            lineItems: [OrderLineItemSnapshot::fromProduct($product, $price)],
        );
        $createdAt = new DateTimeImmutable('2026-09-01T00:00:00Z');
        $data = OrderSerializer::creation(
            context: $context,
            checkoutAttempt: CheckoutAttemptFactory::create(
                order: $context,
                createdAt: $createdAt,
                guestReference: 'guest',
            ),
            createdAt: $createdAt,
        );
        $data['checkoutStatus'] = $checkout;
        $data['paymentStatus'] = $payment;
        $timestamp = match ($checkout) {
            'creating' => 'createdAt',
            'creation_uncertain' => 'creationUncertainAt',
            'creation_failed' => 'creationFailedAt',
            'open' => 'checkoutOpenedAt',
            'complete' => 'checkoutCompletedAt',
            'expired' => 'checkoutExpiredAt',
            default => throw new \LogicException('Unknown test status.'),
        };
        $data[$timestamp] = $data['createdAt'];

        if (in_array($checkout, ['open', 'complete', 'expired'], true)) {
            $data['stripeCheckoutSessionId'] = 'cs_test';
        }

        if ($checkout === 'complete') {
            $data = [
                ...$data,
                'discountTotal' => '0',
                'customFields' => [],
                'discounts' => [],
                'shippingTotal' => '0',
                'taxTotal' => '0',
                'total' => $payment === 'no_payment_required' ? '0' : '16.00',
            ];
        }

        if (in_array($payment, ['paid', 'no_payment_required'], true)) {
            $data['paidAt'] = $data['createdAt'];
        }

        if ($payment === 'failed') {
            $data['paymentFailedAt'] = $data['createdAt'];
        }

        return $data;
    }
}
