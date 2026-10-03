<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Order;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\CustomFieldSnapshot;

final class CustomFieldSnapshotTest extends TestCase
{
    #[DataProvider('returnedAnswers')]
    public function testPreservesReturnedAnswerSemantics(string $type, bool $answered, ?string $value): void
    {
        $data = [
            'key' => 'field1',
            'type' => $type,
            'label' => 'Field',
            'required' => true,
            'configured' => true,
            'answered' => $answered,
            'value' => $value,
        ];

        $this->assertSame($data, CustomFieldSnapshot::fromArray($data)->toArray());
    }

    /** @return iterable<string, array{string, bool, ?string}> */
    public static function returnedAnswers(): iterable
    {
        yield 'required field not yet answered' => ['text', false, null];
        yield 'empty text answer' => ['text', true, ''];
        yield 'text whitespace is preserved' => ['text', true, ' answer '];
        yield 'numeric leading zeros are preserved' => ['numeric', true, '0012'];
        yield 'numeric field not yet answered' => ['numeric', false, null];
        yield 'dropdown answer' => ['dropdown', true, 'option1'];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('inconsistentAnswers')]
    public function testRejectsInconsistentStoredFieldFacts(array $overrides): void
    {
        $data = [
            'key' => 'field1',
            'type' => 'text',
            'label' => 'Field',
            'required' => false,
            'configured' => true,
            'answered' => true,
            'value' => 'answer',
            ...$overrides,
        ];
        $this->expectException(OrderDataException::class);

        CustomFieldSnapshot::fromArray($data);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function inconsistentAnswers(): iterable
    {
        yield 'invalid key syntax' => [['key' => 'field-name']];
        yield 'field absent from Session' => [['configured' => false]];
        yield 'answered without a value' => [['value' => null]];
        yield 'value marked unanswered' => [['answered' => false]];
        yield 'non-numeric numeric answer' => [['type' => 'numeric']];
        yield 'fractional numeric answer' => [['type' => 'numeric', 'value' => '1.2']];
        yield 'empty numeric answer' => [['type' => 'numeric', 'value' => '']];
    }
}
