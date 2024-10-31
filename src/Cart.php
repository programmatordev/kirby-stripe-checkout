<?php

namespace ProgrammatorDev\StripeCheckout;

use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Money\Exception\UnknownCurrencyException;
use Kirby\Session\Session;
use ProgrammatorDev\StripeCheckout\Exception\CartException;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\AtLeastOneOf;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\IsNull;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;

class Cart
{
    private const SESSION_NAME = 'stripe-checkout.cart';

    private array $options;

    private Session $session;

    private array $defaultContents;

    private array $contents;

    /**
     * @throws UnknownCurrencyException
     * @throws NumberFormatException
     * @throws RoundingNecessaryException
     */
    public function __construct(array $options = [])
    {
        $this->options = $this->resolveOptions($options);
        $this->session = kirby()->session(['long' => true]);

        $this->defaultContents = [
            'items' => [],
            'totalAmount' => 0,
            'totalQuantity' => 0,
            'totalAmountFormatted' => MoneyFormatter::format(0, $this->options['currency']),
        ];

        $this->contents = $this->session->get(self::SESSION_NAME) ?? $this->defaultContents;
    }

    /**
     * @throws UnknownCurrencyException
     * @throws NumberFormatException
     * @throws RoundingNecessaryException
     * @throws CartException
     */
    public function addItem(array $data): string
    {
        $data = $this->resolveAddItem($data);

        // find page
        if (($productPage = page($data['id'])) === null) {
            throw new CartException('Product does not exist.');
        }

        // check if "price" field exists
        if ($productPage->price()->value() === null) {
            throw new CartException('Product requires a "price" field.');
        }

        // create unique item id based on product id and given options
        // this means that it is possible to add the same product but with different options
        // and be treated as separate items (the same shoes with different sizes are different items in the cart)
        $lineItemId = ($data['options'] === null)
            ? md5($data['id'])
            : md5($data['id'] . serialize($data['options']));

        // if the same exact item is already in the cart
        // sum the new quantity with the quantity of the existing item
        if (($item = $this->getContentsItem($lineItemId)) !== null) {
            $data['quantity'] += $item['quantity'];
        }

        // set complete item data
        $data = array_merge($data, [
            'name' => $productPage->title()->value(),
            'price' => $productPage->price()->toFloat(),
            'image' => $productPage->cover()->toFile()?->url(),
        ]);

        // trigger event to allow cart item data change
        $data = kirby()->apply('stripe-checkout.cart.addItem:before', [
            'itemContent' => $data,
            'productPage' => $productPage
        ], 'itemContent');

        $this->setContentsItem($lineItemId, $data);
        $this->updateTotals();
        $this->saveToSession();

        return $lineItemId;
    }

    /**
     * @throws CartException
     * @throws NumberFormatException
     * @throws RoundingNecessaryException
     * @throws UnknownCurrencyException
     */
    public function updateItem(string $lineItemId, array $data): void
    {
        $data = $this->resolveUpdateItem($data);

        if (($item = $this->getContentsItem($lineItemId)) === null) {
            throw new CartException('Cart item does not exist.');
        }

        // only quality is allowed to be updated
        $item = array_merge($item, [
            'quantity' => (int) $data['quantity']
        ]);

        $this->setContentsItem($lineItemId, $item);
        $this->updateTotals();
        $this->saveToSession();
    }

    /**
     * @throws CartException
     * @throws NumberFormatException
     * @throws RoundingNecessaryException
     * @throws UnknownCurrencyException
     */
    public function removeItem(string $lineItemId): void
    {
        if ($this->getContentsItem($lineItemId) === null) {
            throw new CartException('Cart item does not exist.');
        }

        $this->removeContentsItem($lineItemId);
        $this->updateTotals();
        $this->saveToSession();
    }

    public function getItems(): array
    {
        return $this->contents['items'];
    }

    public function getTotalQuantity(): int
    {
        return $this->contents['totalQuantity'];
    }

    public function getTotalAmount(): int|float
    {
        return $this->contents['totalAmount'];
    }

    public function getTotalAmountFormatted(): string
    {
        return $this->contents['totalAmountFormatted'];
    }

    public function destroy(): void
    {
        $this->contents = $this->defaultContents;
        $this->session->remove(self::SESSION_NAME);
    }

