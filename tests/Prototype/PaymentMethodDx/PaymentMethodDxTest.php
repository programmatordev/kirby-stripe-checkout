<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Prototype\PaymentMethodDx;

use Brick\Money\Money;
use DateTimeImmutable;
use Kirby\Cms\Events;
use Kirby\Cms\Page;
use Kirby\Content\Field;
use Kirby\Data\Yaml;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEvent;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEventType;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\DisputeStatus;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Order\RefundStatus;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\TestWorkspace;
use Stripe\Charge;
use Stripe\Event;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;

/** Explicitly run prototype scenarios; never discovered by the production PHPUnit suites. */
final class PaymentMethodDxTest extends KirbyTestCase
{
    public function testPaymentRejectsMixedAmountCurrenciesBeforeSerialization(): void
    {
        $this->expectException(OrderDataException::class);

        new Payment(
            status: PaymentStatus::Paid,
            amount: Money::of('25.00', 'EUR'),
            amountReceived: Money::of('25.00', 'USD'),
        );
    }

    public function testVoucherInstructionsDoNotRequireACharge(): void
    {
        $payment = (new PaymentNormalizer())->normalize(
            status: PaymentStatus::Pending,
            amount: Money::of('25.00', 'EUR'),
            paymentIntent: PaymentFixtures::multibanco(),
        );

        $this->assertNull($payment->stripeChargeId());
        $this->assertSame(PaymentMethod::TYPE_MULTIBANCO, $payment->methodType());
        $this->assertSame('123456789', $payment->instructions()?->reference());
    }

    public function testImmediateCardPaymentExposesExactMoneyWithoutCardDetails(): void
    {
        $payment = (new PaymentNormalizer())->normalize(
            status: PaymentStatus::Paid,
            amount: Money::of('25.00', 'EUR'),
            paymentIntent: PaymentFixtures::card(),
        );

        $this->assertSame(PaymentStatus::Paid, $payment->status());
        $this->assertSame(PaymentMethod::TYPE_CARD, $payment->methodType());
        $this->assertSame('25.00', (string) $payment->amount()->getAmount());
        $this->assertSame('25.00', (string) $payment->amountReceived()?->getAmount());
        $this->assertSame('pi_prototype', $payment->stripePaymentIntentId());
        $this->assertSame('ch_prototype', $payment->stripeChargeId());
        $this->assertSame(PaymentIntent::STATUS_SUCCEEDED, $payment->paymentIntentStatus());
        $this->assertNull($payment->instructions());
        $this->assertStringNotContainsString('EXCLUDED_', OrderData::json($payment->toArray()));
    }

    #[DataProvider('completedWalletAndBnplPayments')]
    public function testCompletedWalletAndBnplPaymentsUseTheSameTypedPaymentFacts(
        PaymentIntent $paymentIntent,
        string $methodType,
    ): void {
        $payment = (new PaymentNormalizer())->normalize(
            status: PaymentStatus::Paid,
            amount: Money::of('25.00', 'EUR'),
            paymentIntent: $paymentIntent,
        );

        $this->assertSame($methodType, $payment->methodType());
        $this->assertSame(PaymentStatus::Paid, $payment->status());
        $this->assertSame('25.00', (string) $payment->amountReceived()?->getAmount());
        $this->assertNull($payment->instructions());
        $this->assertStringNotContainsString('EXCLUDED_', OrderData::json($payment->toArray()));
    }

    /** @return iterable<string, array{PaymentIntent, string}> */
    public static function completedWalletAndBnplPayments(): iterable
    {
        yield 'MB WAY app approval' => [PaymentFixtures::mbWay(), PaymentMethod::TYPE_MB_WAY];
        yield 'Klarna merchant payment' => [PaymentFixtures::klarna(), PaymentMethod::TYPE_KLARNA];
        yield 'PayPal' => [PaymentFixtures::paypal(), PaymentMethod::TYPE_PAYPAL];
        yield 'Link' => [PaymentFixtures::link(), PaymentMethod::TYPE_LINK];
        // These wallets fund a card payment; they are not separate Stripe method types.
        yield 'Apple Pay card wallet' => [PaymentFixtures::applePay(), PaymentMethod::TYPE_CARD];
        yield 'Google Pay card wallet' => [PaymentFixtures::googlePay(), PaymentMethod::TYPE_CARD];
    }

