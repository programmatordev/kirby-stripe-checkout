<?php

namespace ProgrammatorDev\StripeCheckout;

use ProgrammatorDev\StripeCheckout\Exception\InvalidConfigException;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;

class StripeCheckout
{
    public const UI_MODE_HOSTED = 'hosted';
    public const UI_MODE_EMBEDDED = 'embedded';

    private static ?array $config = null;

    /**
     * @throws InvalidConfigException
     * @throws ApiErrorException
     */
    static function createSession(): Session
    {
        if (self::$config === null) {
            throw new InvalidConfigException(
                'No config provided. Set your config using StripeCheckout::setConfig($options).'
            );
        }

        $uiMode = self::getUiMode();

        // set base params
        $params = [
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

        // set params according to uiMode
        $params = match ($uiMode) {
            self::UI_MODE_HOSTED => array_merge($params, [
                'success_url' => self::getSuccessUrl(),
                'cancel_url' => self::getCancelUrl()
            ]),
            self::UI_MODE_EMBEDDED => array_merge($params, [
                'return_url' => self::getReturnUrl()
            ])
        };

        Stripe::setApiKey(self::getSecretKey());

        return Session::create($params);
    }

    /**
     * @throws InvalidConfigException
     */
    static function setConfig(array $options): void
    {
        self::validateConfig($options);

        self::$config = $options;
    }

    public static function getPublicKey(): string
    {
        return self::$config['stripePublicKey'];
    }

    public static function getSecretKey(): string
    {
        return self::$config['stripeSecretKey'];
    }

    public static function getUiMode(): string
    {
        return self::$config['uiMode'];
    }

    public static function getReturnUrl(): ?string
    {
        return self::$config['returnUrl'];
    }

    public static function getSuccessUrl(): ?string
    {
        return self::$config['successUrl'];
    }

    public static function getCancelUrl(): ?string
    {
        return self::$config['cancelUrl'];
    }

    /**
     * @throws InvalidConfigException
     */
    private static function validateConfig(array $options): void
    {
        if (empty($options['stripePublicKey']) || empty($options['stripeSecretKey'])) {
            throw new InvalidConfigException('stripePublicKey and stripeSecretKey are required.');
        }

        if (!in_array($options['uiMode'], [self::UI_MODE_HOSTED, self::UI_MODE_EMBEDDED])) {
            throw new InvalidConfigException('uiMode is invalid. Accepted values are: "hosted" or "embedded".');
        }

        if ($options['uiMode'] == self::UI_MODE_HOSTED) {
            if ((empty($options['successUrl']) || empty($options['cancelUrl']))) {
                throw new InvalidConfigException('successUrl and cancelUrl are required in "hosted" mode.');
            }
        }

        if ($options['uiMode'] == self::UI_MODE_EMBEDDED) {
            if (empty($options['returnUrl'])) {
                throw new InvalidConfigException('returnUrl is required in "embedded" mode.');
            }
        }
    }
}
