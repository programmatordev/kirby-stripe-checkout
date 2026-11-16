<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Checkout;

use Brick\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\DisputeRetriever;
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
use ProgrammatorDev\StripeCheckout\Stripe\Dispute\DisputeGatewayInterface;
use Stripe\Charge;
use Stripe\Dispute;
use Stripe\Event;
use Stripe\PaymentIntent;
use Stripe\Refund;

final class DisputeRetrieverTest extends TestCase
{
    #[DataProvider('disputeEventTypes')]
    public function testCorrelatesSupportedEventsThroughAuthoritativePaymentIntentOwnership(string $eventType): void
    {
        $dispute = $this->dispute();
        $correlation = (new DisputeRetriever($this->gateway($dispute)))->correlate(
            $this->event($dispute, $eventType),
            CredentialMode::Test,
        );

        $this->assertNotNull($correlation);
        $this->assertSame('page://Abc123def456GHI7', $correlation->pageUuid);
        $this->assertSame('du_test', $correlation->dispute->stripeDisputeId());
        $this->assertSame('pi_test', $correlation->dispute->stripePaymentIntentId());
        $this->assertSame('ch_test', $correlation->dispute->stripeChargeId());
        $this->assertSame($eventType, $correlation->trigger->type);
        $this->assertSame('evt_dispute', $correlation->trigger->id);
    }

    /** @return iterable<string, array{string}> */
    public static function disputeEventTypes(): iterable
    {
        yield 'created' => [Event::CHARGE_DISPUTE_CREATED];
        yield 'updated' => [Event::CHARGE_DISPUTE_UPDATED];
        yield 'closed' => [Event::CHARGE_DISPUTE_CLOSED];
        yield 'funds withdrawn' => [Event::CHARGE_DISPUTE_FUNDS_WITHDRAWN];
        yield 'funds reinstated' => [Event::CHARGE_DISPUTE_FUNDS_REINSTATED];
    }

    public function testUsesTheChargeBacklinkWhenTheDisputeOmitsItsPaymentIntent(): void
    {
        $dispute = $this->dispute(paymentIntentId: null, chargeId: 'ch_older');
        $charge = $this->charge(id: 'ch_older');
        $correlation = (new DisputeRetriever($this->gateway($dispute, charge: $charge)))->correlate(
            $this->event($dispute),
            CredentialMode::Test,
        );

        $this->assertNotNull($correlation);
        $this->assertSame('pi_test', $correlation->dispute->stripePaymentIntentId());
        $this->assertSame('ch_older', $correlation->dispute->stripeChargeId());
        $this->assertSame('ch_older', $correlation->trigger->stripeChargeId);
    }

    public function testIgnoresDisputeMetadataWhenTheCurrentPaymentIntentIsNotOwned(): void
    {
        $dispute = $this->dispute();
        $dispute['metadata'] = [
            PluginMetadata::OWNER_KEY => PluginMetadata::NAME,
            PluginMetadata::ORDER_KEY => 'page://Abc123def456GHI7',
        ];
        $paymentIntent = $this->paymentIntent();
        $paymentIntent['metadata'] = [];

        $this->assertNull((new DisputeRetriever($this->gateway($dispute, $paymentIntent)))->correlate(
            $this->event($dispute),
            CredentialMode::Test,
        ));
    }

