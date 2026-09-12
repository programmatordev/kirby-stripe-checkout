<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use Closure;
use Kirby\Uuid\Uri;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;
use Throwable;

/** @internal Formats display labels only; never generates an order's identity. */
final class OrderNumberFormatter
{
    /** @param (Closure(string): string)|null $formatter */
    public function __construct(private readonly ?Closure $formatter = null) {}

    public function format(string $uuid): string
    {
        $reference = new Uri([
            'scheme' => 'page',
            'host' => OrderData::text($uuid),
        ]);
        $pageUuid = OrderData::uuid($reference->toString());

        try {
            $number = $this->formatter === null
                ? 'ORD-' . strtoupper($uuid)
                : ($this->formatter)($pageUuid);

            // Check controls before trimming so a trailing newline isn't accepted.
            $number = OrderData::string($number);

            if (TextValidator::isSingleLine($number) === false) {
                throw new OrderDataException();
            }

            return OrderData::text(trim($number), 80);
        } catch (Throwable) {
            throw new OrderDataException('order.number_invalid');
        }
    }
}
