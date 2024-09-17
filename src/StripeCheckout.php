<?php

namespace ProgrammatorDev\StripeCheckout;

use ProgrammatorDev\StripeCheckout\Exception\InvalidOptionException;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;

class StripeCheckout
{
    public const UI_MODE_HOSTED = 'hosted';
    public const UI_MODE_EMBEDDED = 'embedded';

    private static ?array $options = null;

    /**
     * @throws InvalidOptionException
     * @throws ApiErrorException
     */
    static function createSession(): Session
    {
        if (self::$options === null) {
            throw new InvalidOptionException(
                'No options provided. Set your options using StripeCheckout::setOptions($options).'
            );
        }

        $uiMode = self::getUiMode();

        // set base params
        $params = [
            'ui_mode' => $uiMode,
            'mode' => 'payment',
            // TODO replace with Cart data
            // temporarily add line_items
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
     * @throws InvalidOptionException
     */
    static function setOptions(array $options): void
    {
        self::validateOptions($options);

        self::$options = $options;
    }

    public static function getPublicKey(): string
    {
        return self::$options['stripePublicKey'];
    }

    public static function getSecretKey(): string
    {
        return self::$options['stripeSecretKey'];
    }

    public static function getUiMode(): string
    {
        return self::$options['uiMode'];
    }

    public static function getReturnUrl(): ?string
    {
        return self::$options['returnUrl'];
    }

    public static function getSuccessUrl(): ?string
    {
        return self::$options['successUrl'];
    }

    public static function getCancelUrl(): ?string
    {
        return self::$options['cancelUrl'];
    }

    /**
     * @throws InvalidOptionException
     */
    private static function validateOptions(array $options): void
    {
        if (empty($options['stripePublicKey']) || empty($options['stripeSecretKey'])) {
            throw new InvalidOptionException('stripePublicKey and stripeSecretKey are required.');
        }

        if (!in_array($options['uiMode'], [self::UI_MODE_HOSTED, self::UI_MODE_EMBEDDED])) {
            throw new InvalidOptionException('uiMode is invalid. Accepted values are: "hosted" or "embedded".');
        }

        if ($options['uiMode'] == self::UI_MODE_HOSTED) {
            if ((empty($options['successUrl']) || empty($options['cancelUrl']))) {
                throw new InvalidOptionException('successUrl and cancelUrl are required in "hosted" mode.');
            }
        }

        if ($options['uiMode'] == self::UI_MODE_EMBEDDED) {
            if (empty($options['returnUrl'])) {
                throw new InvalidOptionException('returnUrl is required in "embedded" mode.');
            }
        }
    }
}
