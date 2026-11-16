<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Checkout;

use Brick\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\RefundRetriever;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\CheckoutDiscountsSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\CheckoutSessionAssociation;
use ProgrammatorDev\StripeCheckout\Order\Internal\CheckoutSessionSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\CheckoutShippingSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\PaymentSnapshot;
use ProgrammatorDev\StripeCheckout\Plugin\PluginMetadata;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionObservation;
use ProgrammatorDev\StripeCheckout\Stripe\Refund\RefundGatewayInterface;
use Stripe\Event;
use Stripe\PaymentIntent;
use Stripe\Refund;

final class RefundRetrieverTest extends TestCase
{
    #[DataProvider('refundEventTypes')]
    public function testCorrelatesSupportedEventsThroughAuthoritativePaymentIntentOwnership(string $eventType): void
    {
        $refund = $this->refund();
        $correlation = (new RefundRetriever($this->gateway($refund)))->correlate(
            $this->event($refund, $eventType),
            CredentialMode::Test,
        );

        $this->assertNotNull($correlation);
        $this->assertSame('page://Abc123def456GHI7', $correlation->pageUuid);
        $this->assertSame('re_test', $correlation->refund->stripeRefundId());
        $this->assertSame('pi_test', $correlation->refund->stripePaymentIntentId());
        $this->assertSame($eventType, $correlation->trigger->type);
        $this->assertSame('evt_refund', $correlation->trigger->id);
    }

    /** @return iterable<string, array{string}> */
    public static function refundEventTypes(): iterable
    {
        yield 'created' => [Event::REFUND_CREATED];
        yield 'updated' => [Event::REFUND_UPDATED];
        yield 'failed' => [Event::REFUND_FAILED];
    }

    public function testUsesTheChargeBacklinkWhenTheRefundOmitsItsPaymentIntent(): void
    {
        $refund = $this->refund(paymentIntentId: null, chargeId: 'ch_older');
        $charge = $this->charge(id: 'ch_older');
        $correlation = (new RefundRetriever($this->gateway($refund, charge: $charge)))->correlate(
            $this->event($refund),
            CredentialMode::Test,
        );

        $this->assertNotNull($correlation);
        $this->assertSame('pi_test', $correlation->refund->stripePaymentIntentId());
        $this->assertSame('ch_older', $correlation->refund->stripeChargeId());
        $this->assertSame('ch_older', $correlation->trigger->stripeChargeId);
    }

    public function testIgnoresRefundMetadataWhenTheCurrentPaymentIntentIsNotOwned(): void
    {
        $refund = $this->refund();
        $refund['metadata'] = [
            PluginMetadata::OWNER_KEY => PluginMetadata::NAME,
            PluginMetadata::ORDER_KEY => 'page://Abc123def456GHI7',
        ];
        $paymentIntent = $this->paymentIntent();
        $paymentIntent['metadata'] = [];

        $this->assertNull((new RefundRetriever($this->gateway($refund, $paymentIntent)))->correlate(
            $this->event($refund),
            CredentialMode::Test,
        ));
    }

    #[DataProvider('contradictoryParents')]
    public function testRejectsContradictoryParentFacts(string $resource, string $field, mixed $value): void
    {
        $refund = $this->refund(paymentIntentId: null, chargeId: 'ch_test');
        $paymentIntent = $this->paymentIntent();
        $charge = $this->charge();

        if ($resource === 'paymentIntent') {
            $paymentIntent[$field] = $value;
        } else {
            $charge[$field] = $value;
        }

        $this->expectException(OrderDataException::class);
        (new RefundRetriever($this->gateway($refund, $paymentIntent, $charge)))->correlate(
            $this->event($refund),
            CredentialMode::Test,
        );
    }

    /** @return iterable<string, array{string, string, mixed}> */
    public static function contradictoryParents(): iterable
    {
        yield 'PaymentIntent identity' => ['paymentIntent', 'id', 'pi_other'];
        yield 'PaymentIntent currency' => ['paymentIntent', 'currency', 'usd'];
        yield 'PaymentIntent mode' => ['paymentIntent', 'livemode', true];
        yield 'Charge identity' => ['charge', 'id', 'ch_other'];
        yield 'Charge PaymentIntent' => ['charge', 'payment_intent', 'pi_other'];
        yield 'Charge currency' => ['charge', 'currency', 'usd'];
        yield 'Charge mode' => ['charge', 'livemode', true];
    }

    public function testUsesCurrentCollectionFactsInsteadOfTheHistoricalEventStatus(): void
    {
        $historical = $this->refund(status: Refund::STATUS_PENDING, amount: 3200);
        $current = $this->refund(status: Refund::STATUS_SUCCEEDED, amount: 3200);
        $retriever = new RefundRetriever($this->gateway($historical, refunds: [$current]));
        $correlation = $retriever->correlate($this->event($historical), CredentialMode::Test);
        $this->assertNotNull($correlation);

        $collection = $retriever->retrieve($correlation, $this->observation());
        $this->assertSame('full', $collection->refundStatus()->value);
        $this->assertSame('32.00', (string) $collection->refundedTotal()->getAmount());
        $this->assertSame(Refund::STATUS_SUCCEEDED, $collection->toArray()[0]['status']);
    }

