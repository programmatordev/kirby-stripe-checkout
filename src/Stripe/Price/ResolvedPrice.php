<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Price;

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Money\MoneySnapshot;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Product\Support\ProductData;
use Throwable;

/**
 * Contains validated Stripe Price and Product facts for display and checkout.
 */
final readonly class ResolvedPrice
{
    private string $priceId;
    private string $productId;
    private string $name;
    private MoneySnapshot $unitPrice;
    private string $taxBehavior;
    private ?string $description;

    /** @var list<string> */
    private array $images;

    private ?string $nickname;
    private ?string $taxCode;

    /** @param array<mixed> $images */
    public function __construct(
        string $priceId,
        string $productId,
        string $name,
        MoneySnapshot $unitPrice,
        string $taxBehavior,
        ?string $description = null,
        array $images = [],
        ?string $nickname = null,
        ?string $taxCode = null,
    ) {
        $this->priceId = (new StripePriceReference($priceId))->priceId();

        if (preg_match('/^prod_[A-Za-z0-9]{1,249}$/D', $productId) !== 1) {
            throw new InvalidProductException('product.stripe_product_ineligible');
        }

        try {
            $price = (new StripeCurrencyRegistry())->toMoney($unitPrice);
        } catch (Throwable $error) {
            throw new InvalidProductException('product.price_invalid', $error);
        }

        if ($price->isNegative()) {
            throw new InvalidProductException('product.price_invalid');
        }

        if (in_array($taxBehavior, ['exclusive', 'inclusive', 'unspecified'], true) === false) {
            throw new InvalidProductException('product.stripe_price_ineligible');
        }

        try {
            $this->name = ProductData::name($name);
        } catch (InvalidProductException $error) {
            throw new InvalidProductException('product.stripe_product_ineligible', $error);
        }

        $this->productId = $productId;
        $this->unitPrice = $unitPrice;
        $this->taxBehavior = $taxBehavior;
        $this->description = ProductData::optionalString($description, 5000);
        $this->images = $this->validateImages($images);
        $this->nickname = ProductData::optionalString($nickname, 500);
        $this->taxCode = ProductData::optionalString($taxCode, 500);
    }

    public function priceId(): string
    {
        return $this->priceId;
    }

    public function productId(): string
    {
        return $this->productId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function price(): Money
    {
        return (new StripeCurrencyRegistry())->toMoney($this->unitPrice);
    }

    public function taxBehavior(): string
    {
        return $this->taxBehavior;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /** @return list<string> */
    public function images(): array
    {
        return $this->images;
    }

    public function nickname(): ?string
    {
        return $this->nickname;
    }

    public function taxCode(): ?string
    {
        return $this->taxCode;
    }

    /** @return array<string, bool|int|string|list<string>|null> */
    public function toArray(): array
    {
        return [
            'priceId' => $this->priceId,
            'productId' => $this->productId,
            'name' => $this->name,
            'currency' => $this->unitPrice->currency(),
            'minorAmount' => $this->unitPrice->minorAmount(),
            'taxBehavior' => $this->taxBehavior,
            'description' => $this->description,
            'images' => $this->images,
            'nickname' => $this->nickname,
            'taxCode' => $this->taxCode,
        ];
    }

    /**
     * @param array<mixed> $images
     * @return list<string>
     */
    private function validateImages(array $images): array
    {
        if (array_is_list($images) === false) {
            throw new InvalidProductException('product.images_invalid');
        }

        $validated = [];

        foreach ($images as $image) {
            $image = ProductData::requiredString($image, 2048);

            if (
                in_array(parse_url($image, PHP_URL_SCHEME), ['http', 'https'], true) === false
            ) {
                throw new InvalidProductException('product.images_invalid');
            }

            $validated[$image] = true;
        }

        if (count($validated) > 8) {
            throw new InvalidProductException('product.images_invalid');
        }

        return array_keys($validated);
    }
}
