<?php

namespace ProgrammatorDev\StripeCheckout;

use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;
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

    public function __construct(
        array $options,
        private readonly Cart $cart
    )
    {
        $this->options = $this->resolveOptions($options);
    }

    private function resolveOptions(array $options): array
    {
        $resolver = new OptionsResolver();
        $resolver->setIgnoreUndefined();

        $resolver->setRequired(['stripePublicKey', 'stripeSecretKey', 'currency', 'uiMode']);

        $resolver->setAllowedTypes('stripePublicKey', 'string');
        $resolver->setAllowedTypes('stripeSecretKey', 'string');
        $resolver->setAllowedTypes('currency', 'string');
        $resolver->setAllowedTypes('uiMode', 'string');

        $resolver->setAllowedValues('stripePublicKey', Validation::createIsValidCallable(new NotBlank()));
        $resolver->setAllowedValues('stripeSecretKey', Validation::createIsValidCallable(new NotBlank()));
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
        }
        else if ($uiMode === self::UI_MODE_EMBEDDED) {
            $resolver->setRequired(['returnUrl']);

            $resolver->setAllowedTypes('returnUrl', 'string');
            $resolver->setAllowedValues('returnUrl', Validation::createIsValidCallable(new NotBlank(), new Url()));

            $resolver->setNormalizer('returnUrl', function (Options $options, string $returnUrl): string {
                // always include the {CHECKOUT_SESSION_ID} template variable in the URL
                // https://docs.stripe.com/checkout/embedded/quickstart#return-url
                $returnUrl .= (parse_url($returnUrl, PHP_URL_QUERY) ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}';

                return $returnUrl;
            });
        }

        return $resolver->resolve($options);
    }

    /**
     * @throws ApiErrorException
     */
    public function createSession(): Session
    {
        $uiMode = $this->options['uiMode'];

        // set base session options
        $options = [
            'mode' => 'payment',
            'ui_mode' => $uiMode,
            'line_items' => $this->convertCartToLineItems(),
        ];

        // add session params according to uiMode
        if ($uiMode === self::UI_MODE_HOSTED) {
            $options['success_url'] = $this->options['successUrl'];
            $options['cancel_url'] = $this->options['cancelUrl'];
        }
        else if ($uiMode === self::UI_MODE_EMBEDDED) {
            $options['return_url'] = $this->options['returnUrl'];
        }

        Stripe::setApiKey($this->options['stripeSecretKey']);

        // trigger event to allow session options manipulation
        // https://docs.stripe.com/api/checkout/sessions/create?lang=php
        $options = kirby()->apply('stripe.checkout.sessionCreate:before', compact('options'), 'options');

        return Session::create($options);
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

    protected function convertCartToLineItems(): array
    {
        $lineItems = [];

        foreach ($this->cart->getItems() as $item) {
            $description = null;

            if (!empty($item['options'])) {
                $description = [];

                foreach ($item['options'] as $name => $value) {
                    $description[] = sprintf('%s: %s', $name, $value);
                }

                $description = implode(', ', $description);
            }

            // convert to Stripe line_items data
            // https://docs.stripe.com/api/checkout/sessions/create?lang=php#create_checkout_session-line_items
            $lineItems[] = [
                'price_data' => [
                    'currency' => $this->options['currency'],
                    // Stripe only accepts zero-decimal amounts
                    // https://docs.stripe.com/currencies#zero-decimal
                    'unit_amount' => (int) round($item['price'] * 100),
                    'product_data' => [
                        'name' => $item['name'],
                        'images' => [$item['image']],
                        'description' => $description
                    ]
                ],
                'quantity' => $item['quantity'],
            ];
        }

        return $lineItems;
    }
}
