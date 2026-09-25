<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Order;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderNumberFormatter;
use RuntimeException;

final class OrderIdentityTest extends TestCase
{
    #[DataProvider('validUuids')]
    public function testUuidValidationPreservesTheCompleteReference(string $uuid, string $type): void
    {
        $this->assertSame($uuid, OrderData::uuid($uuid, $type));
    }

    /** @return iterable<array{string, string}> */
    public static function validUuids(): iterable
    {
        yield ['page://Abc123def456GHI7', 'page'];
        yield ['page://4c2c8a52-284b-4d3b-9126-5216ff3428ba', 'page'];
        yield ['user://customer', 'user'];
    }

    #[DataProvider('invalidUuids')]
    public function testUuidValidationRejectsMalformedOrWrongTypeReferences(string $uuid): void
    {
        $this->expectException(OrderDataException::class);
        OrderData::uuid($uuid);
    }

    /** @return iterable<array{string}> */
    public static function invalidUuids(): iterable
    {
        yield [''];
        yield ['Abc123'];
        yield ['page://'];
        yield ['user://customer'];
        yield ['page://Abc123/path'];
        yield ['page://Abc123?query=value'];
        yield ['page://Abc123#fragment'];
        yield [' page://Abc123'];
        yield ["page://Abc123\n"];
    }

    public function testNumberFormatterAcceptsNativeShortAndV4Ids(): void
    {
        $formatter = new OrderNumberFormatter();
        $this->assertSame('ORD-ABC123', $formatter->format('Abc123'));
        $this->assertSame('ORD-4C2C8A52-284B-4D3B-9126-5216FF3428BA', $formatter->format('4c2c8a52-284b-4d3b-9126-5216ff3428ba'));
        $custom = new OrderNumberFormatter(function (string $uuid): string {
            $this->assertSame('page://Abc123', $uuid);
            return '  WEB-123  ';
        });
        $this->assertSame('WEB-123', $custom->format('Abc123'));
        $this->assertSame(str_repeat('É', 80), (new OrderNumberFormatter(static fn(): string => str_repeat('É', 80)))->format('id'));
    }

    #[DataProvider('invalidNumbers')]
    public function testNumberValidationDoesNotLeakCallbackData(string $number): void
    {
        $this->expectException(OrderDataException::class);
        $this->expectExceptionMessage('order.number_invalid');
        (new OrderNumberFormatter(static fn(): string => $number))->format('id');
    }

    /** @return iterable<array{string}> */
    public static function invalidNumbers(): iterable
    {
        yield [''];
        yield ['  '];
        yield ["ORDER\n"];
        yield ["ORDER\u{2028}NEXT"];
        yield ["\xff"];
        yield [str_repeat('É', 81)];
    }

    public function testFormatterFailureIsSanitized(): void
    {
        try {
            (new OrderNumberFormatter(static fn(): never => throw new RuntimeException('private credential')))->format('id');
            $this->fail('Expected invalid formatter.');
        } catch (OrderDataException $error) {
            $this->assertSame('order.number_invalid', $error->errorCode());
            $this->assertStringNotContainsString('private', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }
}
