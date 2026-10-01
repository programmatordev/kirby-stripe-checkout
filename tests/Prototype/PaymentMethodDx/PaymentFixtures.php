<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Prototype\PaymentMethodDx;

use Stripe\Charge;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;

/**
 * Synthetic documented provider shapes, not captured payments or account credentials.
 * These fixtures do not prove when each field is available in a real Checkout flow.
 */
final class PaymentFixtures
{
    public static function card(): PaymentIntent
    {
        return self::intent(
            methodType: PaymentMethod::TYPE_CARD,
            status: PaymentIntent::STATUS_SUCCEEDED,
            amountReceived: 2500,
        );
    }

    public static function applePay(): PaymentIntent
    {
        return self::cardWallet('apple_pay');
    }

    public static function googlePay(): PaymentIntent
    {
        return self::cardWallet('google_pay');
    }

    public static function mbWay(): PaymentIntent
    {
        // App approval happens in Stripe's payment flow, not through a voucher
        // reference that the store must invent or persist after completion.
        // https://docs.stripe.com/payments/mb-way/accept-a-payment?payment-ui=checkout
        return self::intent(
            methodType: PaymentMethod::TYPE_MB_WAY,
            status: PaymentIntent::STATUS_SUCCEEDED,
            amountReceived: 2500,
        );
    }

    public static function klarna(): PaymentIntent
    {
        // The buyer's repayment schedule is Klarna's concern. A succeeded merchant
        // payment is not pending merely because the buyer selected instalments.
        // https://docs.stripe.com/payments/klarna
        return self::intent(
            methodType: PaymentMethod::TYPE_KLARNA,
            status: PaymentIntent::STATUS_SUCCEEDED,
            amountReceived: 2500,
        );
    }

    public static function paypal(): PaymentIntent
    {
        return self::intent(
            methodType: PaymentMethod::TYPE_PAYPAL,
            status: PaymentIntent::STATUS_SUCCEEDED,
            amountReceived: 2500,
        );
    }

    public static function link(): PaymentIntent
    {
        return self::intent(
            methodType: PaymentMethod::TYPE_LINK,
            status: PaymentIntent::STATUS_SUCCEEDED,
            amountReceived: 2500,
        );
    }

    public static function multibanco(): PaymentIntent
    {
        $paymentIntent = self::intent(
            methodType: PaymentMethod::TYPE_MULTIBANCO,
            status: PaymentIntent::STATUS_REQUIRES_ACTION,
            nextAction: [
                'type' => 'multibanco_display_details',
                'multibanco_display_details' => [
                    'entity' => '12345',
                    'reference' => '123456789',
                    'expires_at' => 1790899200,
                    'hosted_voucher_url' => 'https://payments.example.test/voucher',
                ],
            ],
        );

        // The pending test-mode voucher observation had instructions before a
        // Charge existed. Keep that shape instead of inventing Charge evidence.
        $paymentIntent->latest_charge = null;

        return $paymentIntent;
    }

    public static function oxxo(): PaymentIntent
    {
        return self::intent(
            methodType: PaymentMethod::TYPE_OXXO,
            status: PaymentIntent::STATUS_REQUIRES_ACTION,
            nextAction: [
                'type' => 'oxxo_display_details',
                'oxxo_display_details' => [
                    'number' => '1234567890',
                    'expires_after' => 1790899200,
                    'hosted_voucher_url' => 'https://payments.example.test/oxxo',
                ],
            ],
            currency: 'mxn',
        );
    }

    public static function paynow(): PaymentIntent
    {
        return self::intent(
            methodType: PaymentMethod::TYPE_PAYNOW,
            status: PaymentIntent::STATUS_REQUIRES_ACTION,
            nextAction: [
                'type' => 'paynow_display_qr_code',
                'paynow_display_qr_code' => [
                    'data' => 'EXCLUDED_QR_PAYLOAD',
                    'image_url_png' => 'https://payments.example.test/qr.png',
                    'image_url_svg' => 'https://payments.example.test/qr.svg',
                    'hosted_instructions_url' => 'https://payments.example.test/paynow',
                ],
            ],
            currency: 'sgd',
        );
    }

