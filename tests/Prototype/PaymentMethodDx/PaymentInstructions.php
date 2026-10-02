<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Prototype\PaymentMethodDx;

use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;

/**
 * Limited voucher/QR projection for the API comparison, not universal instruction support.
 * Kept as the comparison baseline: it loses provider-specific and nested details that the separate Stripe-shaped payload experiment preserves.
 */
final readonly class PaymentInstructions
{
    private const KEYS = ['hostedUrl', 'reference', 'entity', 'qrCodeImageUrl', 'expiresAt'];

    public function __construct(
        private ?string $hostedUrl = null,
        private ?string $reference = null,
        private ?string $entity = null,
        private ?string $qrCodeImageUrl = null,
        private ?DateTimeImmutable $expiresAt = null,
    ) {}

    public function hostedUrl(): ?string
    {
        return $this->hostedUrl;
    }

    public function reference(): ?string
    {
        return $this->reference;
    }

    public function entity(): ?string
    {
        return $this->entity;
    }

    public function qrCodeImageUrl(): ?string
    {
        return $this->qrCodeImageUrl;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'hostedUrl' => $this->hostedUrl,
            'reference' => $this->reference,
            'entity' => $this->entity,
            'qrCodeImageUrl' => $this->qrCodeImageUrl,
            'expiresAt' => $this->expiresAt === null ? null : OrderData::timestamp($this->expiresAt),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        OrderData::validateAllowedKeys($data, self::KEYS);
        OrderData::validateRequiredKeys($data, self::KEYS);

        return new self(
            hostedUrl: OrderData::nullableSingleLine($data['hostedUrl']),
            reference: OrderData::nullableSingleLine($data['reference']),
            entity: OrderData::nullableSingleLine($data['entity']),
            qrCodeImageUrl: OrderData::nullableSingleLine($data['qrCodeImageUrl']),
            expiresAt: $data['expiresAt'] === null ? null : OrderData::date($data['expiresAt']),
        );
    }
}