    #[DataProvider('redirectPayments')]
    public function testProviderAuthenticationRedirectIsNotASavedPaymentInstruction(PaymentIntent $paymentIntent): void
    {
        $data = $paymentIntent->toArray();
        $data['status'] = PaymentIntent::STATUS_REQUIRES_ACTION;
        $data['amount_received'] = 0;
        $data['latest_charge'] = null;
        $data['next_action'] = [
            'type' => 'redirect_to_url',
            'redirect_to_url' => [
                'url' => 'https://authentication.example.test/EXCLUDED_AUTHENTICATION_TOKEN',
                'return_url' => 'https://shop.example.test/EXCLUDED_RETURN_TOKEN',
            ],
        ];
        $payment = (new PaymentNormalizer())->normalize(
            // Before Checkout completes, unfinished authentication is unpaid,
            // not a delayed-payment pending transition or a failed order.
            status: PaymentStatus::Unpaid,
            amount: Money::of('25.00', 'EUR'),
            paymentIntent: PaymentIntent::constructFrom($data),
        );

        $this->assertSame(PaymentStatus::Unpaid, $payment->status());
        $this->assertSame('redirect_to_url', $payment->nextActionType());
        $this->assertNull($payment->stripeChargeId());
        $this->assertNull($payment->instructions());
        $this->assertStringNotContainsString('EXCLUDED_', OrderData::json($payment->toArray()));
    }

    /** @return iterable<string, array{PaymentIntent}> */
    public static function redirectPayments(): iterable
    {
        yield 'Klarna redirect' => [PaymentFixtures::klarna()];
        yield 'PayPal redirect' => [PaymentFixtures::paypal()];
    }

    public function testMbWayApprovalFailureExposesSafeCodeWithoutVoucherInstructions(): void
    {
        $data = PaymentFixtures::mbWay()->toArray();
        $data['status'] = PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD;
        $data['amount_received'] = 0;
        $data['latest_charge'] = null;
        $data['last_payment_error'] = [
            'type' => 'card_error',
            'code' => 'payment_method_provider_decline',
            'message' => 'EXCLUDED_APPROVAL_FAILURE_MESSAGE',
        ];
        $payment = (new PaymentNormalizer())->normalize(
            status: PaymentStatus::Unpaid,
            amount: Money::of('25.00', 'EUR'),
            paymentIntent: PaymentIntent::constructFrom($data),
        );

        $this->assertSame(PaymentMethod::TYPE_MB_WAY, $payment->methodType());
        $this->assertSame(PaymentStatus::Unpaid, $payment->status());
        $this->assertSame('payment_method_provider_decline', $payment->failureCode());
        $this->assertNull($payment->instructions());
        $this->assertStringNotContainsString('EXCLUDED_', OrderData::json($payment->toArray()));
    }

    #[DataProvider('vouchers')]
    public function testVoucherInstructionsHaveNamedAccessorsAndAnAbsoluteExpiry(
        PaymentIntent $paymentIntent,
        string $currency,
        string $reference,
        ?string $entity,
        string $hostedUrl,
    ): void {
        $payment = (new PaymentNormalizer())->normalize(
            status: PaymentStatus::Pending,
            amount: Money::of('25.00', $currency),
            paymentIntent: $paymentIntent,
        );
        $instructions = $payment->instructions();

        $this->assertNotNull($instructions);
        $this->assertSame($reference, $instructions->reference());
        $this->assertSame($entity, $instructions->entity());
        $this->assertSame($hostedUrl, $instructions->hostedUrl());
        $this->assertSame(1790899200, $instructions->expiresAt()?->getTimestamp());
        $this->assertNull($instructions->qrCodeImageUrl());
        $this->assertSame(PaymentStatus::Pending, $payment->status());
        $this->assertSame('0.00', (string) $payment->amountReceived()?->getAmount());

        $restored = Payment::fromArray(OrderData::map(Yaml::decode(Yaml::encode($payment->toArray()))));
        $this->assertSame($payment->toArray(), $restored->toArray());
    }

    /** @return iterable<string, array{PaymentIntent, string, string, string|null, string}> */
    public static function vouchers(): iterable
    {
        yield 'Multibanco' => [PaymentFixtures::multibanco(), 'EUR', '123456789', '12345', 'https://payments.example.test/voucher'];
        yield 'OXXO' => [PaymentFixtures::oxxo(), 'MXN', '1234567890', null, 'https://payments.example.test/oxxo'];
    }

