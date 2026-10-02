<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSessionRetriever;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Configuration\StripeConfiguration;
use ProgrammatorDev\StripeCheckout\Order\Internal\CheckoutSessionAssociation;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Plugin\PluginMetadata;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionObservation;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\StripeApiCheckoutSessionGateway;
use ProgrammatorDev\StripeCheckout\Stripe\StripeApiClientFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\OrderFixture;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

final class CheckoutSessionRetrieverTest extends KirbyTestCase
{
    /** @var list<array<string, mixed>> */
    private array $requests = [];

    public function testReadsAllPagesAndRestoresInitiatingOrderWithoutPresentationOrStorefrontReads(): void
    {
        $session = $this->session();
        $session['customer_details'] = [
            'email' => 'buyer@example.test',
            'address' => null,
            'individual_name' => 'Buyer',
            'tax_ids' => [],
        ];
        $session['custom_fields'] = [[
            'key' => 'deliverynote',
            'type' => 'text',
            'label' => [
                'type' => 'custom',
                'custom' => 'Delivery note',
            ],
            'optional' => true,
            'text' => ['value' => 'Leave by the door'],
        ]];
        $session['line_items'] = [
            'data' => [],
            'has_more' => true,
        ];
        $this->responses([
            $session,
            $this->page([$this->line(1)], true),
            $this->page([$this->line(0)], false),
        ]);

        $observation = $this->read();

        $this->assertSame('complete', $observation->status());
        $this->assertSame('unpaid', $observation->paymentStatus());
        $this->assertSame('64.00', (string) $observation->subtotal()->getAmount());
        $this->assertSame('64.00', (string) $observation->total()->getAmount());
        $this->assertSame(['li_0', 'li_1'], array_map(static fn($line): string => $line->stripeLineItemId(), $observation->lineItems()));
        $this->assertSame([0, 1], array_map(static fn($line): int => $line->initiatingIndex(), $observation->lineItems()));
        $this->assertSame('16.00', (string) $observation->lineItems()[0]->price()->getAmount());
        $this->assertSame('buyer@example.test', $observation->snapshot()->customer()?->toArray()['email']);
        $this->assertSame('Leave by the door', $observation->snapshot()->customFields()[0]->toArray()['value']);
        $this->assertSame('multibanco', $observation->payment()->methodType());
        $this->assertNull($observation->payment()->stripeChargeId());
        $this->assertCount(3, $this->requests);
        $this->assertSame('li_1', $this->requests[2]['starting_after']);
        $this->assertSame(100, $this->requests[1]['limit']);
    }

    public function testGatewayKeepsLightweightReadsSeparateFromCompleteReconciliationReads(): void
    {
        $session = $this->session();
        $session['payment_intent'] = 'pi_current';
        $this->responses([$session]);
        $gateway = new StripeApiCheckoutSessionGateway(
            (new StripeApiClientFactory())->create(new StripeConfiguration('sk_test_retrieval', null, null)),
        );

        $sessionRecord = $gateway->retrieve('cs_current');

        $this->assertSame('cs_current', $sessionRecord->id);
        $this->assertCount(1, $this->requests);
        $this->assertSame([], $this->requests[0]);

        $this->responses([
            $this->session(),
            $this->page([$this->line(0)], true),
            $this->page([$this->line(1)]),
        ]);

        $reconciliationRecord = $gateway->retrieveForReconciliation('cs_current');

        $this->assertSame('cs_current', $reconciliationRecord->session->id);
        $this->assertSame(['li_0', 'li_1'], array_column($reconciliationRecord->lineItems, 'id'));
        $this->assertSame('pi_current', $reconciliationRecord->paymentSource['id'] ?? null);
        $this->assertSame([
            'payment_intent.latest_charge',
            'payment_intent.payment_method',
            'shipping_cost.shipping_rate',
            'total_details.breakdown',
        ], $this->requests[0]['expand']);
        $this->assertCount(3, $this->requests);
    }

