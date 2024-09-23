<?php

namespace ProgrammatorDev\StripeCheckout;

class Converter
{
    public static function cartToLineItems(Cart $cart, string $currency): array
    {
        // normalize currency
        $currency = strtolower($currency);
        $lineItems = [];

        foreach ($cart->getItems() as $item) {
            $description = null;

            if (!empty($item['options'])) {
                $description = [];

                foreach ($item['options'] as $name => $variant) {
                    $description[] = sprintf('%s: %s', $name, $variant);
                }

                $description = implode(', ', $description);
            }

            // convert to Stripe line_items data
            // https://docs.stripe.com/api/checkout/sessions/create?lang=php#create_checkout_session-line_items
            $lineItems[] = [
                'price_data' => [
                    'currency' => $currency,
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
