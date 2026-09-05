<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use Closure;
use LogicException;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use SensitiveParameter;

/**
 * Identifies one customer action; it is neither authorization nor persisted raw data.
 *
 * @internal
 */
final readonly class AttemptToken
{
    public function __construct(#[SensitiveParameter] private string $value)
    {
        if (preg_match('/\A[A-Za-z0-9_-]{32,128}\z/', $value) !== 1) {
            throw new CheckoutInputException('checkout.attempt_token_invalid');
        }
    }

    /** @param (Closure(int): string)|null $randomBytes */
    public static function generate(?Closure $randomBytes = null): self
    {
        $bytes = ($randomBytes ?? random_bytes(...))(32);

        if (strlen($bytes) !== 32) {
            throw new LogicException('An attempt token requires exactly 32 random bytes.');
        }

        return new self(rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='));
    }

    public function value(): string
    {
        return $this->value;
    }

    public function hash(): string
    {
        return hash('sha256', $this->value);
    }
}