    public function testPreservesAutomaticAsyncCaptureFactsWithoutInventingACompletedCapture(): void
    {
        $session = $this->session();
        $payment = $this->payment();
        $session['payment_status'] = 'paid';
        $payment['status'] = 'succeeded';
        $payment['amount_received'] = 6400;
        $payment['capture_method'] = 'automatic_async';
        $payment['next_action'] = null;
        $payment['payment_method'] = [
            'id' => 'pm_card',
            'object' => 'payment_method',
            'type' => 'card',
            'card' => ['fingerprint' => 'EXCLUDED_CARD'],
        ];
        $payment['latest_charge'] = [
            'id' => 'ch_current',
            'object' => 'charge',
            'amount' => 6400,
            'amount_captured' => 0,
            'captured' => false,
            'created' => 1001,
            'currency' => 'eur',
            'livemode' => false,
            'paid' => true,
            'payment_intent' => 'pi_current',
            'payment_method' => 'pm_card',
            'payment_method_details' => [
                'type' => 'card',
                'card' => ['last4' => 'EXCLUDED_CARD'],
            ],
            'status' => 'succeeded',
        ];
        $session['payment_intent'] = $payment;
        $this->responses([$session, $this->page([$this->line(0), $this->line(1)])]);

        $paymentSnapshot = $this->read()->payment();

        $this->assertSame('card', $paymentSnapshot->methodType());
        $this->assertSame('ch_current', $paymentSnapshot->stripeChargeId());
        $this->assertSame('64.00', (string) $paymentSnapshot->amountReceived()?->getAmount());
        $this->assertFalse($paymentSnapshot->chargeCaptured());
        $this->assertSame('0.00', (string) $paymentSnapshot->amountCaptured()?->getAmount());
        $this->assertNull($paymentSnapshot->nextAction());

        $payment['latest_charge']['payment_intent'] = 'pi_other';
        $session['payment_intent'] = $payment;
        $this->responses([$session, $this->page([$this->line(0), $this->line(1)])]);
        $this->expectException(CheckoutSessionException::class);
        $this->expectExceptionMessage(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        $this->read();
    }

    public function testReadsArchivedStripePricesInEmbeddedModeUsingFrozenEvidence(): void
    {
        $lines = [];

        for ($index = 0; $index < 2; $index++) {
            $line = OrderFixture::lineItemData();
            $line['requiresShipping'] = false;
            $line['priceSource'] = 'stripe';
            $line['stripePriceId'] = 'price_' . $index;
            $line['stripeProductId'] = 'prod_archived';
            $lines[] = OrderLineItemSnapshot::fromArray($line);
        }

        $order = new OrderCreationContext(
            uuid: 'Abc123def456GHI7',
            orderNumber: 'ORD-ABC123DEF456GHI7',
            checkoutSource: $this->order()->checkoutSource(),
            cartRevision: 'revision',
            userUuid: null,
            languageCode: 'en',
            uiMode: UiMode::Embedded,
            currency: 'EUR',
            lineItems: $lines,
        );
        $session = $this->session();
        $session['ui_mode'] = 'embedded_page';
        $parameters = $this->request()->parameters();
        $parameters['line_items'] = [
            [
                'quantity' => 2,
                'metadata' => $this->metadata(0),
                'price' => 'price_0',
            ],
            [
                'quantity' => 2,
                'metadata' => $this->metadata(1),
                'price' => 'price_1',
            ],
        ];
        $request = new SessionRequest($parameters);
        $this->responses([$session, $this->page([$this->line(0), $this->line(1)])]);

        $observation = $this->read(order: $order, request: $request);

        $this->assertSame('price_0', $observation->lineItems()[0]->stripePriceId());
        $this->assertSame('prod_archived', $observation->lineItems()[0]->stripeProductId());
        $this->assertSame('16.00', (string) $observation->lineItems()[0]->price()->getAmount());

        $wrongLine = $this->line(0);
        $wrongLine['price'] = array_replace(OrderData::map($wrongLine['price']), ['id' => 'price_unrelated']);
        $this->responses([$session, $this->page([$wrongLine, $this->line(1)])]);
        $this->expectException(CheckoutSessionException::class);
        $this->expectExceptionMessage(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        $this->read(order: $order, request: $request);
    }

    public function testUsesReturnedDiscountAndTaxAllocationsWithoutChangingTheFrozenPrice(): void
    {
        $discount = [
            'amount' => 200,
            'discount' => [
                'id' => 'di_sale',
                'source' => ['coupon' => ['id' => 'coupon_sale']],
            ],
        ];
        $tax = [
            'amount' => 100,
            'taxable_amount' => 3000,
            'rate' => [
                'id' => 'txr_one',
                'inclusive' => false,
            ],
        ];
        $line = $this->line(0);
        $line['amount_discount'] = 200;
        $line['amount_tax'] = 100;
        $line['amount_total'] = 3100;
        $line['discounts'] = [$discount];
        $line['taxes'] = [$tax];
        $session = $this->session();
        $session['amount_total'] = 6300;
        $session['payment_intent'] = array_replace($this->payment(), ['amount' => 6300]);
        $session['total_details'] = [
            'amount_discount' => 200,
            'amount_shipping' => 0,
            'amount_tax' => 100,
            'breakdown' => [
                'discounts' => [$discount],
                'taxes' => [$tax],
            ],
        ];
        $this->responses([$session, $this->page([$line, $this->line(1)])]);

        $observation = $this->read();
        $lineItem = $observation->lineItems()[0];

        $this->assertSame('16.00', (string) $lineItem->price()->getAmount());
        $this->assertSame('31.00', (string) $lineItem->total()->getAmount());
        $this->assertSame('2.00', (string) $lineItem->discount()->getAmount());
        $this->assertSame('1.00', (string) $lineItem->tax()->getAmount());
        $this->assertSame('di_sale', $lineItem->discounts()[0]->toArray()['discountId']);
        $this->assertSame('2.00', $observation->snapshot()->discountTotal());
        $this->assertSame('1.00', $observation->snapshot()->taxTotal());
        $this->assertSame('63.00', (string) $observation->total()->getAmount());
    }

    public function testCorrelatesSelectedShippingAgainstSavedRateAndQuoteEvidence(): void
    {
        $metadata = $this->metadata() + [
            PluginMetadata::SHIPPING_OPTION_KEY => 'standard',
            PluginMetadata::SHIPPING_QUOTE_KEY => str_repeat('a', 64),
        ];
        $parameters = $this->request()->parameters();
        $parameters['shipping_options'] = [['shipping_rate_data' => [
            'metadata' => $metadata,
            'fixed_amount' => [
                'amount' => 500,
                'currency' => 'eur',
            ],
        ]]];
        $parameters['shipping_address_collection'] = ['allowed_countries' => ['PT']];
        $request = new SessionRequest($parameters);
        $association = new CheckoutSessionAssociation('cs_current', ['shr_standard'], $request);
        $session = $this->session();
        $session['amount_total'] = 7015;
        $session['payment_intent'] = array_replace($this->payment(), ['amount' => 7015]);
        $session['total_details'] = [
            'amount_discount' => 0,
            'amount_shipping' => 500,
            'amount_tax' => 115,
            'breakdown' => [
                'discounts' => [],
                'taxes' => [[
                    'amount' => 115,
                    'taxable_amount' => 500,
                    'rate' => [
                        'id' => 'txr_portugal',
                        'inclusive' => false,
                        'percentage' => 23,
                    ],
                ]],
            ],
        ];
        $session['shipping_options'] = [['shipping_rate' => 'shr_standard']];
        $session['collected_information'] = ['shipping_details' => [
            'name' => 'Buyer',
            'address' => [
                'country' => 'PT',
                'line1' => 'Rua Um, 1',
                'city' => 'Porto',
                'postal_code' => '4000-001',
            ],
        ]];
        $session['shipping_cost'] = [
            'amount_subtotal' => 500,
            'amount_tax' => 115,
            'amount_total' => 615,
            'shipping_rate' => [
                'id' => 'shr_standard',
                'object' => 'shipping_rate',
                'type' => 'fixed_amount',
                'metadata' => $metadata,
                'display_name' => 'Standard delivery',
                'fixed_amount' => [
                    'currency' => 'eur',
                    'amount' => 500,
                    'currency_options' => null,
                ],
                'tax_behavior' => 'exclusive',
            ],
        ];
        $this->responses([$session, $this->page([$this->line(0), $this->line(1)])]);

        $observation = $this->read(association: $association, request: $request);

        $this->assertSame('shr_standard', $observation->snapshot()->stripeShippingRateId());
        $this->assertSame('6.15', $observation->snapshot()->shippingTotal());
        $this->assertSame('70.15', (string) $observation->total()->getAmount());

        $session['shipping_cost']['shipping_rate']['metadata'][PluginMetadata::SHIPPING_QUOTE_KEY] = str_repeat('b', 64);
        $this->responses([$session, $this->page([$this->line(0), $this->line(1)])]);
        $this->expectException(CheckoutSessionException::class);
        $this->expectExceptionMessage(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        $this->read(association: $association, request: $request);
    }

    public function testRejectsACompletedShippingCheckoutWithoutTheSelectedRate(): void
    {
        $parameters = $this->request()->parameters();
        $parameters['shipping_options'] = [['shipping_rate_data' => ['fixed_amount' => [
            'amount' => 0,
            'currency' => 'eur',
        ]]]];
        $session = $this->session();
        $session['shipping_options'] = [['shipping_rate' => 'shr_free']];
        $this->responses([$session, $this->page([$this->line(0), $this->line(1)])]);
        $this->expectException(CheckoutSessionException::class);
        $this->expectExceptionMessage(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        $this->read(request: new SessionRequest($parameters));
    }

    /** @param array<string, mixed> $action */
    #[DataProvider('paymentActions')]
    public function testPreservesGenericPaymentActionsWithIndependentSdkReadProjections(array $action): void
    {
        $session = $this->session();
        $payment = $this->payment();
        $payment['next_action'] = $action + ['unrelated' => ['client_secret' => 'EXCLUDED_SECRET']];
        $session['payment_intent'] = $payment;
        $this->responses([$session, $this->page([$this->line(0), $this->line(1)])]);

        $observation = $this->read();
        $paymentAction = $observation->payment()->nextAction();
        $this->assertNotNull($paymentAction);
        $firstProjection = $paymentAction->toPaymentIntent();
        $firstProjection['next_action'] = null;
        $details = $paymentAction->details();
        $details['mutated'] = true;

        $this->assertSame($action['type'], $paymentAction->type());
        $this->assertSame($action['type'], $observation->payment()->nextActionType());
        $this->assertEquals($action, $paymentAction->toArray());
        $this->assertEquals($action, $paymentAction->toPaymentIntent()->next_action?->toArray());
        $this->assertEquals($action[$paymentAction->type()], $paymentAction->details()->toArray());
        $this->assertNull($paymentAction->toPaymentIntent()->id ?? null);
        $this->assertStringNotContainsString('EXCLUDED', json_encode($paymentAction->toArray(), JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('next_action', $observation->snapshot()->toArray());
        $this->assertStringNotContainsString($paymentAction->type(), json_encode($observation->snapshot()->toArray(), JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function paymentActions(): iterable
    {
        yield 'Multibanco' => [[
            'type' => 'multibanco_display_details',
            'multibanco_display_details' => [
                'entity' => '12345',
                'reference' => '123456789',
                'expires_at' => 90000,
                'hosted_voucher_url' => 'https://payments.stripe.com/test/voucher',
            ],
        ]];
        yield 'OXXO' => [[
            'type' => 'oxxo_display_details',
            'oxxo_display_details' => [
                'number' => '1234567890',
                'expires_after' => 90000,
                'hosted_voucher_url' => 'https://payments.stripe.com/test/voucher',
            ],
        ]];
        yield 'PayNow' => [[
            'type' => 'paynow_display_qr_code',
            'paynow_display_qr_code' => [
                'data' => 'TEST_QR_DATA',
                'image_url_png' => 'https://payments.stripe.com/test/qr.png',
                'image_url_svg' => 'https://payments.stripe.com/test/qr.svg',
                'hosted_instructions_url' => 'https://payments.stripe.com/test/qr',
            ],
        ]];
        yield 'Konbini' => [[
            'type' => 'konbini_display_details',
            'konbini_display_details' => [
                'expires_at' => 90000,
                'hosted_voucher_url' => 'https://payments.stripe.com/test/voucher',
                'stores' => [
                    'familymart' => [
                        'payment_code' => 'TEST_CODE',
                        'confirmation_number' => 'TEST_CONFIRMATION',
                    ],
                ],
            ],
        ]];
        yield 'bank transfer without a method-name prefix' => [[
            'type' => 'display_bank_transfer_instructions',
            'display_bank_transfer_instructions' => [
                'reference' => 'TRANSFER_REFERENCE',
                'hosted_instructions_url' => 'https://payments.stripe.com/test/transfer',
                'financial_addresses' => [['type' => 'iban', 'iban' => ['iban' => 'TEST_ONLY_IBAN']]],
            ],
        ]];
        yield 'parameterless action' => [[
            'type' => 'blik_authorize',
            'blik_authorize' => [],
        ]];
        yield 'unknown action' => [[
            'type' => 'future_action',
            'future_action' => ['provider_detail' => ['new_field' => 'NEW_VALUE', 'fraction' => 0.75]],
        ]];
    }

    #[DataProvider('authenticationActions')]
    public function testExposesTransientAuthenticationActionsWithoutAddingThemToTheCanonicalSnapshot(string $actionType): void
    {
        $session = $this->session();
        $payment = $this->payment();
        $payment['payment_method'] = array_replace(OrderData::map($payment['payment_method']), ['type' => 'future_method']);
        $payment['next_action'] = [
            'type' => $actionType,
            $actionType => ['client_secret' => 'EXCLUDED_SECRET'],
        ];
        $session['payment_intent'] = $payment;
        $this->responses([$session, $this->page([$this->line(0), $this->line(1)])]);

        $observation = $this->read();
        $paymentSnapshot = $observation->payment();

        $this->assertSame('future_method', $paymentSnapshot->methodType());
        $this->assertSame($actionType, $paymentSnapshot->nextActionType());
        $this->assertSame('EXCLUDED_SECRET', $paymentSnapshot->nextAction()?->details()['client_secret']);
        $this->assertStringNotContainsString('EXCLUDED_SECRET', json_encode($observation->snapshot()->toArray(), JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('next_action', $observation->snapshot()->toArray());
    }

    /** @return iterable<string, array{string}> */
    public static function authenticationActions(): iterable
    {
        yield 'SDK authentication' => ['use_stripe_sdk'];
        yield 'redirect authentication' => ['redirect_to_url'];
    }

    public function testReadsNoCostAndExpiredSessionsWithoutInventingPaymentObjects(): void
    {
        $session = $this->session();
        $session['payment_intent'] = null;
        $session['payment_status'] = 'no_payment_required';
        $session['amount_total'] = 0;
        $session['amount_subtotal'] = 0;
        $lines = [$this->line(0), $this->line(1)];

        foreach ($lines as &$line) {
            $line['price'] = array_replace(OrderData::map($line['price']), ['unit_amount' => 0]);
            $line['amount_total'] = 0;
            $line['amount_subtotal'] = 0;
        }

        unset($line);
        $this->responses([$session, $this->page($lines)]);
        $freeLine = OrderFixture::lineItemData();
        $freeLine['price'] = '0';
        $freeLine['subtotal'] = '0';
        $freeLine['providerAmounts'] = [
            'price' => 0,
            'subtotal' => 0,
        ];
        $freeLine['requiresShipping'] = false;
        $order = OrderFixture::context([OrderLineItemSnapshot::fromArray($freeLine), OrderLineItemSnapshot::fromArray($freeLine)]);
        $observation = $this->read(order: $order);

        $this->assertTrue($observation->total()->isZero());
        $this->assertNull($observation->payment()->stripePaymentIntentId());
        $this->assertNull($observation->payment()->amount());
        $this->assertNull($observation->payment()->methodType());

        $session = $this->session();
        $session['status'] = 'expired';
        $session['payment_intent'] = null;
        $this->responses([$session, $this->page([$this->line(0), $this->line(1)])]);

        $observation = $this->read();

        $this->assertSame('expired', $observation->status());
        $this->assertNull($observation->payment()->amount());
    }

    #[DataProvider('contradictions')]
    public function testRejectsContradictoryOrIncompleteProviderFacts(string $target, string $key, mixed $value): void
    {
        $session = $this->session();
        $lines = [$this->line(0), $this->line(1)];

        if ($target === 'session') {
            $session[$key] = $value;
        } elseif ($target === 'payment') {
            $payment = $this->payment();
            $payment[$key] = $value;
            $session['payment_intent'] = $payment;
        } else {
            $lines[0][$key] = $value;
        }

        $this->responses([$session, $this->page($lines)]);
        $this->expectException(CheckoutSessionException::class);
        $this->expectExceptionMessage(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        $this->read();
    }

    /** @return iterable<string, array{string, string, mixed}> */
    public static function contradictions(): iterable
    {
        yield 'different Session' => ['session', 'id', 'cs_other'];
        yield 'foreign owner' => ['session', 'metadata', []];
        yield 'different order reference' => ['session', 'client_reference_id', 'page://other'];
        yield 'wrong mode' => ['session', 'livemode', true];
        yield 'different currency' => ['session', 'currency', 'usd'];
        yield 'incomplete total' => ['session', 'amount_total', null];
        yield 'uncorrelated line' => ['line', 'metadata', []];
        yield 'changed quantity' => ['line', 'quantity', 1];
        yield 'wrong subtotal' => ['line', 'amount_subtotal', 1];
        yield 'incomplete allocation' => ['line', 'amount_tax', null];
        yield 'foreign PaymentIntent' => ['payment', 'metadata', []];
        yield 'wrong PaymentIntent amount' => ['payment', 'amount', 1];
        yield 'unexpanded PaymentIntent' => ['session', 'payment_intent', 'pi_current'];
        yield 'missing completed payment' => ['session', 'payment_intent', null];
        yield 'missing action discriminator' => ['payment', 'next_action', ['future_action' => []]];
        yield 'missing active action branch' => ['payment', 'next_action', ['type' => 'future_action']];
        yield 'action branch is a list' => ['payment', 'next_action', [
            'type' => 'future_action',
            'future_action' => ['not an object'],
        ]];
    }

    public function testRejectsRepeatedAndTruncatedPaginationInsteadOfReturningACompletePrefix(): void
    {
        $this->responses([$this->session(), $this->page([$this->line(0)], true), $this->page([$this->line(0)])]);

        try {
            $this->read();
            $this->fail('A repeated line must not be accepted as another page.');
        } catch (CheckoutSessionException $error) {
            $this->assertSame(CheckoutErrorCode::SESSION_INCOMPATIBLE, $error->errorCode());
        }

        $this->responses([$this->session(), $this->page([], true)]);
        $this->expectException(CheckoutSessionException::class);
        $this->expectExceptionMessage(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        $this->read();
    }

    public function testReturnsNoObservationWhenALaterProviderReadFails(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->exactly(3))->method('request')->willReturnOnConsecutiveCalls(
            [json_encode($this->session(), JSON_THROW_ON_ERROR), 200, []],
            [json_encode($this->page([$this->line(0)], true), JSON_THROW_ON_ERROR), 200, []],
            $this->throwException(new RuntimeException('EXCLUDED PROVIDER MESSAGE')),
        );
        ApiRequestor::setHttpClient($client);
        $this->expectException(CheckoutSessionException::class);
        $this->expectExceptionMessage(CheckoutErrorCode::SESSION_UNAVAILABLE);
        $this->read();
    }

    private function read(?OrderCreationContext $order = null, ?CheckoutSessionAssociation $association = null, ?SessionRequest $request = null): CheckoutSessionObservation
    {
        $gateway = new StripeApiCheckoutSessionGateway((new StripeApiClientFactory())->create(new StripeConfiguration('sk_test_retrieval', null, null)));

        return (new CheckoutSessionRetriever($gateway))->retrieve(
            sessionId: 'cs_current',
            order: $order ?? $this->order(),
            request: $request ?? $this->request(),
            credentialMode: CredentialMode::Test,
            association: $association,
        );
    }

    private function order(): OrderCreationContext
    {
        $line = OrderFixture::lineItemData();
        $line['requiresShipping'] = false;

        return OrderFixture::context([OrderLineItemSnapshot::fromArray($line), OrderLineItemSnapshot::fromArray($line)]);
    }

    private function request(): SessionRequest
    {
        return new SessionRequest([
            'mode' => 'payment',
            'expires_at' => 87400,
            'integration_identifier' => 'kirby_stripe_checkout_abcdefgh',
            'metadata' => $this->metadata(),
            'payment_intent_data' => ['metadata' => $this->metadata()],
            'line_items' => [
                [
                    'quantity' => 2,
                    'metadata' => $this->metadata(0),
                ],
                [
                    'quantity' => 2,
                    'metadata' => $this->metadata(1),
                ],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function session(): array
    {
        return [
            'id' => 'cs_current',
            'object' => 'checkout.session',
            'created' => 1000,
            'expires_at' => 87400,
            'currency' => 'eur',
            'mode' => 'payment',
            'ui_mode' => 'hosted_page',
            'status' => 'complete',
            'payment_status' => 'unpaid',
            'livemode' => false,
            'client_reference_id' => 'page://Abc123def456GHI7',
            'integration_identifier' => 'kirby_stripe_checkout_abcdefgh',
            'metadata' => $this->metadata(),
            'amount_subtotal' => 6400,
            'amount_total' => 6400,
            'automatic_tax' => [
                'enabled' => false,
                'status' => null,
            ],
            'total_details' => [
                'amount_discount' => 0,
                'amount_shipping' => 0,
                'amount_tax' => 0,
                'breakdown' => ['discounts' => []],
            ],
            'shipping_options' => [],
            'payment_intent' => $this->payment(),
            'invoice' => 'in_current',
        ];
    }

    /** @return array<string, mixed> */
    private function payment(): array
    {
        return [
            'id' => 'pi_current',
            'object' => 'payment_intent',
            'created' => 1000,
            'livemode' => false,
            'metadata' => $this->metadata(),
            'currency' => 'eur',
            'amount' => 6400,
            'amount_received' => 0,
            'capture_method' => 'automatic',
            'status' => 'requires_action',
            'client_secret' => 'EXCLUDED_SECRET',
            'payment_method_types' => ['card', 'multibanco'],
            'payment_method' => [
                'id' => 'pm_current',
                'object' => 'payment_method',
                'type' => 'multibanco',
            ],
            'latest_charge' => null,
            'next_action' => [
                'type' => 'multibanco_display_details',
                'multibanco_display_details' => [
                    'entity' => '12345',
                    'reference' => '123456789',
                    'expires_at' => 90000,
                    'hosted_voucher_url' => 'https://payments.stripe.com/test/voucher',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function line(int $index): array
    {
        return [
            'id' => 'li_' . $index,
            'object' => 'item',
            'metadata' => $this->metadata($index),
            'quantity' => 2,
            'currency' => 'eur',
            'description' => 'Archived product name',
            'amount_subtotal' => 3200,
            'amount_discount' => 0,
            'amount_tax' => 0,
            'amount_total' => 3200,
            'price' => [
                'id' => 'price_' . $index,
                'object' => 'price',
                'active' => false,
                'currency' => 'eur',
                'unit_amount' => 1600,
                'product' => 'prod_archived',
                'billing_scheme' => 'per_unit',
                'type' => 'one_time',
            ],
            'discounts' => [],
            'taxes' => [],
        ];
    }

    /** @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function page(array $lines, bool $hasMore = false): array
    {
        return [
            'object' => 'list',
            'data' => $lines,
            'has_more' => $hasMore,
            'url' => '/v1/checkout/sessions/cs_current/line_items',
        ];
    }

    /** @return array<string, string> */
    private function metadata(?int $index = null): array
    {
        $metadata = [
            PluginMetadata::OWNER_KEY => PluginMetadata::NAME,
            PluginMetadata::ORDER_KEY => 'page://Abc123def456GHI7',
        ];

        if ($index !== null) {
            $metadata[PluginMetadata::LINE_KEY] = 'line_' . $index;
        }

        return $metadata;
    }

    /** @param list<array<string, mixed>> $responses */
    private function responses(array $responses): void
    {
        $requests = &$this->requests;
        $requests = [];
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturnCallback(static function (...$arguments) use (&$responses, &$requests): array {
            $requests[] = OrderData::map($arguments[3]);
            $response = array_shift($responses) ?? throw new RuntimeException('Unexpected provider call.');

            return [json_encode($response, JSON_THROW_ON_ERROR), 200, []];
        });
        ApiRequestor::setHttpClient($client);
    }
}