    public function testQrInstructionsDoNotInventExpiryOrPersistRawQrPayload(): void
    {
        $payment = (new PaymentNormalizer())->normalize(
            status: PaymentStatus::Pending,
            amount: Money::of('25.00', 'SGD'),
            paymentIntent: PaymentFixtures::paynow(),
        );
        $instructions = $payment->instructions();

        $this->assertNotNull($instructions);
        $this->assertSame('https://payments.example.test/qr.png', $instructions->qrCodeImageUrl());
        $this->assertSame('https://payments.example.test/paynow', $instructions->hostedUrl());
        $this->assertNull($instructions->reference());
        $this->assertNull($instructions->expiresAt());
        $this->assertStringNotContainsString('EXCLUDED_', OrderData::json($payment->toArray()));
        $this->assertSame($payment->toArray(), Payment::fromArray($payment->toArray())->toArray());
    }

    public function testAbsentVoucherDetailsDoNotProduceAnEmptyInstructionObject(): void
    {
        $data = PaymentFixtures::multibanco()->toArray();
        $data['next_action'] = [
            'type' => 'multibanco_display_details',
            'multibanco_display_details' => [
                'entity' => null,
                'reference' => null,
                'expires_at' => null,
                'hosted_voucher_url' => null,
            ],
        ];
        $payment = (new PaymentNormalizer())->normalize(
            status: PaymentStatus::Pending,
            amount: Money::of('25.00', 'EUR'),
            paymentIntent: PaymentIntent::constructFrom($data),
        );

        $this->assertSame(PaymentStatus::Pending, $payment->status());
        $this->assertSame('multibanco_display_details', $payment->nextActionType());
        $this->assertNull($payment->instructions());
    }

    public function testLaterPaidObservationDoesNotReusePreviousVoucherInstructions(): void
    {
        $paymentIntent = PaymentFixtures::multibanco();
        $normalizer = new PaymentNormalizer();
        $pendingPayment = $normalizer->normalize(
            status: PaymentStatus::Pending,
            amount: Money::of('25.00', 'EUR'),
            paymentIntent: $paymentIntent,
        );
        $paymentIntent->status = PaymentIntent::STATUS_SUCCEEDED;
        $paymentIntent->amount_received = 2500;
        $paymentIntent->next_action = null;
        $paidPayment = $normalizer->normalize(
            status: PaymentStatus::Paid,
            amount: Money::of('25.00', 'EUR'),
            paymentIntent: $paymentIntent,
        );

        $this->assertNull($paidPayment->instructions());
        $this->assertSame('25.00', (string) $paidPayment->amountReceived()?->getAmount());
        $this->assertSame('123456789', $pendingPayment->instructions()?->reference());
    }

    #[DataProvider('delayedDebits')]
    public function testPendingDebitDoesNotImplyCustomerInstructions(
        PaymentIntent $paymentIntent,
        string $methodType,
        string $currency,
    ): void {
        $payment = (new PaymentNormalizer())->normalize(
            status: PaymentStatus::Pending,
            amount: Money::of('25.00', $currency),
            paymentIntent: $paymentIntent,
        );

        $this->assertSame(PaymentStatus::Pending, $payment->status());
        $this->assertSame($methodType, $payment->methodType());
        $this->assertSame(PaymentIntent::STATUS_PROCESSING, $payment->paymentIntentStatus());
        $this->assertNull($payment->nextActionType());
        $this->assertNull($payment->instructions());
        $this->assertStringNotContainsString('EXCLUDED_', OrderData::json($payment->toArray()));
    }

    /** @return iterable<string, array{PaymentIntent, string, string}> */
    public static function delayedDebits(): iterable
    {
        yield 'SEPA' => [PaymentFixtures::sepaDebit(), PaymentMethod::TYPE_SEPA_DEBIT, 'EUR'];
        yield 'Bacs' => [PaymentFixtures::bacsDebit(), PaymentMethod::TYPE_BACS_DEBIT, 'GBP'];
    }

