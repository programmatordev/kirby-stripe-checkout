<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Checkout;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\InvalidSessionRequestException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionRequestValidator;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;

final class SessionRequestValidatorTest extends TestCase
{
    public function testAddsOnlyProjectMetadataAndSafePaymentIntentFields(): void
    {
        $request = (new SessionRequestValidator())->applyAdditions($this->standardRequest(), [
            'metadata' => ['customer_reference' => 'customer-42'],
            'payment_intent_data' => [
                'description' => 'Order ORD-TEST',
                'metadata' => ['warehouse' => 'west'],
                'receipt_email' => 'buyer@example.com',
                'statement_descriptor_suffix' => 'ORDER',
            ],
        ]);
        $parameters = $request->parameters();

        $this->assertIsArray($parameters['metadata']);
        $this->assertIsArray($parameters['payment_intent_data']);
        $this->assertIsArray($parameters['payment_intent_data']['metadata']);
        $this->assertSame('customer-42', $parameters['metadata']['customer_reference'] ?? null);
        $this->assertSame('Order ORD-TEST', $parameters['payment_intent_data']['description'] ?? null);
        $this->assertSame('west', $parameters['payment_intent_data']['metadata']['warehouse'] ?? null);
        $this->assertSame('buyer@example.com', $parameters['payment_intent_data']['receipt_email'] ?? null);
        $this->assertSame('ORDER', $parameters['payment_intent_data']['statement_descriptor_suffix'] ?? null);
    }

    /** @param array<mixed, mixed> $additions */
    #[DataProvider('invalidAdditions')]
    public function testRejectsProtectedUnsupportedAndInvalidAdditions(
        array $additions,
        string $errorCode,
        ?string $path,
    ): void {
        try {
            (new SessionRequestValidator())->applyAdditions($this->standardRequest(), $additions);
            $this->fail('Expected the additions to be rejected.');
        } catch (InvalidSessionRequestException $error) {
            $this->assertSame($errorCode, $error->errorCode());
            $this->assertSame($path, $error->path());
        }
    }

    /** @return iterable<string, array{array<mixed, mixed>, string, string|null}> */
    public static function invalidAdditions(): iterable
    {
        $tooManyMetadataEntries = [];

        for ($index = 0; $index < 48; $index++) {
            $tooManyMetadataEntries['key_' . $index] = 'value';
        }

        yield 'built-in field' => [
            ['mode' => 'payment'],
            'session_request.parameter_protected',
            'mode',
        ];
        yield 'private metadata' => [
            ['metadata' => ['kirby_stripe_checkout_order' => 'page://different']],
            'session_request.additions_invalid',
            'metadata.kirby_stripe_checkout_order',
        ];
        yield 'duplicate project metadata' => [
            ['metadata' => ['project_reference' => 'duplicate']],
            'session_request.parameter_protected',
            'metadata.project_reference',
        ];
        yield 'unsupported root' => [
            ['invoice_creation' => ['enabled' => true]],
            'session_request.parameter_unsupported',
            'invoice_creation',
        ];
        yield 'unsupported PaymentIntent field' => [
            ['payment_intent_data' => ['capture_method' => 'manual']],
            'session_request.parameter_unsupported',
            'payment_intent_data.capture_method',
        ];
        yield 'invalid receipt email' => [
            ['payment_intent_data' => ['receipt_email' => 'invalid']],
            'session_request.additions_invalid',
            'payment_intent_data.receipt_email',
        ];
        yield 'statement suffix is too long' => [
            ['payment_intent_data' => ['statement_descriptor_suffix' => str_repeat('x', 23)]],
            'session_request.additions_invalid',
            'payment_intent_data.statement_descriptor_suffix',
        ];
        yield 'invalid metadata value' => [
            ['metadata' => ['project_reference' => 42]],
            'session_request.additions_invalid',
            'metadata.project_reference',
        ];
        yield 'invalid UTF-8 metadata key' => [
            ['metadata' => ["\xB1" => 'value']],
            'session_request.additions_invalid',
            null,
        ];
        yield 'too many metadata entries' => [
            ['metadata' => $tooManyMetadataEntries],
            'session_request.additions_invalid',
            'metadata',
        ];
        yield 'metadata key is too long' => [
            ['metadata' => [str_repeat('k', 41) => 'value']],
            'session_request.additions_invalid',
            'metadata.' . str_repeat('k', 41),
        ];
        yield 'metadata key contains brackets' => [
            ['metadata' => ['project[key]' => 'value']],
            'session_request.additions_invalid',
            'metadata.project[key]',
        ];
        yield 'metadata value is too long' => [
            ['metadata' => ['customer_reference' => str_repeat('v', 501)]],
            'session_request.additions_invalid',
            'metadata.customer_reference',
        ];
    }

    public function testTreatsEmptyNestedAdditionsAsNoOps(): void
    {
        $request = $this->standardRequest();
        $customizedRequest = (new SessionRequestValidator())->applyAdditions($request, [
            'metadata' => [],
            'payment_intent_data' => [],
        ]);

        $this->assertSame($request->parameters(), $customizedRequest->parameters());
    }

    public function testAllowsAdvancedOneTimeConstructionInsideTheSafetyFloor(): void
    {
        $parameters = $this->standardRequest()->parameters();
        $parameters['automatic_tax'] = ['enabled' => true];
        $parameters['invoice_creation'] = ['enabled' => true];
        $this->assertIsArray($parameters['line_items']);
        $this->assertIsArray($parameters['line_items'][0]);
        $parameters['line_items'][0]['tax_rates'] = ['txr_custom'];
        $request = new SessionRequest($parameters);

        $this->assertSame(
            $request,
            (new SessionRequestValidator())->validate($this->standardRequest(), $request),
        );
    }

