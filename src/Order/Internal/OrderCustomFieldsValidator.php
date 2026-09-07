<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use Kirby\Data\Txt;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;

/**
 * @internal Validates developer-added order Page fields, not Checkout form custom fields.
 * Protects canonical handles and rejects aliases that Kirby would normalize onto another field.
 */
final class OrderCustomFieldsValidator
{
    /** @return array<string, mixed> */
    public static function validate(mixed $fields): array
    {
        try {
            if (is_array($fields) === false) {
                throw new OrderDataException();
            }

            $result = [];

            foreach ($fields as $key => $value) {
                if (is_string($key) === false || OrderSchema::isReserved($key)) {
                    throw new OrderDataException();
                }

                OrderData::text($key);

                // Kirby accepts/slugs loose handles. An extension must not silently
                // rename one or collapse distinct keys when it reaches a text file.
                $normalized = array_key_first(Txt::decode(Txt::encode([$key => 'test'])));

                if ($normalized !== strtolower($key) || array_key_exists($normalized, $result)) {
                    throw new OrderDataException();
                }

                $result[$normalized] = OrderData::normalize($value);
            }

            ksort($result);

            return $result;
        } catch (OrderDataException) {
            throw new OrderDataException('order.project_fields_invalid');
        }
    }
}
