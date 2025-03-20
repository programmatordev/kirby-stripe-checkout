<?php

namespace ProgrammatorDev\StripeCheckout\Cart;

use Kirby\Cms\File;
use Kirby\Cms\Page;
use ProgrammatorDev\StripeCheckout\Exception\InvalidCartItemException;

class Item
{
    private ?Page $productPage;

    private string $name;

    private int|float $price;

    private int|float $totalAmount;

    private ?File $thumbnail;

    public function __construct(
        private readonly string $id,
        private readonly int $quantity,
        private readonly ?array $options = null
    )
    {
        // find page
        $this->productPage = page($this->id);

        // check if page exists
        if ($this->productPage === null) {
            throw new InvalidCartItemException(
                sprintf('Product "%s" does not exist.', $this->id)
            );
        }

        // check if product is listed
        // draft and unlisted products are not allowed
        if (!$this->productPage->isListed()) {
            throw new InvalidCartItemException(
                sprintf('Product "%s" does not exist.', $this->id)
            );
        }

        // check if "price" field exists as it is required
        if ($this->productPage->price()->value() === null) {
            throw new InvalidCartItemException(
                sprintf('Product "%s" requires a "price" field.', $this->id)
            );
        }

        $this->name = $this->productPage->title()->value();
        $this->price = $this->productPage->price()->value();
        $this->totalAmount = $this->price * $this->quantity;
        $this->thumbnail = $this->productPage->thumbnail()?->toFile() ?? null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function options(): ?array
    {
        return $this->options;
    }

    public function productPage(): ?Page
    {
        return $this->productPage;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function price(): float|int
    {
        return $this->price;
    }

    public function totalAmount(): int|float
    {
        return $this->totalAmount;
    }

    public function thumbnail(): ?File
    {
        return $this->thumbnail;
    }
}
