<?php

namespace ProgrammatorDev\StripeCheckout;

use Brick\Math\Exception\MathException;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Money\Exception\UnknownCurrencyException;
use Kirby\Cms\Page;
use Kirby\Uuid\Uuid;
use ProgrammatorDev\StripeCheckout\Exception\CartIsEmptyException;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Validation;

class StripeCheckout
{
    private array $options;

    private StripeClient $stripe;

    private ?Page $shippingPage;

    public function __construct(array $options)
    {
        $this->options = $this->resolveOptions($options);
        $this->stripe = new StripeClient($this->options['stripeSecretKey']);
        $this->shippingPage = kirby()->page('shipping');
    }

    private function resolveOptions(array $options): array
    {
        $resolver = new OptionsResolver();
        $resolver->setIgnoreUndefined();

        $resolver->setRequired(['stripePublicKey', 'stripeSecretKey', 'stripeWebhookSecret', 'currency', 'uiMode']);

        $resolver->setAllowedTypes('stripePublicKey', 'string');
        $resolver->setAllowedTypes('stripeSecretKey', 'string');
        $resolver->setAllowedTypes('stripeWebhookSecret', 'string');
        $resolver->setAllowedTypes('currency', 'string');
        $resolver->setAllowedTypes('uiMode', 'string');

        $resolver->setAllowedValues('stripePublicKey', Validation::createIsValidCallable(new NotBlank()));
        $resolver->setAllowedValues('stripeSecretKey', Validation::createIsValidCallable(new NotBlank()));
        $resolver->setAllowedValues('stripeWebhookSecret', Validation::createIsValidCallable(new NotBlank()));
        $resolver->setAllowedValues('currency', Currencies::getCurrencyCodes());
        $resolver->setAllowedValues('uiMode', [Session::UI_MODE_HOSTED, Session::UI_MODE_EMBEDDED]);

        // https://docs.stripe.com/currencies#presentment-currencies
        $resolver->setNormalizer('currency', function (Options $options, string $currency): string {
            return strtoupper($currency);
        });

        $uiMode = $options['uiMode'] ?? null;

        if ($uiMode === Session::UI_MODE_HOSTED) {
            $resolver->setRequired(['successUrl', 'cancelUrl']);

            $resolver->setAllowedTypes('successUrl', 'string');
            $resolver->setAllowedTypes('cancelUrl', 'string');

            $resolver->setAllowedValues('successUrl', Validation::createIsValidCallable(new NotBlank(), new Url()));
            $resolver->setAllowedValues('cancelUrl', Validation::createIsValidCallable(new NotBlank(), new Url()));

            $resolver->setNormalizer('successUrl', function (Options $options, string $successUrl): string {
                return $this->addSessionIdToUrlQuery($successUrl);
            });
        }
        else if ($uiMode === Session::UI_MODE_EMBEDDED) {
            $resolver->setRequired(['returnUrl']);

            $resolver->setAllowedTypes('returnUrl', 'string');
            $resolver->setAllowedValues('returnUrl', Validation::createIsValidCallable(new NotBlank(), new Url()));

            $resolver->setNormalizer('returnUrl', function (Options $options, string $returnUrl): string {
                return $this->addSessionIdToUrlQuery($returnUrl);
            });
        }

        return $resolver->resolve($options);
    }

    /**
     * @throws ApiErrorException
     * @throws CartIsEmptyException
     * @throws MathException
     * @throws NumberFormatException
     * @throws RoundingNecessaryException
     * @throws UnknownCurrencyException
     */
    public function createSession(Cart $cart): Session
    {
        if ($cart->getTotalQuantity() === 0) {
            throw new CartIsEmptyException('Cart is empty.');
        }

        $uiMode = $this->options['uiMode'];

        // set base session params
        $sessionParams = [
            'mode' => Session::MODE_PAYMENT,
            'ui_mode' => $uiMode,
            'line_items' => $this->getLineItems($cart),
            'metadata' => [
                // generate a unique id for the order
                // required in webhooks to sync the different event payment steps
                'order_id' => Uuid::generate()
            ]
        ];

        // add session params according to uiMode
        if ($uiMode === Session::UI_MODE_HOSTED) {
            $sessionParams['success_url'] = $this->options['successUrl'];
            $sessionParams['cancel_url'] = $this->options['cancelUrl'];
        }
        else if ($uiMode === Session::UI_MODE_EMBEDDED) {
            $sessionParams['return_url'] = $this->options['returnUrl'];
        }

        // add shipping params if page exists and is enabled
        // https://docs.stripe.com/payments/during-payment/charge-shipping?payment-ui=checkout&lang=php
        if ($this->shippingPage !== null && $this->shippingPage->enabled()->toBool() === true) {
            $sessionParams['shipping_address_collection']['allowed_countries'] = $this->shippingPage->allowedCountries()->split();
            $sessionParams['shipping_options'] = $this->getShippingOptions();
        }

        // trigger event to allow session parameters manipulation
        // https://docs.stripe.com/api/checkout/sessions/create?lang=php
        $sessionParams = kirby()->apply(
            'stripe.checkout.sessionCreate:before',
            compact('sessionParams'),
            'sessionParams'
        );

        return $this->stripe->checkout->sessions->create($sessionParams);
    }

