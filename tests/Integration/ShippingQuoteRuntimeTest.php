<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Shipping\Exception\InvalidShippingQuoteException;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingOption;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use RuntimeException;

final class ShippingQuoteRuntimeTest extends KirbyTestCase
{
    private const PREFIX = 'programmatordev.stripe-checkout';

    public function testRuntimeUsesTheConfiguredZonesByDefault(): void
    {
        $this->restart([
            self::PREFIX => [
                'settings' => [
                    'currency' => 'EUR',
                    'shippingZones' => [[
                        'name' => 'Portugal',
                        'scope' => 'selected_countries',
                        'countries' => ['PT'],
                        'options' => [[
                            'key' => 'standard',
                            'label' => 'Standard delivery',
                            'amount' => '4.90',
                        ]],
                    ]],
                ],
            ],
        ]);

        $quote = (new RuntimeFactory($this->kirby))->resolveShippingQuote(
            self::checkout(),
            self::shipping(),
        );

        $this->assertNotNull($quote);
        $this->assertSame('standard', $quote->options()[0]->key());
        $this->assertSame('4.90', (string) $quote->options()[0]->amount()->getAmount());
    }

    public function testConfiguredResolverReplacesTheBuiltInZones(): void
    {
        $receivedCheckout = null;
        $receivedShipping = null;
        $this->restart([
            self::PREFIX => [
                'settings' => [
                    'currency' => 'EUR',
                    'shippingZones' => [[
                        'name' => 'Portugal',
                        'scope' => 'selected_countries',
                        'countries' => ['PT'],
                        'options' => [[
                            'key' => 'standard',
                            'label' => 'Standard delivery',
                            'amount' => '4.90',
                        ]],
                    ]],
                ],
                'shipping' => [
                    'resolver' => static function (
                        CheckoutContext $checkout,
                        ShippingContext $shipping,
                    ) use (&$receivedCheckout, &$receivedShipping): ShippingQuote {
                        $receivedCheckout = $checkout;
                        $receivedShipping = $shipping;

                        return ShippingQuote::available([
                            new ShippingOption(
                                key: 'custom',
                                label: 'Custom carrier',
                                amount: Money::of('8.00', 'EUR'),
                            ),
                        ]);
                    },
                ],
            ],
        ]);
        $checkout = self::checkout();
        $shipping = self::shipping();

        $quote = (new RuntimeFactory($this->kirby))->resolveShippingQuote(
            $checkout,
            $shipping,
        );

        $this->assertSame($checkout, $receivedCheckout);
        $this->assertSame($shipping, $receivedShipping);
        $this->assertNotNull($quote);
        $this->assertSame('custom', $quote->options()[0]->key());
    }

    public function testQuoteFilterCanDecorateTheResolvedQuote(): void
    {
        $received = null;
        $this->restart([
            self::PREFIX => [
                'settings' => [
                    'currency' => 'EUR',
                    'shippingZones' => [[
                        'name' => 'Portugal',
                        'scope' => 'selected_countries',
                        'countries' => ['PT'],
                        'options' => [[
                            'key' => 'standard',
                            'label' => 'Standard delivery',
                            'amount' => '4.90',
                        ]],
                    ]],
                ],
            ],
        ], hooks: [
            'programmatordev.stripe-checkout.shipping.quote' => function (
                ShippingQuote $quote,
                CheckoutContext $checkout,
                ShippingContext $shipping,
            ) use (&$received): ShippingQuote {
                $received = [$quote, $checkout, $shipping];

                return ShippingQuote::available([
                    new ShippingOption(
                        key: 'free',
                        label: 'Free delivery',
                        amount: Money::zero($checkout->currency()),
                    ),
                ]);
            },
        ]);
        $checkout = self::checkout();
        $shipping = self::shipping();

        $quote = (new RuntimeFactory($this->kirby))->resolveShippingQuote(
            $checkout,
            $shipping,
        );

        $this->assertNotNull($received);
        $this->assertSame('standard', $received[0]->options()[0]->key());
        $this->assertSame($checkout, $received[1]);
        $this->assertSame($shipping, $received[2]);
        $this->assertNotNull($quote);
        $this->assertSame('free', $quote->options()[0]->key());
    }

