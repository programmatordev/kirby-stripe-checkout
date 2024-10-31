<?php

namespace ProgrammatorDev\StripeCheckout;

use Brick\Math\Exception\MathException;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Money\Exception\UnknownCurrencyException;
use Kirby\Cms\Page;
use Kirby\Uuid\Uuid;
use ProgrammatorDev\StripeCheckout\Exception\CheckoutSessionException;
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
use Symfony\Component\Validator\Validation;

class StripeCheckout
{
    private array $options;

    private StripeClient $stripe;

    private ?Page $settingsPage;

    public function __construct(array $options)
    {
        $this->options = $this->resolveOptions($options);
        $this->stripe = new StripeClient($this->options['stripeSecretKey']);
        $this->settingsPage = page($this->options['settingsPage']);
    }

    /**
     * @throws ApiErrorException
     * @throws CheckoutSessionException
     * @throws MathException
     * @throws NumberFormatException
     * @throws RoundingNecessaryException
     * @throws UnknownCurrencyException
     */
    public function createSession(Cart $cart): Session
    {
        if ($cart->getTotalQuantity() === 0) {
            throw new CheckoutSessionException('Cart is empty.');
        }

        // get current user language
        $languageCode = kirby()->language()?->code();

        // set base session params
        $sessionParams = [
            'mode' => Session::MODE_PAYMENT,
            'ui_mode' => $this->options['uiMode'],
            'line_items' => $this->getLineItems($cart),
            'metadata' => [
                // generate a unique id for the order
                // required in webhooks to sync the different event payment steps
                'order_id' => Uuid::generate(),
                // save language to know which one was used when ordering
                // useful to set language programmatically on webhooks
                // example: to use with hooks when sending emails (with the same language as ordered)
                'language_code' => $languageCode
            ]
        ];

        // add session params according to uiMode
        if ($this->options['uiMode'] === Session::UI_MODE_HOSTED) {
            $sessionParams['success_url'] = $this->getPageUrl($this->options['successPage'], $languageCode, true);
            $sessionParams['cancel_url'] = $this->getPageUrl($this->options['cancelPage'], $languageCode);
        }
        else if ($this->options['uiMode'] === Session::UI_MODE_EMBEDDED) {
            $sessionParams['return_url'] = $this->getPageUrl($this->options['returnPage'], $languageCode, true);
        }

        // add shipping params if page exists and is enabled
        // https://docs.stripe.com/payments/during-payment/charge-shipping?payment-ui=checkout&lang=php
        if ($this->settingsPage?->shippingEnabled()->toBool() === true) {
            $sessionParams['shipping_address_collection']['allowed_countries'] = $this->settingsPage->shippingAllowedCountries()->split();
            $sessionParams['shipping_options'] = $this->getShippingOptions();
        }

        // trigger event to allow session parameters change
        // https://docs.stripe.com/api/checkout/sessions/create?lang=php
        $sessionParams = kirby()->apply('stripe-checkout.session.create:before', [
            'sessionParams' => $sessionParams,
        ], 'sessionParams');

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
    public function constructEvent(string $payload, string $sigHeader): Event
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

    public function getReturnPage(): ?string
    {
        return $this->options['returnPage'] ?? null;
    }

    public function getSuccessPage(): ?string
    {
        return $this->options['successPage'] ?? null;
    }

    public function getCancelPage(): ?string
    {
        return $this->options['cancelPage'] ?? null;
    }

    public function getOrdersPage(): string
    {
        return $this->options['ordersPage'];
    }

    public function getSettingsPage(): string
    {
        return $this->options['settingsPage'];
    }

    /**
     * @throws RoundingNecessaryException
     * @throws MathException
     * @throws UnknownCurrencyException
     * @throws NumberFormatException
     */
    protected function getLineItems(Cart $cart): array
    {
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
                    'currency' => strtolower($this->options['currency']),
                    // Stripe expects amounts to be provided in the currency smallest unit
                    // https://docs.stripe.com/currencies#zero-decimal
                    'unit_amount' => MoneyFormatter::toMinorUnit($item['price'], $this->options['currency']),
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
        // if there is no settings page or shipping is disabled
        if ($this->settingsPage?->shippingEnabled()->toBool() !== true) {
            return [];
        }

        $shippingOptions = [];

        // get shipping rates and handle them if they exist
        foreach ($this->settingsPage->shippingOptions()->toStructure() as $shippingOption) {
            $shippingRateData = [
                'type' => 'fixed_amount',
                'fixed_amount' => [
                    'amount' => MoneyFormatter::toMinorUnit($shippingOption->amount()->value(), $this->options['currency']),
                    'currency' => $this->options['currency'],
                ],
                'display_name' => $shippingOption->name()->value()
            ];

            // add minimum delivery estimate if it exists
            if ($shippingOption->deliveryEstimateMinimum()->isNotEmpty()) {
                $shippingRateData['delivery_estimate']['minimum'] = [
                    'unit' => $shippingOption->deliveryEstimateMinimumUnit()->value(),
                    'value' => $shippingOption->deliveryEstimateMinimum()->value(),
                ];
            }

            // add maximum delivery estimate if it exists
            if ($shippingOption->deliveryEstimateMaximum()->isNotEmpty()) {
                $shippingRateData['delivery_estimate']['maximum'] = [
                    'unit' => $shippingOption->deliveryEstimateMaximumUnit()->value(),
                    'value' => $shippingOption->deliveryEstimateMaximum()->value(),
                ];
            }

            // add shipping rate to shipping options
            $shippingOptions[] = ['shipping_rate_data' => $shippingRateData];
        }

        return $shippingOptions;
    }

    /**
     * @throws CheckoutSessionException
     */
    protected function getPageUrl(string $pageId, ?string $languageCode = null, bool $addSessionParam = false): string
    {
        $page = page($pageId);

        if ($page === null) {
            throw new CheckoutSessionException(
                sprintf('Page with id "%s" was not found.', $pageId)
            );
        }

        $url = $page->url($languageCode);

        if ($addSessionParam === true) {
            $url = $this->addSessionIdToUrlQuery($url);
        }

        return $url;
    }

    protected function addSessionIdToUrlQuery(string $url): string
    {
        // always include the {CHECKOUT_SESSION_ID} template variable in the URL
        // https://docs.stripe.com/checkout/embedded/quickstart#return-url
        $url .= (parse_url($url, PHP_URL_QUERY) ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}';

        return $url;
    }

    private function resolveOptions(array $options): array
    {
        $resolver = new OptionsResolver();
        $resolver->setIgnoreUndefined();

        $resolver->define('stripePublicKey')
            ->required()
            ->allowedTypes('string')
            ->allowedValues(Validation::createIsValidCallable(new NotBlank()));

        $resolver->define('stripeSecretKey')
            ->required()
            ->allowedTypes('string')
            ->allowedValues(Validation::createIsValidCallable(new NotBlank()));

        $resolver->define('stripeWebhookSecret')
            ->required()
            ->allowedTypes('string')
            ->allowedValues(Validation::createIsValidCallable(new NotBlank()));

        $resolver->define('currency')
            ->required()
            ->allowedTypes('string')
            ->allowedValues(...Currencies::getCurrencyCodes())
            ->normalize(function (Options $options, string $currency): string {
                return strtoupper($currency);
            });

        $resolver->define('uiMode')
            ->required()
            ->allowedTypes('string')
            ->allowedValues(Session::UI_MODE_HOSTED, Session::UI_MODE_EMBEDDED);

        $resolver->define('ordersPage')
            ->required()
            ->allowedTypes('string')
            ->allowedValues(Validation::createIsValidCallable(new NotBlank()));

        $resolver->define('settingsPage')
            ->required()
            ->allowedTypes('string')
            ->allowedValues(Validation::createIsValidCallable(new NotBlank()));

        // conditional options based on ui mode
        $uiMode = $options['uiMode'] ?? null;

        if ($uiMode === Session::UI_MODE_HOSTED) {
            $resolver->define('successPage')
                ->required()
                ->allowedTypes('string')
                ->allowedValues(Validation::createIsValidCallable(new NotBlank()));

            $resolver->define('cancelPage')
                ->required()
                ->allowedTypes('string')
                ->allowedValues(Validation::createIsValidCallable(new NotBlank()));
        }
        else if ($uiMode === Session::UI_MODE_EMBEDDED) {
            $resolver->define('returnPage')
                ->required()
                ->allowedTypes('string')
                ->allowedValues(Validation::createIsValidCallable(new NotBlank()));
        }

        return $resolver->resolve($options);
    }
}