    public function testFailedDebitKeepsSafeFailureCodeWithoutProviderErrorMessages(): void
    {
        $data = PaymentFixtures::bacsDebit()->toArray();
        $data['status'] = PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD;
        $data['last_payment_error'] = [
            'type' => 'card_error',
            'code' => 'payment_method_failed',
            'message' => 'EXCLUDED_PROVIDER_MESSAGE',
        ];
        $paymentIntent = PaymentIntent::constructFrom($data);
        $charge = $paymentIntent->latest_charge;
        $this->assertInstanceOf(Charge::class, $charge);
        $charge->status = Charge::STATUS_FAILED;
        $payment = (new PaymentNormalizer())->normalize(
            status: PaymentStatus::Failed,
            amount: Money::of('25.00', 'GBP'),
            paymentIntent: $paymentIntent,
        );

        $this->assertSame(PaymentStatus::Failed, $payment->status());
        $this->assertSame('payment_method_failed', $payment->failureCode());
        $this->assertNull($payment->instructions());
        $this->assertStringNotContainsString('EXCLUDED_', OrderData::json($payment->toArray()));
    }

    public function testNoCostOrderNeedsNoPaymentIntentOrGuessedMethod(): void
    {
        $payment = (new PaymentNormalizer())->normalize(
            status: PaymentStatus::NoPaymentRequired,
            amount: Money::of('0', 'EUR'),
            paymentIntent: null,
        );

        $this->assertSame(PaymentStatus::NoPaymentRequired, $payment->status());
        $this->assertSame('0.00', (string) $payment->amount()->getAmount());
        $this->assertNull($payment->amountReceived());
        $this->assertNull($payment->methodType());
        $this->assertNull($payment->stripePaymentIntentId());
        $this->assertNull($payment->instructions());
    }

    public function testUnknownMethodAndActionRemainObservableWithoutCopyingTheirSubtrees(): void
    {
        $data = PaymentFixtures::sepaDebit()->toArray();
        $data['payment_method'] = 'pm_unexpanded';
        $data['latest_charge'] = [
            'id' => 'ch_future',
            'object' => Charge::OBJECT_NAME,
            'payment_method_details' => [
                'type' => 'future_method',
                'future_method' => ['provider_score' => 1.5],
            ],
        ];
        $data['next_action'] = [
            'type' => 'future_action',
            'future_action' => [
                'secret' => 'EXCLUDED_FUTURE_DATA',
                'provider_score' => 1.5,
            ],
        ];
        $payment = (new PaymentNormalizer())->normalize(
            status: PaymentStatus::Pending,
            amount: Money::of('25.00', 'EUR'),
            paymentIntent: PaymentIntent::constructFrom($data),
        );

        $this->assertSame('future_method', $payment->methodType());
        $this->assertSame('future_action', $payment->nextActionType());
        $this->assertNull($payment->instructions());
        $this->assertStringNotContainsString('provider_score', OrderData::json($payment->toArray()));
        $this->assertStringNotContainsString('EXCLUDED_', OrderData::json($payment->toArray()));
    }

    public function testUnexpandedReferencesDoNotTurnAnAllowedMethodIntoAChosenMethod(): void
    {
        $paymentIntent = PaymentFixtures::sepaDebit();
        $paymentIntent->payment_method = 'pm_unexpanded';
        $paymentIntent->latest_charge = 'ch_unexpanded';
        $payment = (new PaymentNormalizer())->normalize(
            status: PaymentStatus::Pending,
            amount: Money::of('25.00', 'EUR'),
            paymentIntent: $paymentIntent,
        );

        $this->assertNull($payment->methodType());
        $this->assertSame('ch_unexpanded', $payment->stripeChargeId());
    }