    #[DataProvider('contradictoryCollections')]
    public function testRejectsACollectionThatDoesNotContainTheSameTriggeringPurchase(
        bool $containsTrigger,
        int $amount,
        string $paymentIntentId,
    ): void {
        $historical = $this->refund(amount: 3200);
        $current = $containsTrigger ? [$this->refund(amount: $amount, paymentIntentId: $paymentIntentId)] : [];
        $retriever = new RefundRetriever($this->gateway($historical, refunds: $current));
        $correlation = $retriever->correlate($this->event($historical), CredentialMode::Test);
        $this->assertNotNull($correlation);

        $this->expectException(OrderDataException::class);
        $retriever->retrieve($correlation, $this->observation());
    }

    /** @return iterable<string, array{bool, int, string}> */
    public static function contradictoryCollections(): iterable
    {
        yield 'missing trigger' => [false, 3200, 'pi_test'];
        yield 'changed amount' => [true, 1600, 'pi_test'];
        yield 'different parent' => [true, 3200, 'pi_other'];
    }

    public function testReadsACompleteCollectionWithoutATriggerForManualSynchronization(): void
    {
        $collection = (new RefundRetriever($this->gateway($this->refund(), refunds: [])))
            ->retrieve(null, $this->observation());

        $this->assertSame([], $collection->toArray());
        $this->assertSame('none', $collection->refundStatus()->value);
    }

    /**
     * @param array<string, mixed> $refund
     * @param array<string, mixed>|null $paymentIntent
     * @param array<string, mixed>|null $charge
     * @param list<array<string, mixed>>|null $refunds
     * @return RefundGatewayInterface&MockObject
     */
    private function gateway(
        array $refund,
        ?array $paymentIntent = null,
        ?array $charge = null,
        ?array $refunds = null,
    ): RefundGatewayInterface {
        $gateway = $this->createMock(RefundGatewayInterface::class);
        $gateway->method('retrieve')->willReturn($refund);
        $gateway->method('retrievePaymentIntent')->willReturn($paymentIntent ?? $this->paymentIntent());
        $gateway->method('retrieveCharge')->willReturn($charge ?? []);
        $gateway->method('allForPaymentIntent')->willReturn($refunds ?? [$refund]);

        return $gateway;
    }

    /** @return array<string, mixed> */
    private function refund(
        string $status = Refund::STATUS_SUCCEEDED,
        int $amount = 1600,
        ?string $paymentIntentId = 'pi_test',
        ?string $chargeId = null,
    ): array {
        return [
            'id' => 're_test',
            'object' => Refund::OBJECT_NAME,
            'amount' => $amount,
            'currency' => 'eur',
            'payment_intent' => $paymentIntentId,
            'charge' => $chargeId,
            'created' => 100,
            'status' => $status,
            'metadata' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function paymentIntent(): array
    {
        return [
            'id' => 'pi_test',
            'object' => PaymentIntent::OBJECT_NAME,
            'currency' => 'eur',
            'livemode' => false,
            'metadata' => [
                PluginMetadata::OWNER_KEY => PluginMetadata::NAME,
                PluginMetadata::ORDER_KEY => 'page://Abc123def456GHI7',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function charge(string $id = 'ch_test'): array
    {
        return [
            'id' => $id,
            'object' => 'charge',
            'payment_intent' => 'pi_test',
            'currency' => 'eur',
            'livemode' => false,
        ];
    }

    /** @param array<string, mixed> $refund */
    private function event(array $refund, string $type = Event::REFUND_CREATED): Event
    {
        return Event::constructFrom([
            'id' => 'evt_refund',
            'object' => Event::OBJECT_NAME,
            'type' => $type,
            'created' => 100,
            'livemode' => false,
            'data' => ['object' => $refund],
        ]);
    }

    private function observation(): CheckoutSessionObservation
    {
        $amount = Money::of('32', 'EUR');

        return new CheckoutSessionObservation(
            association: new CheckoutSessionAssociation(
                sessionId: 'cs_test',
                shippingRateIds: [],
                request: new SessionRequest(['mode' => 'payment']),
            ),
            status: 'complete',
            paymentStatus: 'paid',
            liveMode: false,
            createdAt: 100,
            expiresAt: 200,
            requestId: null,
            stripeInvoiceId: null,
            subtotal: $amount,
            total: $amount,
            lineItems: [],
            payment: new PaymentSnapshot(
                amount: $amount,
                stripePaymentIntentId: 'pi_test',
            ),
            snapshot: new CheckoutSessionSnapshot(
                stripeCustomerId: null,
                customer: null,
                billingAddress: null,
                shippingAddress: null,
                checkoutShipping: CheckoutShippingSnapshot::none('0.00'),
                customFields: [],
                consent: null,
                checkoutDiscounts: CheckoutDiscountsSnapshot::available([], '0.00'),
                tax: null,
            ),
        );
    }
}