    public static function konbini(): PaymentIntent
    {
        // One voucher can contain different payment codes for several stores.
        // A single reference accessor cannot faithfully describe this structure.
        // https://docs.stripe.com/api/payment_intents/object?query=next_action.konbini_display_details
        return self::intent(
            methodType: PaymentMethod::TYPE_KONBINI,
            status: PaymentIntent::STATUS_REQUIRES_ACTION,
            nextAction: [
                'type' => 'konbini_display_details',
                'konbini_display_details' => [
                    'expires_at' => 1790899200,
                    'hosted_voucher_url' => 'https://payments.example.test/konbini',
                    'stores' => [
                        'familymart' => [
                            'payment_code' => '123456789',
                            'confirmation_number' => '11111111110',
                        ],
                        'lawson' => ['payment_code' => '987654321'],
                        'ministop' => null,
                        'seicomart' => null,
                    ],
                ],
            ],
            currency: 'jpy',
        );
    }

    public static function sepaDebit(): PaymentIntent
    {
        return self::intent(
            methodType: PaymentMethod::TYPE_SEPA_DEBIT,
            status: PaymentIntent::STATUS_PROCESSING,
        );
    }

    public static function bacsDebit(): PaymentIntent
    {
        return self::intent(
            methodType: PaymentMethod::TYPE_BACS_DEBIT,
            status: PaymentIntent::STATUS_PROCESSING,
            currency: 'gbp',
        );
    }

    private static function cardWallet(string $walletType): PaymentIntent
    {
        $data = self::card()->toArray();
        $data['latest_charge'] = [
            'id' => 'ch_prototype',
            'object' => Charge::OBJECT_NAME,
            'status' => Charge::STATUS_SUCCEEDED,
            'payment_intent' => 'pi_prototype',
            'payment_method_details' => [
                'type' => PaymentMethod::TYPE_CARD,
                'card' => [
                    'wallet' => ['type' => $walletType],
                    'fingerprint' => 'EXCLUDED_WALLET_FINGERPRINT',
                ],
            ],
        ];

        return PaymentIntent::constructFrom($data);
    }

    /** @param array<string, mixed>|null $nextAction */
    private static function intent(
        string $methodType,
        string $status,
        ?array $nextAction = null,
        string $currency = 'eur',
        int $amountReceived = 0,
    ): PaymentIntent {
        return PaymentIntent::constructFrom([
            'id' => 'pi_prototype',
            'object' => PaymentIntent::OBJECT_NAME,
            'status' => $status,
            'currency' => $currency,
            'amount' => 2500,
            'amount_received' => $amountReceived,
            'livemode' => false,
            'client_secret' => 'EXCLUDED_CLIENT_SECRET',
            'metadata' => ['private' => 'EXCLUDED_METADATA'],
            'payment_method_types' => [PaymentMethod::TYPE_CARD, $methodType],
            'payment_method' => [
                'id' => 'pm_prototype',
                'object' => PaymentMethod::OBJECT_NAME,
                'type' => $methodType,
                'billing_details' => ['email' => 'EXCLUDED_CUSTOMER_EMAIL'],
            ],
            'latest_charge' => [
                'id' => 'ch_prototype',
                'object' => Charge::OBJECT_NAME,
                'status' => $status === PaymentIntent::STATUS_SUCCEEDED ? Charge::STATUS_SUCCEEDED : Charge::STATUS_PENDING,
                'payment_intent' => 'pi_prototype',
                'payment_method_details' => [
                    'type' => $methodType,
                    $methodType => ['fingerprint' => 'EXCLUDED_FINGERPRINT'],
                ],
            ],
            'next_action' => $nextAction,
            'last_payment_error' => null,
        ]);
    }
}
