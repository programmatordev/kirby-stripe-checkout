<?php

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use ProgrammatorDev\StripeCheckout\Cart;
use ProgrammatorDev\StripeCheckout\Converter;
use ProgrammatorDev\StripeCheckout\Test\BaseTestCase;

class ConverterTest extends BaseTestCase
{
    public function testCartToLineItems(): void
    {
        // arrange
        $cart = new Cart();

        $cart->addItem([
            'id' => 'item-id-1',
            'name' => 'Item 1',
            'image' => 'image.jpg',
            'price' => 10,
            'quantity' => 1
        ]);
        $cart->addItem([
            'id' => 'item-id-2',
            'name' => 'Item 2',
            'image' => 'image.jpg',
            'price' => 20,
            'quantity' => 2,
            'options' => [
                'Size' => 'Small'
            ]
        ]);

        // act
        $lineItems = Converter::cartToLineItems($cart, 'eur');

        // assert
        $this->assertEquals([
            [
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => 1000,
                    'product_data' => [
                        'name' => 'Item 1',
                        'images' => ['image.jpg'],
                        'description' => null
                    ]
                ],
                'quantity' => 1,
            ],
            [
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => 2000,
                    'product_data' => [
                        'name' => 'Item 2',
                        'images' => ['image.jpg'],
                        'description' => 'Size: Small'
                    ]
                ],
                'quantity' => 2,
            ]
        ], $lineItems);
    }
}
