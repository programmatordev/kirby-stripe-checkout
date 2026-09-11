<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use Closure;
use Kirby\Uuid\Uuid;
use LogicException;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderUuidValidator;
use SensitiveParameter;
use Throwable;

/**
 * Carries the future Kirby Order UUID and an independent random nonce.
 *
 * The UUID addresses the not-yet-created Order directly on retries. The nonce
 * keeps the public Order UUID from being the complete retry token. Only a hash
 * of the complete transport value is persisted; this token is not authorization.
 *
 * @internal
 */
final readonly class AttemptToken
{
    private const NONCE_BYTES = 32;

    private string $orderUuid;

    public function __construct(#[SensitiveParameter] private string $value)
    {
        if (
            strlen($value) > 256
            || preg_match('/\A(?<uuid>[A-Za-z0-9_-]+)\.(?<nonce>[A-Za-z0-9_-]{43})\z/D', $value, $parts) !== 1
        ) {
            throw new CheckoutInputException('checkout.attempt_token_invalid');
        }

        $orderUuid = self::decode($parts['uuid']);
        $nonce = self::decode($parts['nonce']);

        try {
            if (
                $orderUuid === null
                || $nonce === null
                || self::encode($orderUuid) !== $parts['uuid']
                || self::encode($nonce) !== $parts['nonce']
                || strlen($nonce) !== self::NONCE_BYTES
            ) {
                throw new CheckoutInputException('checkout.attempt_token_invalid');
            }

            // Order identities follow Kirby's configured UUID vocabulary,
            // including custom generators as well as the bundled formats.
            OrderData::uuid('page://' . $orderUuid);
        } catch (Throwable) {
            throw new CheckoutInputException('checkout.attempt_token_invalid');
        }

        $this->orderUuid = $orderUuid;
    }

    /**
     * Generates the future Order identity through Kirby before the Order exists.
     *
     * @param (Closure(int): string)|null $randomBytes
     * @param (Closure(): string)|null $uuidGenerator
     */
    public static function generate(
        ?Closure $randomBytes = null,
        ?Closure $uuidGenerator = null,
    ): self {
        $orderUuid = ($uuidGenerator ?? static fn(): string => Uuid::generate())();

        // Page::create() normalizes every slug. Validate before exposing a token
        // whose embedded UUID could not address its eventual Order unchanged.
        OrderUuidValidator::validate($orderUuid);

        return self::forOrder($orderUuid, $randomBytes);
    }

    /** @param (Closure(int): string)|null $randomBytes */
    public static function forOrder(string $orderUuid, ?Closure $randomBytes = null): self
    {
        $bytes = ($randomBytes ?? random_bytes(...))(self::NONCE_BYTES);

        if (strlen($bytes) !== self::NONCE_BYTES) {
            throw new LogicException('An attempt token requires exactly 32 random bytes.');
        }

        return new self(self::encode($orderUuid) . '.' . self::encode($bytes));
    }

    public function value(): string
    {
        return $this->value;
    }

    /** Kirby UUID ID reserved for the Order created by this attempt. */
    public function orderUuid(): string
    {
        return $this->orderUuid;
    }

    public function hash(): string
    {
        return hash('sha256', $this->value);
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): ?string
    {
        $remainder = strlen($value) % 4;

        if ($remainder === 1) {
            return null;
        }

        $decoded = base64_decode(
            strtr($value, '-_', '+/') . str_repeat('=', (4 - $remainder) % 4),
            true,
        );

        return is_string($decoded) ? $decoded : null;
    }
}