    public function getContents(): array
    {
        return $this->contents;
    }

    private function getContentsItem($lineItemId): ?array
    {
        if (!isset($this->contents['items'][$lineItemId])) {
            return null;
        }

        return $this->contents['items'][$lineItemId];
    }

    private function setContentsItem($lineItemId, array $data): void
    {
        $data = $this->resolveSetContentsItem($data);
        $this->contents['items'][$lineItemId] = $data;
    }

    private function removeContentsItem(string $lineItemId): void
    {
        unset($this->contents['items'][$lineItemId]);
    }

    /**
     * @throws UnknownCurrencyException
     * @throws NumberFormatException
     * @throws RoundingNecessaryException
     */
    private function updateTotals(): void
    {
        $totalAmount = 0;
        $totalQuantity = 0;

        foreach ($this->contents['items'] as $lineItemId => $item) {
            $item['subtotal'] = $item['price'] * $item['quantity'];
            $item['priceFormatted'] = MoneyFormatter::format($item['price'], $this->options['currency']);
            $item['subtotalFormatted'] = MoneyFormatter::format($item['subtotal'], $this->options['currency']);

            $this->setContentsItem($lineItemId, $item);

            $totalAmount += $item['subtotal'];
            $totalQuantity += $item['quantity'];
        }

        $this->contents['totalAmount'] = $totalAmount;
        $this->contents['totalQuantity'] = $totalQuantity;
        $this->contents['totalAmountFormatted'] = MoneyFormatter::format($totalAmount, $this->options['currency']);
    }

    private function saveToSession(): void
    {
        $this->session->set(self::SESSION_NAME, $this->contents);
    }

    private function resolveOptions(array $options): array
    {
        $resolver = new OptionsResolver();

        $resolver->define('currency')
            ->required()
            ->allowedTypes('string')
            ->allowedValues(...Currencies::getCurrencyCodes())
            ->normalize(function (Options $options, string $currency): string {
                return strtoupper($currency);
            });

        return $resolver->resolve($options);
    }

    private function resolveUpdateItem(array $data): array
    {
        $resolver = new OptionsResolver();

        $resolver->define('quantity')
            ->required()
            ->allowedTypes('int')
            ->allowedValues(Validation::createIsValidCallable(new GreaterThan(0)));

        return $resolver->resolve($data);
    }

    private function resolveAddItem(array $data): array
    {
        $resolver = new OptionsResolver();

        $resolver->define('id')
            ->required()
            ->allowedTypes('string')
            ->allowedValues(Validation::createIsValidCallable(new NotBlank()));

        $resolver->define('quantity')
            ->required()
            ->allowedTypes('int')
            ->allowedValues(Validation::createIsValidCallable(new GreaterThan(0)));

        $resolver->define('options')
            ->default(null)
            ->allowedTypes('null', 'array')
            ->allowedValues(Validation::createIsValidCallable(new AtLeastOneOf([new IsNull(), new NotBlank()])));

        return $resolver->resolve($data);
    }

    private function resolveSetContentsItem(array $data): array
    {
        $resolver = new OptionsResolver();
        $resolver->setIgnoreUndefined();

        $resolver->define('id')
            ->required()
            ->allowedTypes('string')
            ->allowedValues(Validation::createIsValidCallable(new NotBlank()));

        $resolver->define('name')
            ->required()
            ->allowedTypes('string')
            ->allowedValues(Validation::createIsValidCallable(new NotBlank()));

        $resolver->define('price')
            ->required()
            ->allowedTypes('int', 'float')
            ->allowedValues(Validation::createIsValidCallable(new GreaterThan(0)));

        $resolver->define('quantity')
            ->required()
            ->allowedTypes('int')
            ->allowedValues(Validation::createIsValidCallable(new GreaterThan(0)));

        $resolver->define('image')
            ->default(null)
            ->allowedTypes('null', 'string')
            ->allowedValues(Validation::createIsValidCallable(new AtLeastOneOf([new IsNull(), new NotBlank()])));

        $resolver->define('options')
            ->default(null)
            ->allowedTypes('null', 'array')
            ->allowedValues(Validation::createIsValidCallable(new AtLeastOneOf([new IsNull(), new NotBlank()])));

        return $resolver->resolve($data);
    }
}
