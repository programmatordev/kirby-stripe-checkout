<?php

namespace ProgrammatorDev\StripeCheckout\Cart;

use Kirby\Session\Session;
use Kirby\Toolkit\Collection;
use ProgrammatorDev\FluentValidator\Validator;
use ProgrammatorDev\StripeCheckout\Exception\InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Exception\NoSuchCartItemException;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\Validator\Constraints;

class Cart
{
    private const SESSION_NAME = 'stripe-checkout.cart';

    private array $options;

    private Session $session;

    /** @var Collection<string, Item> */
    private Collection $items;

    private int|float $totalAmount;

    private int $totalQuantity;

    private string $currencySymbol;

    private static ?self $instance = null;

    public function __construct(array $options = [])
    {
        $this->options = $this->resolveOptions($options);
        $this->initialize();
    }

    private function initialize(): void
    {
        $this->session = kirby()->session(['long' => true]);

        // set default session data if one does not exist
        if ($this->session->data()->get(self::SESSION_NAME) === null) {
            $this->session->data()->set(self::SESSION_NAME, [
                'items' => [],
                'totalAmount' => 0,
                'totalQuantity' => 0,
                'currency' => $this->options['currency'],
                'currencySymbol' => Currencies::getSymbol($this->options['currency']),
            ]);
        }

        // get existing session data
        $sessionData = $this->session->data()->get(self::SESSION_NAME);

        // sync cart data with session data...
        $this->items = new Collection();
        $this->totalAmount = $sessionData['totalAmount'];
        $this->totalQuantity = $sessionData['totalQuantity'];
        $this->currencySymbol = $sessionData['currencySymbol'];

        // ...including items
        foreach ($sessionData['items'] as $item) {
            $this->items->set(
                $item['key'],
                new Item($item['id'], $item['quantity'], $item['options'])
            );
        }
    }

    public static function instance(array $options = []): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::$instance = new self($options);

        return self::$instance;
    }

    public function items(): Collection
    {
        return $this->items;
    }

    public function totalAmount(): int|float
    {
        return $this->totalAmount;
    }

    public function totalQuantity(): int
    {
        return $this->totalQuantity;
    }

    public function currency(): string
    {
        return $this->options['currency'];
    }

    public function currencySymbol(): string
    {
        return $this->currencySymbol;
    }

    public function cartSnippet(bool $render = false): ?string
    {
        // if render is false, return cartSnippet option as is
        // otherwise try to render HTML
        if ($render === false) {
            return $this->options['cartSnippet'];
        }

        // render HTML
        $snippet = snippet($this->options['cartSnippet'], return: true);

        return !empty($snippet) ? $snippet : null;
    }

    public function addItem(string $id, int $quantity, ?array $options = null): string
    {
        // validate parameters
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than 0.');
        }

        // create unique key based on page id and given options
        // this means that it is possible to add the same product with different options
        // and be treated as separate items (the same shoes with different sizes are different items in the cart)
        $key = $options === null ? md5($id) : md5($id . serialize($options));

        // if the same exact item is already in the cart
        // sum the new quantity with the quantity of the existing item
        if ($item = $this->items()->get($key)) {
            $quantity += $item->quantity();
        }

        // add item to cart
        $this->items()->set(
            $key,
            new Item($id, $quantity, $options)
        );

        $this->updateTotal();
        $this->saveSession();

        return $key;
    }

    public function updateItem(string $key, int $quantity): void
    {
        // validate parameters
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than 0.');
        }

        if (($item = $this->items()->get($key)) === null) {
            throw new NoSuchCartItemException(
                sprintf('Cart item with key "%s" does not exist.', $key)
            );
        }

        $this->items()->set(
            $key,
            new Item($item->id(), $quantity, $item->options())
        );

        $this->updateTotal();
        $this->saveSession();
    }

    public function removeItem(string $key): void
    {
        if (!$this->items()->has($key)) {
            throw new NoSuchCartItemException(
                sprintf('Cart item with key "%s" does not exist.', $key)
            );
        }

        $this->items()->remove($key);

        $this->updateTotal();
        $this->saveSession();
    }

    public function destroy(): void
    {
        $this->session->data()->remove(self::SESSION_NAME);
        $this->initialize();
    }

    private function updateTotal(): void
    {
        $totalAmount = 0;
        $totalQuantity = 0;

        /** @var Item $item */
        foreach ($this->items() as $item) {
            $totalAmount += $item->totalAmount();
            $totalQuantity += $item->quantity();
        }

        $this->totalAmount = $totalAmount;
        $this->totalQuantity = $totalQuantity;
    }

    private function saveSession(): void
    {
        $data = [
            'items' => [],
            'totalAmount' => $this->totalAmount(),
            'totalQuantity' => $this->totalQuantity(),
            'currency' => $this->currency(),
            'currencySymbol' => $this->currencySymbol(),
        ];

        /** @var Item $item */
        foreach ($this->items as $key => $item) {
            $data['items'][] = [
                'key' => $key,
                'id' => $item->id(),
                'quantity' => $item->quantity(),
                'options' => $item->options()
            ];
        }

        $this->session->data()->set(self::SESSION_NAME, $data);
    }

    private function resolveOptions(array $options): array
    {
        // merge options with defaults
        $options = array_merge([
            'currency' => strtoupper(option('programmatordev.stripe-checkout.currency')),
            'cartSnippet' => option('programmatordev.stripe-checkout.cartSnippet')
        ], $options);

        // validate options
        Validator::collection([
            'currency' => [
                new Constraints\NotBlank(),
                new Constraints\Choice(Currencies::getCurrencyCodes())
            ],
            'cartSnippet' => [
                new Constraints\AtLeastOneOf([
                    new Constraints\IsNull(),
                    new Constraints\NotBlank(),
                ])
            ]
        ])->assert($options, 'options');

        return $options;
    }
}