    #[DataProvider('unsafeFactoryRequests')]
    public function testRejectsFactoryRequestsOutsideTheSafetyFloor(
        callable $change,
        string $path,
    ): void {
        $parameters = $this->standardRequest()->parameters();
        $change($parameters);

        try {
            (new SessionRequestValidator())->validate(
                $this->standardRequest(),
                new SessionRequest($parameters),
            );
            $this->fail('Expected the replacement request to be rejected.');
        } catch (InvalidSessionRequestException $error) {
            $this->assertSame($path, $error->path());
        }
    }

    /** @return iterable<string, array{callable(array<string, mixed>&): void, string}> */
    public static function unsafeFactoryRequests(): iterable
    {
        yield 'subscription mode' => [
            static function (array &$parameters): void {
                $parameters['mode'] = 'subscription';
            },
            'mode',
        ];
        yield 'other currency' => [
            static function (array &$parameters): void {
                $parameters['currency'] = 'usd';
            },
            'currency',
        ];
        yield 'other success URL' => [
            static function (array &$parameters): void {
                $parameters['success_url'] = 'https://example.com/success';
            },
            'success_url',
        ];
        yield 'missing Session metadata' => [
            static function (array &$parameters): void {
                assert(is_array($parameters['metadata']));
                unset($parameters['metadata']['kirby_stripe_checkout_order']);
            },
            'metadata.kirby_stripe_checkout_order',
        ];
        yield 'different line quantity' => [
            static function (array &$parameters): void {
                assert(is_array($parameters['line_items']));
                assert(is_array($parameters['line_items'][0]));
                $parameters['line_items'][0]['quantity'] = 2;
            },
            'line_items.0.quantity',
        ];
        yield 'second line' => [
            static function (array &$parameters): void {
                assert(is_array($parameters['line_items']));
                assert(is_array($parameters['line_items'][0]));
                $parameters['line_items'][] = $parameters['line_items'][0];
            },
            'line_items',
        ];
        yield 'manual capture' => [
            static function (array &$parameters): void {
                assert(is_array($parameters['payment_intent_data']));
                $parameters['payment_intent_data']['capture_method'] = 'manual';
            },
            'payment_intent_data.capture_method',
        ];
        yield 'future payment method' => [
            static function (array &$parameters): void {
                $parameters['payment_method_options'] = [
                    'card' => ['setup_future_usage' => 'off_session'],
                ];
            },
            'payment_method_options.card.setup_future_usage',
        ];
        yield 'Connect transfer' => [
            static function (array &$parameters): void {
                assert(is_array($parameters['payment_intent_data']));
                $parameters['payment_intent_data']['transfer_data'] = [
                    'destination' => 'acct_test',
                ];
            },
            'payment_intent_data.transfer_data',
        ];
        yield 'explicit payment methods' => [
            static function (array &$parameters): void {
                $parameters['payment_method_types'] = ['card'];
            },
            'payment_method_types',
        ];
        yield 'recurring inline price' => [
            static function (array &$parameters): void {
                assert(is_array($parameters['line_items']));
                assert(is_array($parameters['line_items'][0]));
                assert(is_array($parameters['line_items'][0]['price_data']));
                $parameters['line_items'][0]['price_data']['recurring'] = [
                    'interval' => 'month',
                ];
            },
            'line_items.0.price_data.recurring',
        ];
        yield 'private metadata outside correlation maps' => [
            static function (array &$parameters): void {
                $parameters['invoice_creation'] = [
                    'enabled' => true,
                    'invoice_data' => [
                        'metadata' => ['kirby_stripe_checkout_order' => 'forged'],
                    ],
                ];
            },
            'invoice_creation.invoice_data.metadata.kirby_stripe_checkout_order',
        ];
    }

    private function standardRequest(): SessionRequest
    {
        return new SessionRequest([
            'cancel_url' => 'https://example.com/stripe-checkout/cancel',
            'client_reference_id' => 'page://Order123',
            'currency' => 'eur',
            'expires_at' => 1_789_200_000,
            'integration_identifier' => 'kirby_stripe_checkout_abcdefgh',
            'line_items' => [[
                'metadata' => [
                    'kirby_stripe_checkout_line' => 'line-hash',
                    'kirby_stripe_checkout_order' => 'page://Order123',
                    'kirby_stripe_checkout_owner' => 'programmatordev/stripe-checkout',
                ],
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => ['name' => 'Product'],
                    'unit_amount' => 1_000,
                ],
                'quantity' => 1,
            ]],
            'locale' => 'en',
            'metadata' => [
                'kirby_stripe_checkout_order' => 'page://Order123',
                'kirby_stripe_checkout_owner' => 'programmatordev/stripe-checkout',
                'project_reference' => 'original',
            ],
            'mode' => 'payment',
            'payment_intent_data' => [
                'metadata' => [
                    'kirby_stripe_checkout_order' => 'page://Order123',
                    'kirby_stripe_checkout_owner' => 'programmatordev/stripe-checkout',
                ],
            ],
            'success_url' => 'https://example.com/stripe-checkout/success?session_id={CHECKOUT_SESSION_ID}',
            'ui_mode' => 'hosted_page',
        ]);
    }
}