    /**
     * @throws ApiErrorException
     */
    public function retrieveSession(string $sessionId, array $params = [], array $options = []): Session
    {
        return $this->stripe->checkout->sessions->retrieve($sessionId, $params, $options);
    }

    /**
     * @throws SignatureVerificationException
     */
    public function constructWebhookEvent(string $payload, string $sigHeader): Event
    {
        return Webhook::constructEvent($payload, $sigHeader, $this->options['stripeWebhookSecret']);
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getStripePublicKey(): string
    {
        return $this->options['stripePublicKey'];
    }

    public function getStripeSecretKey(): string
    {
        return $this->options['stripeSecretKey'];
    }

    public function getStripeWebhookSecret(): string
    {
        return $this->options['stripeWebhookSecret'];
    }

    public function getCurrency(): string
    {
        return $this->options['currency'];
    }

    public function getUiMode(): string
    {
        return $this->options['uiMode'];
    }

    public function getReturnUrl(): ?string
    {
        return $this->options['returnUrl'] ?? null;
    }

    public function getSuccessUrl(): ?string
    {
        return $this->options['successUrl'] ?? null;
    }

    public function getCancelUrl(): ?string
    {
        return $this->options['cancelUrl'] ?? null;
    }

    /**
     * @throws RoundingNecessaryException
     * @throws MathException
     * @throws UnknownCurrencyException
     * @throws NumberFormatException
     */
    protected function getLineItems(Cart $cart): array
    {
        $currency = $this->options['currency'];
        $lineItems = [];

        foreach ($cart->getItems() as $item) {
            // initial product data
            $productData = [
                'name' => $item['name'],
                'images' => [$item['image']]
            ];

            if (!empty($item['options'])) {
                $description = [];

                foreach ($item['options'] as $name => $value) {
                    $description[] = sprintf('%s: %s', $name, $value);
                }

                // only add description key if there are options
                $productData['description'] = implode(', ', $description);
            }

            // convert to Stripe line_items data
            // https://docs.stripe.com/api/checkout/sessions/create?lang=php#create_checkout_session-line_items
            $lineItems[] = [
                'price_data' => [
                    // present currency in lowercase
                    // https://docs.stripe.com/currencies#presentment-currencies
                    'currency' => strtolower($currency),
                    // Stripe expects amounts to be provided in the currency smallest unit
                    // https://docs.stripe.com/currencies#zero-decimal
                    'unit_amount' => MoneyFormatter::toMinorUnit($item['price'], $currency),
                    'product_data' => $productData
                ],
                'quantity' => $item['quantity'],
            ];
        }

        return $lineItems;
    }

    /**
     * @throws RoundingNecessaryException
     * @throws MathException
     * @throws UnknownCurrencyException
     * @throws NumberFormatException
     */
    private function getShippingOptions(): array
    {
        // if there is no shipping page, return empty
        if ($this->shippingPage === null) {
            return [];
        }

        $currency = $this->options['currency'];
        $shippingOptions = [];

        // get shipping rates and handle them if they exist
        foreach ($this->shippingPage->shippingRates()->toStructure() as $shippingRate) {
            $shippingRateData = [
                'type' => 'fixed_amount',
                'fixed_amount' => [
                    'amount' => MoneyFormatter::toMinorUnit($shippingRate->amount()->value(), $currency),
                    'currency' => $currency,
                ],
                'display_name' => $shippingRate->name()->value()
            ];

            // add minimum delivery estimate if it exists
            if ($shippingRate->deliveryEstimateMinimumValue()->value() !== '') {
                $shippingRateData['delivery_estimate']['minimum'] = [
                    'unit' => $shippingRate->deliveryEstimateMinimumUnit()->value(),
                    'value' => $shippingRate->deliveryEstimateMinimumValue()->value(),
                ];
            }

            // add maximum delivery estimate if it exists
            if ($shippingRate->deliveryEstimateMaximumValue()->value() !== '') {
                $shippingRateData['delivery_estimate']['maximum'] = [
                    'unit' => $shippingRate->deliveryEstimateMaximumUnit()->value(),
                    'value' => $shippingRate->deliveryEstimateMaximumValue()->value(),
                ];
            }

            // add shipping rate to shipping options
            $shippingOptions[] = ['shipping_rate_data' => $shippingRateData];
        }

        return $shippingOptions;
    }

    protected function addSessionIdToUrlQuery(string $url): string
    {
        // always include the {CHECKOUT_SESSION_ID} template variable in the URL
        // https://docs.stripe.com/checkout/embedded/quickstart#return-url
        $url .= (parse_url($url, PHP_URL_QUERY) ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}';

        return $url;
    }
}
