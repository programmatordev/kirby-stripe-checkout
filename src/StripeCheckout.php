<?php

namespace ProgrammatorDev\StripeCheckout;

use ProgrammatorDev\StripeCheckout\Exception\CartIsEmptyException;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Validation;

class StripeCheckout
{
    public const UI_MODE_HOSTED = 'hosted';
    public const UI_MODE_EMBEDDED = 'embedded';

    private array $options;

    private StripeClient $stripe;

    public function __construct(array $options)
    {
        $this->options = $this->resolveOptions($options);
        $this->stripe = new StripeClient($this->options['stripeSecretKey']);
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
        $resolver->setAllowedValues('currency', Validation::createIsValidCallable(new NotBlank()));
        $resolver->setAllowedValues('uiMode', [self::UI_MODE_HOSTED, self::UI_MODE_EMBEDDED]);

        // https://docs.stripe.com/currencies#presentment-currencies
        $resolver->setNormalizer('currency', function (Options $options, string $currency): string {
            return strtolower($currency);
        });

        $uiMode = $options['uiMode'] ?? null;

        if ($uiMode === self::UI_MODE_HOSTED) {
            $resolver->setRequired(['successUrl', 'cancelUrl']);

            $resolver->setAllowedTypes('successUrl', 'string');
            $resolver->setAllowedTypes('cancelUrl', 'string');

            $resolver->setAllowedValues('successUrl', Validation::createIsValidCallable(new NotBlank(), new Url()));
            $resolver->setAllowedValues('cancelUrl', Validation::createIsValidCallable(new NotBlank(), new Url()));

            $resolver->setNormalizer('successUrl', function (Options $options, string $successUrl): string {
                return $this->addSessionIdParamToUrl($successUrl);
            });
        }
        else if ($uiMode === self::UI_MODE_EMBEDDED) {
            $resolver->setRequired(['returnUrl']);

            $resolver->setAllowedTypes('returnUrl', 'string');
            $resolver->setAllowedValues('returnUrl', Validation::createIsValidCallable(new NotBlank(), new Url()));

            $resolver->setNormalizer('returnUrl', function (Options $options, string $returnUrl): string {
                return $this->addSessionIdParamToUrl($returnUrl);
            });
        }

        return $resolver->resolve($options);
    }

    /**
     * @throws ApiErrorException
     * @throws CartIsEmptyException
     */
    public function createSession(Cart $cart): Session
    {
        if ($cart->getTotalQuantity() === 0) {
            throw new CartIsEmptyException('Cart is empty.');
        }

        $uiMode = $this->options['uiMode'];

        // set base session options
        $params = [
            'mode' => 'payment',
            'ui_mode' => $uiMode,
            'line_items' => $this->convertCartToLineItems($cart),
        ];

        // add session params according to uiMode
        if ($uiMode === self::UI_MODE_HOSTED) {
            $params['success_url'] = $this->options['successUrl'];
            $params['cancel_url'] = $this->options['cancelUrl'];
        }
        else if ($uiMode === self::UI_MODE_EMBEDDED) {
            $params['return_url'] = $this->options['returnUrl'];
        }

        // trigger event to allow session parameters manipulation
        // https://docs.stripe.com/api/checkout/sessions/create?lang=php
        $params = kirby()->apply('stripe.checkout.sessionCreate:before', compact('params'), 'params');

        return $this->stripe->checkout->sessions->create($params);
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

    protected function convertCartToLineItems(Cart $cart): array
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
                    'currency' => $this->options['currency'],
                    // Stripe only accepts zero-decimal amounts
                    // https://docs.stripe.com/currencies#zero-decimal
                    'unit_amount' => (int) round($item['price'] * 100),
                    'product_data' => $productData
                ],
                'quantity' => $item['quantity'],
            ];
        }

        return $lineItems;
    }

    protected function addSessionIdParamToUrl(string $url): string
    {
        // always include the {CHECKOUT_SESSION_ID} template variable in the URL
        // https://docs.stripe.com/checkout/embedded/quickstart#return-url
        $url .= (parse_url($url, PHP_URL_QUERY) ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}';

        return $url;
    }

}
