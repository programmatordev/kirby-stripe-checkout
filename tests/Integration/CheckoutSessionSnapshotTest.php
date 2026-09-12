<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionRecord;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Internal\CheckoutSessionSnapshotNormalizer;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\Stripe\FakeCheckoutSessionGateway;

final class CheckoutSessionSnapshotTest extends KirbyTestCase
{
    public function testNormalizesTheCurrentFakeGatewayRetrievalInsteadOfRequestSettings(): void
    {
        $sessionRecord = new CheckoutSessionRecord(
            id: 'cs_current',
            createdAt: 1,
            expiresAt: 2,
            status: 'complete',
            paymentStatus: 'paid',
            liveMode: false,
            mode: 'payment',
            uiMode: 'hosted_page',
            currency: 'eur',
            clientReferenceId: 'page://order',
            integrationIdentifier: 'kirby_stripe_checkout_test',
            metadata: [],
            requestId: 'req_current',
            url: null,
            clientSecret: null,
            orderSnapshotSource: [
                'customer_details' => [
                    'address' => null,
                    'business_name' => null,
                    'email' => 'returned@example.test',
                    'individual_name' => 'Returned Name',
                    'phone' => null,
                    'tax_ids' => [],
                ],
                'custom_fields' => [[
                    'key' => 'reference',
                    'label' => [
                        'custom' => 'Returned label',
                        'type' => 'custom',
                    ],
                    'optional' => true,
                    'text' => ['value' => 'Returned value'],
                    'type' => 'text',
                ]],
            ],
        );
        $gateway = new FakeCheckoutSessionGateway(retrievalResults: [
            'cs_current' => $sessionRecord,
        ]);
        $currentSession = $gateway->retrieve('cs_current');
        $snapshots = (new CheckoutSessionSnapshotNormalizer())->normalize($currentSession);

        $this->assertSame(['cs_current'], $gateway->retrievals);
        $this->assertSame('returned@example.test', $snapshots['customer']['email'] ?? null);
        $this->assertSame('Returned label', $snapshots['customFields'][0]['label'] ?? null);
        $this->assertSame('Returned value', $snapshots['customFields'][0]['value'] ?? null);
    }
}
