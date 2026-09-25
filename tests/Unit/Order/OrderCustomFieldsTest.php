<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Order;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderCustomFieldsValidator;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSchema;
use stdClass;

final class OrderCustomFieldsTest extends TestCase
{
    public function testResourcesAreNotCustomFieldData(): void
    {
        $resource = fopen('php://memory', 'r+');

        try {
            $this->expectException(OrderDataException::class);
            OrderCustomFieldsValidator::validate(['data' => $resource]);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    public function testCustomFieldsNormalizeNativeHandlesAndPreserveAllowedValues(): void
    {
        $fields = OrderCustomFieldsValidator::validate([
            'salesChannel' => 'website',
            'internal_note' => "First\nSecond",
            'data' => [
                'b' => [true, null, 2],
                'a' => 'text',
            ],
        ]);
        $this->assertSame(['data', 'internal_note', 'saleschannel'], array_keys($fields));
        $this->assertIsArray($fields['data']);
        $this->assertSame(['a', 'b'], array_keys($fields['data']));
        $this->assertSame([true, null, 2], $fields['data']['b']);

        $reservedFields = [...OrderSchema::fields(), 'slug', 'template'];

        foreach ($reservedFields as $field) {
            $this->assertTrue(OrderSchema::isReserved(strtoupper($field)));

            try {
                OrderCustomFieldsValidator::validate([strtoupper($field) => null]);
                $this->fail('Reserved field accepted: ' . $field);
            } catch (OrderDataException $error) {
                $this->assertSame('order.custom_fields_invalid', $error->errorCode());
            }
        }
    }

    #[DataProvider('invalidCustomFields')]
    public function testRejectsUnstableOrReservedCustomFields(mixed $fields): void
    {
        $this->expectException(OrderDataException::class);
        $this->expectExceptionMessage('order.custom_fields_invalid');
        OrderCustomFieldsValidator::validate($fields);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidCustomFields(): iterable
    {
        yield 'float' => [['data' => 1.1]];
        yield 'object' => [['data' => new stdClass()]];
        yield 'callable' => [['data' => static fn(): string => 'value']];
        yield 'invalid UTF8' => [['data' => "\xff"]];
        yield 'numeric handle' => [[0 => 'value']];
        yield 'slugged alias' => [['sales channel' => 'value']];
        yield 'case collision' => [[
            'field' => null,
            'FIELD' => 'value',
        ]];
        yield 'list' => [['one']];
        yield 'not map' => [false];
    }

    public function testCyclicArraysAreRejectedAndReferencesAreDetached(): void
    {
        $name = 'Original';
        $fields = OrderCustomFieldsValidator::validate(['name' => &$name]);
        $name = 'Changed';
        $this->assertSame('Original', $fields['name']);
        $recursive = [];
        $recursive['self'] = &$recursive;
        $this->expectException(OrderDataException::class);
        OrderCustomFieldsValidator::validate(['data' => $recursive]);
    }
}
