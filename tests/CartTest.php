<?php

namespace ProgrammatorDev\StripeCheckout\Test;

use Kirby\Cms\Page;
use Kirby\Toolkit\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Cart\Cart;
use ProgrammatorDev\StripeCheckout\Exception\InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Exception\InvalidCartItemException;
use ProgrammatorDev\StripeCheckout\Exception\NoSuchCartItemException;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;

class CartTest extends AbstractTestCase
{
    private Cart $cart;

    private Page $productPage;

    private array $defaults;

    protected function setUp(): void
    {
        parent::setUp();

        // init cart
        $this->cart = new Cart([
            'currency' => 'EUR',
            'cartSnippet' => null
        ]);

        // create product page for testing
        $this->productPage = site()->createChild([
            'slug' => 'product',
            'template' => 'product',
            'content' => [
                'title' => 'Product',
                'price' => 10
            ]
        ])->changeStatus('listed');

        // cart default data on init
        $this->defaults = [
            'items' => [],
            'totalAmount' => 0,
            'totalQuantity' => 0,
            'currency' => 'EUR',
            'currencySymbol' => '€',
        ];
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // destroy data after each test
        $this->cart->destroy();
        $this->productPage->delete(true);
    }

    #[DataProvider('provideInvalidOptionsData')]
    public function testInvalidOptions(string $optionName, mixed $invalidValue): void
    {
        $options = ['currency' => 'EUR', 'cartSnippet' => null];
        $options[$optionName] = $invalidValue;

        $this->expectException(InvalidOptionsException::class);

        new Cart($options);
    }

    public static function provideInvalidOptionsData(): \Generator
    {
        yield 'invalid currency' => ['currency', 'INV'];
        yield 'empty currency' => ['currency', ''];
    }

    public function testAddItem(): void
    {
        // pre-assertions
        $this->assertEquals($this->defaults, $this->cart->toArray());

        // act
        $key = $this->cart->addItem('product', 1);

        // assert
        $this->assertEquals([
            'items' => [
                [
                    'key' => $key,
                    'id' => 'product',
                    'name' => 'Product',
                    'price' => 10,
                    'quantity' => 1,
                    'totalAmount' => 10,
                    'options' => null,
                    'thumbnail' => null
                ]
            ],
            'totalAmount' => 10,
            'totalQuantity' => 1,
            'currency' => 'EUR',
            'currencySymbol' => '€'
        ], $this->cart->toArray());
    }

    public function testAddItemWithSameItemInCart(): void
    {
        // pre-assertions
        $this->assertEquals($this->defaults, $this->cart->toArray());

        // act
        $key = $this->cart->addItem('product', 1);
        $this->cart->addItem('product', 1);

        // assert
        $this->assertEquals([
            'items' => [
                [
                    'key' => $key,
                    'id' => 'product',
                    'name' => 'Product',
                    'price' => 10,
                    'quantity' => 2,
                    'totalAmount' => 20,
                    'options' => null,
                    'thumbnail' => null
                ]
            ],
            'totalAmount' => 20,
            'totalQuantity' => 2,
            'currency' => 'EUR',
            'currencySymbol' => '€'
        ], $this->cart->toArray());
    }

    public function testAddItemWithDifferentOptions()
    {
        // pre-assertions
        $this->assertEquals($this->defaults, $this->cart->toArray());

        // act
        $key1 = $this->cart->addItem('product', 1, ['size' => 'small']);
        $key2 = $this->cart->addItem('product', 1, ['size' => 'medium']);

        // assert
        $this->assertEquals([
            'items' => [
                [
                    'key' => $key1,
                    'id' => 'product',
                    'name' => 'Product',
                    'price' => 10,
                    'quantity' => 1,
                    'totalAmount' => 10,
                    'options' => ['size' => 'small'],
                    'thumbnail' => null
                ],
                [
                    'key' => $key2,
                    'id' => 'product',
                    'name' => 'Product',
                    'price' => 10,
                    'quantity' => 1,
                    'totalAmount' => 10,
                    'options' => ['size' => 'medium'],
                    'thumbnail' => null
                ]
            ],
            'totalAmount' => 20,
            'totalQuantity' => 2,
            'currency' => 'EUR',
            'currencySymbol' => '€'
        ], $this->cart->toArray());
    }

