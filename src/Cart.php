<?php

namespace ProgrammatorDev\StripeCheckout;

use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Money\Exception\UnknownCurrencyException;
use Kirby\Session\Session;
use ProgrammatorDev\StripeCheckout\Exception\CartItemNotFoundException;
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
    private const SESSION_NAME = 'stripe.checkout.cart';

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

    public function addItem(array $data): string
    {
        $item = $this->resolveItem($data);

        // create unique item id based on product id and given options
        // this means that it is possible to add the same product but with different options
        // and be treated as separate items (the same shoes with different sizes are different items in the cart)
        $lineItemId = ($item['options'] === null)
            ? md5($item['id'])
            : md5($item['id'] . serialize($item['options']));

        // if the same exact item is already in the cart
        // sum the new quantity with the quantity of the existing item
        if (($contentsItem = $this->getContentsItem($lineItemId)) !== null) {
            $item['quantity'] += $contentsItem['quantity'];
        }

        $this->setContentsItem($lineItemId, $item);
        $this->updateTotals();
        $this->saveToSession();

        return $lineItemId;
    }

    /**
     * @throws CartItemNotFoundException
     */
    public function updateItem(string $lineItemId, int $quantity): void
    {
        if (($item = $this->getContentsItem($lineItemId)) === null) {
            throw new CartItemNotFoundException('Cart item does not exist.');
        }

        $item['quantity'] = $quantity;
        $item = $this->resolveItem($item);

        $this->setContentsItem($lineItemId, $item);
        $this->updateTotals();
        $this->saveToSession();
    }

    /**
     * @throws CartItemNotFoundException
     */
    public function removeItem(string $lineItemId): void
    {
        if ($this->getContentsItem($lineItemId) === null) {
            throw new CartItemNotFoundException('Cart item does not exist.');
        }

        $this->removeContentsItem($lineItemId);
        $this->updateTotals();
        $this->saveToSession();
    }

    public function getItems(): array
    {
        return $this->contents['items'];
    }

    public function getTotalAmount(): int|float
    {
        return $this->contents['totalAmount'];
    }

    public function getTotalQuantity(): int
    {
        return $this->contents['totalQuantity'];
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

        $resolver->setRequired(['currency']);
        $resolver->setAllowedTypes('currency', 'string');
        $resolver->setAllowedValues('currency', Currencies::getCurrencyCodes());

        $resolver->setNormalizer('currency', function (Options $options, string $currency): string {
            return strtoupper($currency);
        });

        return $resolver->resolve($options);
    }

    private function resolveItem(array $data): array
    {
        $resolver = new OptionsResolver();
        $resolver->setIgnoreUndefined();

        $resolver->setDefaults([
            'image' => null,
            'options' => null
        ]);

        $resolver->setRequired(['id', 'name', 'price', 'quantity']);

        $resolver->setAllowedTypes('id', 'string');
        $resolver->setAllowedTypes('name', 'string');
        $resolver->setAllowedTypes('image', ['null', 'string']);
        $resolver->setAllowedTypes('price', ['int', 'float']);
        $resolver->setAllowedTypes('quantity', 'int');
        $resolver->setAllowedTypes('options', ['null', 'scalar[]']);

        $resolver->setAllowedValues('id', Validation::createIsValidCallable(new NotBlank()));
        $resolver->setAllowedValues('name', Validation::createIsValidCallable(new NotBlank()));
        $resolver->setAllowedValues('image', Validation::createIsValidCallable(new AtLeastOneOf([new IsNull(), new NotBlank()])));
        $resolver->setAllowedValues('price', Validation::createIsValidCallable(new GreaterThan(0)));
        $resolver->setAllowedValues('quantity', Validation::createIsValidCallable(new GreaterThan(0)));
        $resolver->setAllowedValues('options', Validation::createIsValidCallable(new AtLeastOneOf([new IsNull(), new NotBlank()])));

        return $resolver->resolve($data);
    }
}
