<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Price;

/**
 * Carries untrusted Stripe Price and Product data to the eligibility boundary.
 *
 * @internal
 */
final readonly class PriceRecord
{
    /**
     * @param list<string> $productImages
     */
    public function __construct(
        public string $priceId,
        public bool $active,
        public string $billingScheme,
        public string $currency,
        public bool $hasCustomUnitAmount,
        public ?string $nickname,
        public bool $hasRecurring,
        public ?string $taxBehavior,
        public bool $hasTiers,
        public ?string $tiersMode,
        public bool $hasQuantityTransform,
        public string $type,
        public ?int $unitAmount,
        public ?string $unitAmountDecimal,
        public ?string $productId,
        public bool $productActive,
        public ?string $productName,
        public ?string $productDescription,
        public array $productImages,
        public ?string $productTaxCode,
    ) {}
}