    public function testAddItemWithInvalidQuantity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cart->addItem('product', 0);
    }

    public function testAddItemWhenProductDoesNotExist(): void
    {
        $this->expectException(InvalidCartItemException::class);
        $this->expectExceptionMessage('Product "does-not-exist" does not exist.');

        $this->cart->addItem('does-not-exist', 1);
    }

    #[DataProvider('provideAddItemWhenProductIsNotListedData')]
    public function testAddItemWhenProductIsNotListed(string $status): void
    {
        $this->expectException(InvalidCartItemException::class);
        $this->expectExceptionMessage('Product "product" does not exist.');

        $this->productPage->changeStatus($status);
        $this->cart->addItem('product', 1);
    }

    public static function provideAddItemWhenProductIsNotListedData(): \Generator
    {
        yield 'unlisted' => ['unlisted'];
        // TODO understand why a draft page is not being deleted programmatically
        // yield 'draft' => ['draft'];
    }

    public function testUpdateItem(): void
    {
        // arrange
        $key = $this->cart->addItem('product', 2);

        // pre-assertions
        $this->assertEquals([
            'items' => [
                [
                    'key' => $key,
                    'id' => 'product',
                    'name' => 'Product',
                    'price' => 10,
                    'quantity' => 2,
                    'totalAmount' => 20,
                    'options' => null,
                    'thumbnail' => null
                ]
            ],
            'totalAmount' => 20,
            'totalQuantity' => 2,
            'currency' => 'EUR',
            'currencySymbol' => '€'
        ], $this->cart->toArray());

        // act
        $this->cart->updateItem($key, 1);

        // assert
        $this->assertEquals([
            'items' => [
                [
                    'key' => $key,
                    'id' => 'product',
                    'name' => 'Product',
                    'price' => 10,
                    'quantity' => 1,
                    'totalAmount' => 10,
                    'options' => null,
                    'thumbnail' => null
                ]
            ],
            'totalAmount' => 10,
            'totalQuantity' => 1,
            'currency' => 'EUR',
            'currencySymbol' => '€'
        ], $this->cart->toArray());
    }

    public function testUpdateItemThatDoesNotExist(): void
    {
        $this->expectException(NoSuchCartItemException::class);
        $this->expectExceptionMessage('Cart item with key "does-not-exist" does not exist.');

        $this->cart->updateItem('does-not-exist', 1);
    }

    public function testUpdateItemWithInvalidQuantity(): void
    {
        $key = $this->cart->addItem('product', 2);

        $this->expectException(InvalidArgumentException::class);

        $this->cart->updateItem($key, 0);
    }

    public function testRemoveItem(): void
    {
        $key = $this->cart->addItem('product', 1);

        $this->cart->removeItem($key);

        $this->assertEquals($this->defaults, $this->cart->toArray());
    }

    public function testRemoveItemThatDoesNotExist(): void
    {
        $this->expectException(NoSuchCartItemException::class);
        $this->expectExceptionMessage('Cart item with key "does-not-exist" does not exist.');

        $this->cart->removeItem('does-not-exist');
    }

    public function testDestroy(): void
    {
        $this->cart->addItem('product', 1);
        $this->cart->addItem('product', 1, ['size' => 'small']);

        $this->cart->destroy();

        $this->assertEquals($this->defaults, $this->cart->toArray());
    }

    #[DataProvider('provideTestGetterValue')]
    public function testGetterValue(mixed $value, string $method): void
    {
        $this->assertSame($value, $this->cart->$method());
    }

    public static function provideTestGetterValue(): \Generator
    {
        yield 'total amount' => [0, 'totalAmount'];
        yield 'total quantity' => [0, 'totalQuantity'];
        yield 'currency' => ['EUR', 'currency'];
        yield 'currency symbol' => ['€', 'currencySymbol'];
        yield 'cart snippet' => [null, 'cartSnippet'];
    }

    #[DataProvider('provideTestGetterInstance')]
    public function testGetterInstance(string $instanceClass, string $method): void
    {
        $this->assertInstanceOf($instanceClass, $this->cart->$method());
    }

    public static function provideTestGetterInstance(): \Generator
    {
        yield 'items' => [Collection::class, 'items'];
    }
}