    public function testQuoteFilterOutputUsesTheCheckoutCurrency(): void
    {
        $this->restart(self::zoneConfiguration(), hooks: [
            'programmatordev.stripe-checkout.shipping.quote' => function (): ShippingQuote {
                return ShippingQuote::available([
                    new ShippingOption(
                        key: 'invalid',
                        label: 'Invalid currency',
                        amount: Money::of('5.00', 'USD'),
                    ),
                ]);
            },
        ]);

        try {
            (new RuntimeFactory($this->kirby))->resolveShippingQuote(
                self::checkout(),
                self::shipping(),
            );
            self::fail('The decorated quote should use the Checkout currency.');
        } catch (InvalidShippingQuoteException $error) {
            $this->assertSame(ShippingErrorCode::CURRENCY_MISMATCH, $error->errorCode());
        }
    }

    public function testQuoteFilterRequiresAShippingQuote(): void
    {
        $this->restart(self::zoneConfiguration(), hooks: [
            'programmatordev.stripe-checkout.shipping.quote' => function (): array {
                return [];
            },
        ]);

        try {
            (new RuntimeFactory($this->kirby))->resolveShippingQuote(
                self::checkout(),
                self::shipping(),
            );
            self::fail('The quote filter should return a ShippingQuote.');
        } catch (InvalidShippingQuoteException $error) {
            $this->assertSame(ShippingErrorCode::FILTER_INVALID, $error->errorCode());
        }
    }

    public function testQuoteFilterFailuresAreSanitized(): void
    {
        $this->restart(self::zoneConfiguration(), hooks: [
            'programmatordev.stripe-checkout.shipping.quote' => function (): never {
                throw new RuntimeException('Private carrier response.');
            },
        ]);

        try {
            (new RuntimeFactory($this->kirby))->resolveShippingQuote(
                self::checkout(),
                self::shipping(),
            );
            self::fail('The quote filter failure should be normalized.');
        } catch (InvalidShippingQuoteException $error) {
            $this->assertSame(ShippingErrorCode::FILTER_FAILED, $error->errorCode());
            $this->assertStringNotContainsString('Private carrier response', $error->getMessage());
        }
    }

    private static function checkout(): CheckoutContext
    {
        return new CheckoutContext(
            items: [new CheckoutLineItem(
                productReference: 'page://product',
                variantId: null,
                sku: null,
                quantity: 1,
                price: Money::of('20.00', 'EUR'),
                subtotal: Money::of('20.00', 'EUR'),
                requiresShipping: true,
            )],
            languageCode: 'en',
            locale: 'en_US',
            userUuid: null,
            checkoutSource: CheckoutSource::Direct,
            uiMode: UiMode::Hosted,
        );
    }

    private static function shipping(): ShippingContext
    {
        return new ShippingContext(
            allowedCountries: ['PT'],
            destinationCountry: 'PT',
        );
    }

    /** @return array<string, mixed> */
    private static function zoneConfiguration(): array
    {
        return [
            self::PREFIX => [
                'settings' => [
                    'currency' => 'EUR',
                    'shippingZones' => [[
                        'name' => 'Portugal',
                        'scope' => 'selected_countries',
                        'countries' => ['PT'],
                        'options' => [[
                            'key' => 'standard',
                            'label' => 'Standard delivery',
                            'amount' => '4.90',
                        ]],
                    ]],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, callable|list<callable>> $hooks
     */
    private function restart(array $options, array $hooks = []): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start($options, hooks: $hooks);
        $this->kirby = $this->environment->app();
    }
}
