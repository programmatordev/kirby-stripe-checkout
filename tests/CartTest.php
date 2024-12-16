<?php

namespace ProgrammatorDev\StripeCheckout\Test;

use Kirby\Cms\Page;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Cart;
use ProgrammatorDev\StripeCheckout\Exception\CartException;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;

class CartTest extends BaseTestCase
{
    private Cart $cart;

    private array $defaultContents;

    private Page $testPage;

    private Page $productPage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cart = new Cart([
            'currency' => 'EUR'
        ]);

        $this->defaultContents = [
            'items' => [],
            'totalAmount' => 0,
            'totalQuantity' => 0,
            'totalAmountFormatted' => '€ 0.00'
        ];

        $this->testPage = site()
            ->createChild([
                'slug' => 'test',
                'template' => 'default',
                'isDraft' => false
            ]);

        $this->productPage = site()
            ->createChild([
                'slug' => 'product',
                'template' => 'product',
                'content' => [
                    'title' => 'Product',
                    'price' => 10
                ]
            ])
            ->changeStatus('listed');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // destroy data after each test
        $this->cart->destroy();
        $this->testPage->delete(true);
        $this->productPage->delete(true);
    }

    public function testAddItem(): void
    {
        // pre-assertions
        $this->assertEquals($this->defaultContents, $this->cart->getContents());

        // act
        $lineItemId = $this->cart->addItem([
            'id' => 'product',
            'quantity' => 1,
        ]);

        // assert
        $this->assertEqualsCanonicalizing([
            'items' => [
                $lineItemId => [
                    'id' => 'product',
                    'image' => null,
                    'name' => 'Product',
                    'price' => 10,
                    'quantity' => 1,
                    'subtotal' => 10,
                    'options' => null,
                    'priceFormatted' => '€ 10.00',
                    'subtotalFormatted' => '€ 10.00'
                ]
            ],
            'totalAmount' => 10,
            'totalQuantity' => 1,
            'totalAmountFormatted' => '€ 10.00'
        ], $this->cart->getContents());
    }

    public function testAddItemWithSameItemInCart(): void
    {
        // pre-assertions
        $this->assertEquals($this->defaultContents, $this->cart->getContents());

        // act
        $lineItemId = $this->cart->addItem([
            'id' => 'product',
            'quantity' => 1,
        ]);

        $this->cart->addItem([
            'id' => 'product',
            'quantity' => 1,
        ]);

        // assert
        $this->assertEqualsCanonicalizing([
            'items' => [
                $lineItemId => [
                    'id' => 'product',
                    'image' => null,
                    'name' => 'Product',
                    'price' => 10,
                    'quantity' => 2,
                    'subtotal' => 20,
                    'options' => null,
                    'priceFormatted' => '€ 10.00',
                    'subtotalFormatted' => '€ 20.00'
                ]
            ],
            'totalAmount' => 20,
            'totalQuantity' => 2,
            'totalAmountFormatted' => '€ 20.00'
        ], $this->cart->getContents());
    }

    public function testAddItemWithDifferentOptions()
    {
        // pre-assertions
        $this->assertEquals($this->defaultContents, $this->cart->getContents());

        // act
        $lineItemId1 = $this->cart->addItem([
            'id' => 'product',
            'quantity' => 1,
            'options' => [
                'size' => 'small'
            ]
        ]);

        $lineItemId2 = $this->cart->addItem([
            'id' => 'product',
            'quantity' => 1,
            'options' => [
                'size' => 'medium'
            ]
        ]);

        // assert
        $this->assertEqualsCanonicalizing([
            'items' => [
                $lineItemId1 => [
                    'id' => 'product',
                    'image' => null,
                    'name' => 'Product',
                    'price' => 10,
                    'quantity' => 1,
                    'subtotal' => 10,
                    'options' => [
                        'size' => 'small'
                    ],
                    'priceFormatted' => '€ 10.00',
                    'subtotalFormatted' => '€ 10.00'
                ],
                $lineItemId2 => [
                    'id' => 'product',
                    'image' => null,
                    'name' => 'Product',
                    'price' => 10,
                    'quantity' => 1,
                    'subtotal' => 10,
                    'options' => [
                        'size' => 'medium'
                    ],
                    'priceFormatted' => '€ 10.00',
                    'subtotalFormatted' => '€ 10.00'
                ]
            ],
            'totalAmount' => 20,
            'totalQuantity' => 2,
            'totalAmountFormatted' => '€ 20.00'
        ], $this->cart->getContents());
    }

    #[DataProvider('provideAddItemWithMissingOptionsData')]
    public function testAddItemWithMissingOptions(string $missingOption): void
    {
        $item = [
            'id' => 'product',
            'quantity' => 1
        ];

        unset($item[$missingOption]);

        $this->expectException(MissingOptionsException::class);

        $this->cart->addItem($item);
    }

    public static function provideAddItemWithMissingOptionsData(): \Generator
    {
        yield 'missing id' => ['id'];
        yield 'missing quantity' => ['quantity'];
    }

    #[DataProvider('provideAddItemWithInvalidOptionsData')]
    public function testAddItemWithInvalidOptions(string $optionName, mixed $invalidValue): void
    {
        $item = [
            'id' => 'product',
            'quantity' => 1,
            'options' => null
        ];

        $item[$optionName] = $invalidValue;

        $this->expectException(InvalidOptionsException::class);

        $this->cart->addItem($item);
    }

    public static function provideAddItemWithInvalidOptionsData(): \Generator
    {
        yield 'invalid id' => ['id', 1];
        yield 'empty id' => ['id', ''];
        yield 'invalid quantity' => ['quantity', 'invalid'];
        yield 'zero quantity' => ['quantity', 0];
        yield 'invalid options' => ['options', 'invalid'];
        yield 'empty options' => ['options', []];
    }

    public function testAddItemWhenProductDoesNotExist(): void
    {
        $this->expectException(CartException::class);
        $this->expectExceptionMessage('Product does not exist.');

        $this->cart->addItem([
            'id' => 'does-not-exist',
            'quantity' => 1,
        ]);
    }

    #[DataProvider('provideAddItemWhenProductIsNotListedData')]
    public function testAddItemWhenProductIsNotListed(string $status): void
    {
        $this->expectException(CartException::class);
        $this->expectExceptionMessage('Product does not exist.');

        $this->productPage->changeStatus($status);

        $this->cart->addItem([
            'id' => 'product',
            'quantity' => 1,
        ]);
    }

    public static function provideAddItemWhenProductIsNotListedData(): \Generator
    {
        yield 'unlisted' => ['unlisted'];
        // TODO understand why a draft page is not being deleted programmatically
//        yield 'draft' => ['draft'];
    }

    public function testUpdateItem(): void
    {
        // arrange
        $lineItemId = $this->cart->addItem([
            'id' => 'product',
            'quantity' => 2,
        ]);

        // pre-assertions
        $this->assertEqualsCanonicalizing([
            'items' => [
                $lineItemId => [
                    'id' => 'product',
                    'image' => null,
                    'name' => 'Product',
                    'price' => 10,
                    'quantity' => 2,
                    'subtotal' => 20,
                    'options' => null,
                    'priceFormatted' => '€ 10.00',
                    'subtotalFormatted' => '€ 20.00'
                ]
            ],
            'totalAmount' => 20,
            'totalQuantity' => 2,
            'totalAmountFormatted' => '€ 20.00'
        ], $this->cart->getContents());

        // act
        $this->cart->updateItem($lineItemId, [
            'quantity' => 1
        ]);

        // assert
        $this->assertEqualsCanonicalizing([
            'items' => [
                $lineItemId => [
                    'id' => 'product',
                    'image' => null,
                    'name' => 'Product',
                    'price' => 10,
                    'quantity' => 1,
                    'subtotal' => 10,
                    'options' => null,
                    'priceFormatted' => '€ 10.00',
                    'subtotalFormatted' => '€ 10.00'
                ]
            ],
            'totalAmount' => 10,
            'totalQuantity' => 1,
            'totalAmountFormatted' => '€ 10.00'
        ], $this->cart->getContents());
    }

    public function testUpdateItemThatDoesNotExist(): void
    {
        $this->expectException(CartException::class);

        $this->cart->updateItem('does-not-exist', [
            'quantity' => 1
        ]);
    }

    public function testUpdateItemWithInvalidQuantity(): void
    {
        // arrange
        $lineItemId = $this->cart->addItem([
            'id' => 'product',
            'quantity' => 2
        ]);

        $this->expectException(InvalidOptionsException::class);

        $this->cart->updateItem($lineItemId, [
            'quantity' => 0
        ]);
    }

    public function testRemoveItem(): void
    {
        // arrange
        $lineItemId = $this->cart->addItem([
            'id' => 'product',
            'quantity' => 1
        ]);

        // pre-assertions
        $this->assertEqualsCanonicalizing([
            'items' => [
                $lineItemId => [
                    'id' => 'product',
                    'image' => null,
                    'name' => 'Product',
                    'price' => 10,
                    'quantity' => 1,
                    'subtotal' => 10,
                    'options' => null,
                    'priceFormatted' => '€ 10.00',
                    'subtotalFormatted' => '€ 10.00'
                ]
            ],
            'totalAmount' => 10,
            'totalQuantity' => 1,
            'totalAmountFormatted' => '€ 10.00'
        ], $this->cart->getContents());

        // act
        $this->cart->removeItem($lineItemId);

        // assert
        $this->assertEquals($this->defaultContents, $this->cart->getContents());
    }

    public function testRemoveItemThatDoesNotExist(): void
    {
        $this->expectException(CartException::class);

        $this->cart->removeItem('does-not-exist');
    }

    public function testDestroy(): void
    {
        // arrange
        $lineItemId = $this->cart->addItem([
            'id' => 'product',
            'quantity' => 1
        ]);

        // pre-assertions
        $this->assertEqualsCanonicalizing([
            'items' => [
                $lineItemId => [
                    'id' => 'product',
                    'image' => null,
                    'name' => 'Product',
                    'price' => 10,
                    'quantity' => 1,
                    'subtotal' => 10,
                    'options' => null,
                    'priceFormatted' => '€ 10.00',
                    'subtotalFormatted' => '€ 10.00'
                ]
            ],
            'totalAmount' => 10,
            'totalQuantity' => 1,
            'totalAmountFormatted' => '€ 10.00'
        ], $this->cart->getContents());

        // act
        $this->cart->destroy();

        // assert
        $this->assertEquals($this->defaultContents, $this->cart->getContents());
    }

    public function testGetContents(): void
    {
        $this->assertEquals($this->defaultContents, $this->cart->getContents());
    }

    public function testGetItems(): void
    {
        $this->assertEquals([], $this->cart->getItems());
    }

    public function testGetTotalAmount(): void
    {
        $this->assertSame(0, $this->cart->getTotalAmount());
    }

    public function testGetTotalQuantity(): void
    {
        $this->assertSame(0, $this->cart->getTotalQuantity());
    }
}