    public function testNativeHookReadsRestoredInstructionsAfterTheLiveOrderChanges(): void
    {
        $payment = (new PaymentNormalizer())->normalize(
            status: PaymentStatus::Pending,
            amount: Money::of('25.00', 'EUR'),
            paymentIntent: PaymentFixtures::multibanco(),
        );
        $message = null;
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            hooks: [
                'programmatordev.stripe-checkout.payment.pending' => function (Page $order, LifecycleEvent $lifecycleEvent) use (&$message): void {
                    // This conversion exercises the candidate API only.
                    // Neither a lifecycle accessor nor this instruction schema is approved yet.
                    // Read event-time payment facts, not the live Order Page: a retried hook can run after that Page's payment state changes.
                    $payment = Payment::fromArray(OrderData::map($lifecycleEvent->orderSnapshot()['payment']));
                    $instructions = $payment->instructions();

                    if ($instructions === null) {
                        return;
                    }

                    $message = implode(' / ', [
                        $order->title()->toString(),
                        $instructions->entity() ?? '',
                        $instructions->reference() ?? '',
                        $instructions->hostedUrl() ?? '',
                    ]);
                },
            ],
            beforeApp: static function (TestWorkspace $workspace) use ($payment): void {
                $workspace->writeDraftPage('prototype-order', 'default', [
                    'title' => 'Prototype order',
                    'uuid' => 'prototype-order',
                    'payment' => Yaml::encode($payment->toArray()),
                ]);
            },
        );
        $this->kirby = $this->environment->app();
        $order = $this->kirby->site()->drafts()->find('prototype-order');
        $this->assertInstanceOf(Page::class, $order);
        $paymentField = $order->content()->get('payment');
        $this->assertInstanceOf(Field::class, $paymentField);
        $restoredPayment = Payment::fromArray(OrderData::map(Yaml::decode($paymentField->toString())));
        $this->assertSame($payment->toArray(), $restoredPayment->toArray());
        $snapshot = [
            'uuid' => 'prototype-order',
            'languageCode' => 'en',
            'checkoutStatus' => CheckoutStatus::Complete->value,
            'paymentStatus' => PaymentStatus::Pending->value,
            'refundStatus' => RefundStatus::None->value,
            'disputeStatus' => DisputeStatus::None->value,
            'payment' => $restoredPayment->toArray(),
        ];
        $event = new LifecycleEvent(
            deliveryId: 'prototype-delivery',
            type: LifecycleEventType::PaymentPending,
            pageUuid: 'page://prototype-order',
            occurredAt: new DateTimeImmutable('2026-10-01T20:00:00Z'),
            revision: 1,
            languageCode: 'en',
            checkoutStatus: CheckoutStatus::Complete,
            paymentStatus: PaymentStatus::Pending,
            refundStatus: RefundStatus::None,
            disputeStatus: DisputeStatus::None,
            triggerType: Event::CHECKOUT_SESSION_COMPLETED,
            triggerId: 'evt_prototype',
            orderSnapshot: $snapshot,
        );

        // Simulate later saved payment facts independently of the frozen event.
        // This exercises hook inputs, not a production reconciliation operation.
        $currentPayment = new Payment(
            status: PaymentStatus::Paid,
            amount: Money::of('25.00', 'EUR'),
            amountReceived: Money::of('25.00', 'EUR'),
            methodType: PaymentMethod::TYPE_MULTIBANCO,
        );
        $order = $order->update([
            'paymentStatus' => PaymentStatus::Paid->value,
            'payment' => Yaml::encode($currentPayment->toArray()),
        ]);
        $currentPaymentField = $order->content()->get('payment');
        $this->assertInstanceOf(Field::class, $currentPaymentField);
        $livePayment = Payment::fromArray(OrderData::map(Yaml::decode($currentPaymentField->toString())));
        $this->assertSame(PaymentStatus::Paid, $livePayment->status());
        $this->assertNull($livePayment->instructions());

        // Native Events exercises named hook arguments, not a production reducer, payment commit, delivery ledger or lifecycle retry implementation.
        (new Events($this->kirby))->trigger('programmatordev.stripe-checkout.payment.pending', [
            'order' => $order,
            'lifecycleEvent' => $event,
        ]);

        $this->assertSame('Prototype order / 12345 / 123456789 / https://payments.example.test/voucher', $message);
        $this->assertSame(Event::CHECKOUT_SESSION_COMPLETED, $event->triggerType());
        $this->assertSame('evt_prototype', $event->triggerId());
    }

    public function testRawSdkAccessIsTypedButMutableAndNotSafeToPersistWholesale(): void
    {
        $paymentIntent = PaymentFixtures::multibanco();
        $payment = (new PaymentNormalizer())->normalize(
            status: PaymentStatus::Pending,
            amount: Money::of('25.00', 'EUR'),
            paymentIntent: $paymentIntent,
        );
        $action = $paymentIntent->next_action;
        $this->assertNotNull($action);
        $details = $action->multibanco_display_details ?? null;
        $this->assertNotNull($details);

        // SDK PHPDoc gives method-specific completion, but nested SDK objects are mutable even when a wrapper's property is declared readonly.
        $this->assertSame('123456789', $details->reference);
        $details['reference'] = 'changed-sdk-reference';
        $this->assertSame('changed-sdk-reference', $details->reference);
        $this->assertSame('123456789', $payment->instructions()?->reference());
        $this->assertStringContainsString('EXCLUDED_CLIENT_SECRET', json_encode($paymentIntent->toArray(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('EXCLUDED_', OrderData::json($payment->toArray()));
    }
}