    #[DataProvider('contradictoryParents')]
    public function testRejectsContradictoryParentFacts(string $resource, string $field, mixed $value): void
    {
        $dispute = $this->dispute(paymentIntentId: null);
        $paymentIntent = $this->paymentIntent();
        $charge = $this->charge();

        if ($resource === 'paymentIntent') {
            $paymentIntent[$field] = $value;
        } elseif ($resource === 'charge') {
            $charge[$field] = $value;
        } else {
            $dispute[$field] = $value;
        }

        $this->expectException(OrderDataException::class);
        (new DisputeRetriever($this->gateway($dispute, $paymentIntent, $charge)))->correlate(
            $this->event($dispute),
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
        yield 'Dispute mode' => ['dispute', 'livemode', true];
    }

    public function testUsesCurrentCollectionFactsInsteadOfTheHistoricalEvent(): void
    {
        $historical = $this->dispute(status: Dispute::STATUS_NEEDS_RESPONSE, amount: 1600);
        $current = $this->dispute(status: Dispute::STATUS_PREVENTED, amount: 5000);
        $retriever = new DisputeRetriever($this->gateway($historical, disputes: [$current]));
        $correlation = $retriever->correlate($this->event($historical), CredentialMode::Test);
        $this->assertNotNull($correlation);

        $collection = $retriever->retrieve($correlation, $this->observation());
        $this->assertSame('resolved_favorable', $collection->disputeStatus()->value);
        $this->assertSame('50.00', $collection->toArray()[0]['amount']);
        $this->assertSame(Dispute::STATUS_PREVENTED, $collection->toArray()[0]['status']);
    }

    public function testRejectsACollectionWithoutTheTriggeringDispute(): void
    {
        $historical = $this->dispute();
        $retriever = new DisputeRetriever($this->gateway(
            dispute: $historical,
            disputes: [$this->dispute(id: 'du_other')],
        ));
        $correlation = $retriever->correlate($this->event($historical), CredentialMode::Test);
        $this->assertNotNull($correlation);

        $this->expectException(OrderDataException::class);
        $retriever->retrieve($correlation, $this->observation());
    }

    #[DataProvider('contradictorySiblings')]
    public function testRejectsContradictorySiblingFacts(string $field, mixed $value): void
    {
        $historical = $this->dispute();
        $sibling = $this->dispute(id: 'du_sibling');
        $sibling[$field] = $value;
        $retriever = new DisputeRetriever($this->gateway(
            dispute: $historical,
            disputes: [$historical, $sibling],
        ));
        $correlation = $retriever->correlate($this->event($historical), CredentialMode::Test);
        $this->assertNotNull($correlation);

        $this->expectException(OrderDataException::class);
        $retriever->retrieve($correlation, $this->observation());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function contradictorySiblings(): iterable
    {
        yield 'different PaymentIntent' => ['payment_intent', 'pi_other'];
        yield 'different currency' => ['currency', 'usd'];
        yield 'different mode' => ['livemode', true];
        yield 'wrong resource' => ['object', Refund::OBJECT_NAME];
    }

    public function testReadsACompleteCollectionWithoutATriggerForManualSynchronization(): void
    {
        $collection = (new DisputeRetriever($this->gateway($this->dispute(), disputes: [])))
            ->retrieve(null, $this->observation());

        $this->assertSame([], $collection->toArray());
        $this->assertSame('none', $collection->disputeStatus()->value);
    }

    /**
     * @param array<string, mixed> $dispute
     * @param array<string, mixed>|null $paymentIntent
     * @param array<string, mixed>|null $charge
     * @param list<array<string, mixed>>|null $disputes
     * @return DisputeGatewayInterface&MockObject
     */
    private function gateway(
        array $dispute,
        ?array $paymentIntent = null,
        ?array $charge = null,
        ?array $disputes = null,
    ): DisputeGatewayInterface {
        $gateway = $this->createMock(DisputeGatewayInterface::class);
        $gateway->method('retrieve')->willReturn($dispute);
        $gateway->method('retrievePaymentIntent')->willReturn($paymentIntent ?? $this->paymentIntent());
        $gateway->method('retrieveCharge')->willReturn($charge ?? $this->charge());
        $gateway->method('allForPaymentIntent')->willReturn($disputes ?? [$dispute]);

        return $gateway;
    }

    /** @return array<string, mixed> */
    private function dispute(
        string $id = 'du_test',
        string $status = Dispute::STATUS_NEEDS_RESPONSE,
        int $amount = 1600,
        ?string $paymentIntentId = 'pi_test',
        string $chargeId = 'ch_test',
    ): array {
        return [
            'id' => $id,
            'object' => Dispute::OBJECT_NAME,
            'amount' => $amount,
            'currency' => 'eur',
            'payment_intent' => $paymentIntentId,
            'charge' => $chargeId,
            'livemode' => false,
            'created' => 100,
            'status' => $status,
            'reason' => Dispute::REASON_FRAUDULENT,
            'evidence_details' => [
                'due_by' => null,
                'has_evidence' => false,
                'past_due' => false,
                'submission_count' => 0,
            ],
            'balance_transactions' => [],
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
            'object' => Charge::OBJECT_NAME,
            'payment_intent' => 'pi_test',
            'currency' => 'eur',
            'livemode' => false,
        ];
    }

    /** @param array<string, mixed> $dispute */
    private function event(array $dispute, string $type = Event::CHARGE_DISPUTE_CREATED): Event
    {
        return Event::constructFrom([
            'id' => 'evt_dispute',
            'object' => Event::OBJECT_NAME,
            'type' => $type,
            'created' => 100,
            'livemode' => false,
            'data' => ['object' => $dispute],
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
