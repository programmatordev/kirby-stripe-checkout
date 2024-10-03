<?php

namespace ProgrammatorDev\StripeCheckout\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Cart;
use ProgrammatorDev\StripeCheckout\Exception\CartItemDoesNotExistException;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;

class CartTest extends BaseTestCase
{
    private Cart $cart;

    private array $defaultContents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cart = new Cart();

        $this->defaultContents = [
            'items' => [],
            'totalAmount' => 0.0,
            'totalQuantity' => 0
        ];
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->cart->destroy();
    }

    public function testAddItem(): void
    {
        // pre-assertions
        $this->assertEquals($this->defaultContents, $this->cart->getContents());

        // act
        $lineItemId = $this->cart->addItem([
            'id' => 'item-id',
            'image' => 'image.jpg',
            'name' => 'Item',
            'price' => 10,
            'quantity' => 1,
        ]);

        // assert
        $this->assertEqualsCanonicalizing([
            'items' => [
                $lineItemId => [
                    'id' => 'item-id',
                    'image' => 'image.jpg',
                    'name' => 'Item',
                    'price' => 10.0,
                    'quantity' => 1,
                    'subtotal' => 10.0,
                    'options' => null
                ]
            ],
            'totalAmount' => 10.0,
            'totalQuantity' => 1
        ], $this->cart->getContents());
    }

    public function testAddItemWithSameItemInCart(): void
    {
        // pre-assertions
        $this->assertEquals($this->defaultContents, $this->cart->getContents());

        // act
        $lineItemId = $this->cart->addItem([
            'id' => 'item-id',
            'name' => 'Item',
            'price' => 10,
            'quantity' => 1,
        ]);

        $this->cart->addItem([
            'id' => 'item-id',
            'name' => 'Item',
            'price' => 10,
            'quantity' => 1,
        ]);

        // assert
        $this->assertEqualsCanonicalizing([
            'items' => [
                $lineItemId => [
                    'id' => 'item-id',
                    'image' => null,
                    'name' => 'Item',
                    'price' => 10.0,
                    'quantity' => 2,
                    'subtotal' => 20.0,
                    'options' => null
                ]
            ],
            'totalAmount' => 20.0,
            'totalQuantity' => 2
        ], $this->cart->getContents());
    }

    public function testAddItemWithDifferentOptions()
    {
        // pre-assertions
        $this->assertEquals($this->defaultContents, $this->cart->getContents());

        // act
        $lineItemId1 = $this->cart->addItem([
            'id' => 'item-id',
            'name' => 'Item',
            'price' => 10,
            'quantity' => 1,
            'options' => [
                'size' => 'small'
            ]
        ]);

        $lineItemId2 = $this->cart->addItem([
            'id' => 'item-id',
            'name' => 'Item',
            'price' => 10,
            'quantity' => 1,
            'options' => [
                'size' => 'medium'
            ]
        ]);

        // assert
        $this->assertEqualsCanonicalizing([
            'items' => [
                $lineItemId1 => [
                    'id' => 'item-id',
                    'image' => null,
                    'name' => 'Item',
                    'price' => 10.0,
                    'quantity' => 1,
                    'subtotal' => 10.0,
                    'options' => [
                        'size' => 'small'
                    ]
                ],
                $lineItemId2 => [
                    'id' => 'item-id',
                    'image' => null,
                    'name' => 'Item',
                    'price' => 10.0,
                    'quantity' => 1,
                    'subtotal' => 10.0,
                    'options' => [
                        'size' => 'medium'
                    ]
                ]
            ],
            'totalAmount' => 20.0,
            'totalQuantity' => 2
        ], $this->cart->getContents());
    }

    #[DataProvider('provideAddItemWithMissingOptionsData')]
    public function testAddItemWithMissingOptions(string $missingOption): void
    {
        $item = [
            'id' => 'item-id',
            'name' => 'Item',
            'price' => 10,
            'quantity' => 1
        ];

        unset($item[$missingOption]);

        $this->expectException(MissingOptionsException::class);

        $this->cart->addItem($item);
    }

    public static function provideAddItemWithMissingOptionsData(): \Generator
    {
        yield 'missing id' => ['id'];
        yield 'missing name' => ['name'];
        yield 'missing price' => ['price'];
        yield 'missing quantity' => ['quantity'];
    }

    #[DataProvider('provideAddItemWithInvalidOptionsData')]
    public function testAddItemWithInvalidOptions(string $optionName, mixed $invalidValue): void
    {
        $item = [
            'id' => 'item-id',
            'image' => null,
            'name' => 'Item',
            'price' => 10,
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
        yield 'invalid image' => ['image', 1];
        yield 'empty image' => ['image', ''];
        yield 'invalid name' => ['name', 1];
        yield 'empty name' => ['name', ''];
        yield 'invalid price' => ['price', 'invalid'];
        yield 'zero price' => ['price', 0];
        yield 'invalid quantity' => ['quantity', 'invalid'];
        yield 'zero quantity' => ['quantity', 0];
        yield 'invalid options' => ['options', 'invalid'];
        yield 'empty options' => ['options', []];
    }

    public function testUpdateItem(): void
    {
        // arrange
        $lineItemId = $this->cart->addItem([
            'id' => 'item-id',
            'name' => 'Item',
            'price' => 10,
            'quantity' => 2,
        ]);

        // pre-assertions
        $this->assertEqualsCanonicalizing([
            'items' => [
                $lineItemId => [
                    'id' => 'item-id',
                    'image' => null,
                    'name' => 'Item',
                    'price' => 10.0,
                    'quantity' => 2,
                    'subtotal' => 20.0,
                    'options' => null
                ]
            ],
            'totalAmount' => 20.0,
            'totalQuantity' => 2
        ], $this->cart->getContents());

        // act
        $this->cart->updateItem($lineItemId, 1);

        // assert
        $this->assertEqualsCanonicalizing([
            'items' => [
                $lineItemId => [
                    'id' => 'item-id',
                    'image' => null,
                    'name' => 'Item',
                    'price' => 10.0,
                    'quantity' => 1,
                    'subtotal' => 10.0,
                    'options' => null
                ]
            ],
            'totalAmount' => 10.0,
            'totalQuantity' => 1
        ], $this->cart->getContents());
    }

    public function testUpdateItemThatDoesNotExist(): void
    {
        $this->expectException(CartItemDoesNotExistException::class);

        $this->cart->updateItem('does-not-exist', 1);
    }

    public function testUpdateItemWithInvalidQuantity(): void
    {
        // arrange
        $lineItemId = $this->cart->addItem([
            'id' => 'item-id',
            'name' => 'Item',
            'price' => 10,
            'quantity' => 2,
        ]);

        $this->expectException(InvalidOptionsException::class);

        $this->cart->updateItem($lineItemId, 0);
    }

    public function testRemoveItem(): void
    {
        // arrange
        $lineItemId = $this->cart->addItem([
            'id' => 'item-id',
            'name' => 'Item',
            'price' => 10,
            'quantity' => 1,
        ]);

        // pre-assertions
        $this->assertEqualsCanonicalizing([
            'items' => [
                $lineItemId => [
                    'id' => 'item-id',
                    'image' => null,
                    'name' => 'Item',
                    'price' => 10.0,
                    'quantity' => 1,
                    'subtotal' => 10.0,
                    'options' => null
                ]
            ],
            'totalAmount' => 10.0,
            'totalQuantity' => 1
        ], $this->cart->getContents());

        // act
        $this->cart->removeItem($lineItemId);

        // assert
        $this->assertEquals($this->defaultContents, $this->cart->getContents());
    }

    public function testRemoveItemThatDoesNotExist(): void
    {
        $this->expectException(CartItemDoesNotExistException::class);

        $this->cart->removeItem('does-not-exist');
    }

    public function testDestroy(): void
    {
        // arrange
        $lineItemId = $this->cart->addItem([
            'id' => 'item-id',
            'name' => 'Item',
            'price' => 10,
            'quantity' => 1,
        ]);

        // pre-assertions
        $this->assertEqualsCanonicalizing([
            'items' => [
                $lineItemId => [
                    'id' => 'item-id',
                    'image' => null,
                    'name' => 'Item',
                    'price' => 10.0,
                    'quantity' => 1,
                    'subtotal' => 10.0,
                    'options' => null
                ]
            ],
            'totalAmount' => 10.0,
            'totalQuantity' => 1
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
        $this->assertSame(0.0, $this->cart->getTotalAmount());
    }

    public function testGetTotalQuantity(): void
    {
        $this->assertSame(0, $this->cart->getTotalQuantity());
    }
}
