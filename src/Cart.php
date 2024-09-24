<?php

namespace ProgrammatorDev\StripeCheckout;

use Kirby\Session\Session;
use ProgrammatorDev\StripeCheckout\Exception\CartItemDoesNotExistException;
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

    private Session $session;

    private array $defaultContents;

    private ?array $contents;

    public function __construct()
    {
        $this->session = kirby()->session(['long' => true]);

        $this->defaultContents = [
            'items' => [],
            'totalAmount' => 0.0,
            'totalQuantity' => 0
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
     * @throws CartItemDoesNotExistException
     */
    public function updateItem(string $lineItemId, int $quantity): void
    {
        if (($item = $this->getContentsItem($lineItemId)) === null) {
            throw new CartItemDoesNotExistException('Cart item does not exist.');
        }

        $item['quantity'] = $quantity;
        $item = $this->resolveItem($item);

        $this->setContentsItem($lineItemId, $item);
        $this->updateTotals();
        $this->saveToSession();
    }

    /**
     * @throws CartItemDoesNotExistException
     */
    public function removeItem(string $lineItemId): void
    {
        if ($this->getContentsItem($lineItemId) === null) {
            throw new CartItemDoesNotExistException('Cart item does not exist.');
        }

        $this->removeContentsItem($lineItemId);
        $this->updateTotals();
        $this->saveToSession();
    }

    public function destroy(): void
    {
        $this->contents = $this->defaultContents;
        $this->session->remove(self::SESSION_NAME);
    }

    public function getContents(): ?array
    {
        return $this->contents;
    }

    public function getItems(): array
    {
        return $this->contents['items'];
    }

    public function getTotalAmount(): float
    {
        return $this->contents['totalAmount'];
    }

    public function getTotalQuantity(): int
    {
        return $this->contents['totalQuantity'];
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

    private function updateTotals(): void
    {
        $totalAmount = 0;
        $totalQuantity = 0;

        foreach ($this->contents['items'] as $lineItemId => $item) {
            $item['subtotal'] = round($item['price'] * $item['quantity'], 2);
            $this->setContentsItem($lineItemId, $item);

            $totalAmount += $item['subtotal'];
            $totalQuantity += $item['quantity'];
        }

        $this->contents['totalAmount'] = round($totalAmount, 2);
        $this->contents['totalQuantity'] = $totalQuantity;
    }

    private function saveToSession(): void
    {
        $this->session->set(self::SESSION_NAME, $this->contents);
    }

    private function resolveItem(array $data): array
    {
        $resolver = new OptionsResolver();

        $resolver->setDefaults([
            'image' => null,
            'options' => null,
            'subtotal' => 0.0
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

        $resolver->setNormalizer('price', function (Options $options, int|float $value): float {
            return round($value, 2);
        });

        return $resolver->resolve($data);
    }
}
