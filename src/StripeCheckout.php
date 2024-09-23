<?php

namespace ProgrammatorDev\StripeCheckout;

use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Validation;

class StripeCheckout
{
    public const UI_MODE_HOSTED = 'hosted';
    public const UI_MODE_EMBEDDED = 'embedded';

    private array $options;

    public function __construct(array $options)
    {
        $this->options = $this->resolveOptions($options);
    }

    private function resolveOptions(array $options): array
    {
        $resolver = new OptionsResolver();
        $resolver->setIgnoreUndefined();

        $resolver->setRequired(['stripePublicKey', 'stripeSecretKey', 'uiMode']);

        $resolver->setAllowedTypes('stripePublicKey', 'string');
        $resolver->setAllowedTypes('stripeSecretKey', 'string');
        $resolver->setAllowedTypes('uiMode', 'string');

        $resolver->setAllowedValues('stripePublicKey', Validation::createIsValidCallable(new NotBlank()));
        $resolver->setAllowedValues('stripeSecretKey', Validation::createIsValidCallable(new NotBlank()));
        $resolver->setAllowedValues('uiMode', [self::UI_MODE_HOSTED, self::UI_MODE_EMBEDDED]);

        switch ($options['uiMode'] ?? null) {
            case self::UI_MODE_HOSTED:
                $resolver->setRequired(['successUrl', 'cancelUrl']);

                $resolver->setAllowedTypes('successUrl', 'string');
                $resolver->setAllowedTypes('cancelUrl', 'string');

                $resolver->setAllowedValues('successUrl', Validation::createIsValidCallable(new NotBlank(), new Url()));
                $resolver->setAllowedValues('cancelUrl', Validation::createIsValidCallable(new NotBlank(), new Url()));

                break;
            case self::UI_MODE_EMBEDDED:
                $resolver->setRequired(['checkoutPage', 'returnUrl']);

                $resolver->setAllowedTypes('checkoutPage', 'string');
                $resolver->setAllowedTypes('returnUrl', 'string');

                $resolver->setAllowedValues('checkoutPage', Validation::createIsValidCallable(new NotBlank()));
                $resolver->setAllowedValues('returnUrl', Validation::createIsValidCallable(new NotBlank(), new Url()));

                break;
        }

        return $resolver->resolve($options);
    }

    /**
     * @throws ApiErrorException
     */
    public function createSession(): Session
    {
        $uiMode = $this->getUiMode();

        // set base session params
        $sessionParams = [
            'ui_mode' => $uiMode,
            'mode' => 'payment',
            // TODO replace with Cart data
            // temporarily manually add line_items
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => 'eur',
                        'unit_amount' => 2000,
                        'product_data' => [
                            'name' => 'Product 01',
                        ]
                    ],
                    'quantity' => 1,
                ]
            ]
        ];

        // add session params according to uiMode
        $sessionParams = match ($uiMode) {
            self::UI_MODE_HOSTED => array_merge($sessionParams, [
                'success_url' => $this->getSuccessUrl(),
                'cancel_url' => $this->getCancelUrl(),
            ]),
            self::UI_MODE_EMBEDDED => array_merge($sessionParams, [
                'return_url' => $this->getReturnUrl(),
            ])
        };

        Stripe::setApiKey($this->getStripeSecretKey());

        return Session::create($sessionParams);
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

    public function getUiMode(): string
    {
        return $this->options['uiMode'];
    }

    public function getCheckoutPage(): ?string
    {
        return $this->options['checkoutPage'] ?? null;
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
}
