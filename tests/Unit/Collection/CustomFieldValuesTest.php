<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Collection;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Collection\CustomField;
use ProgrammatorDev\StripeCheckout\Collection\CustomFieldOption;
use ProgrammatorDev\StripeCheckout\Collection\CustomFieldType;

final class CustomFieldValuesTest extends TestCase
{
    public function testExposesAValidatedDropdownField(): void
    {
        $customField = new CustomField(
            key: 'delivery',
            label: 'Delivery preference',
            type: CustomFieldType::Dropdown,
            required: true,
            defaultValue: 'morning',
            options: [
                new CustomFieldOption('morning', 'Morning'),
                new CustomFieldOption('afternoon', 'Afternoon'),
            ],
        );

        $this->assertSame('delivery', $customField->key());
        $this->assertSame('Delivery preference', $customField->label());
        $this->assertSame(CustomFieldType::Dropdown, $customField->type());
        $this->assertTrue($customField->isRequired());
        $this->assertNull($customField->minimumLength());
        $this->assertNull($customField->maximumLength());
        $this->assertSame('morning', $customField->defaultValue());
        $this->assertSame('afternoon', $customField->options()[1]->value());
        $this->assertSame([
            'key' => 'delivery',
            'label' => 'Delivery preference',
            'type' => 'dropdown',
            'required' => true,
            'minimumLength' => null,
            'maximumLength' => null,
            'defaultValue' => 'morning',
            'options' => [
                [
                    'value' => 'morning',
                    'label' => 'Morning',
                ],
                [
                    'value' => 'afternoon',
                    'label' => 'Afternoon',
                ],
            ],
        ], $customField->toArray());
    }

    #[DataProvider('invalidCustomFieldProvider')]
    public function testRejectsInvalidCustomFields(Closure $create): void
    {
        $this->expectException(InvalidArgumentException::class);

        $create();
    }

    /** @return iterable<string, array{Closure(): CustomField}> */
    public static function invalidCustomFieldProvider(): iterable
    {
        $option = new CustomFieldOption('morning', 'Morning');

        yield 'invalid key' => [
            static fn(): CustomField => new CustomField(
                'Reference',
                'Reference',
                CustomFieldType::Text,
            ),
        ];
        yield 'blank label' => [
            static fn(): CustomField => new CustomField(
                'reference',
                '',
                CustomFieldType::Text,
            ),
        ];
        yield 'invalid minimum' => [
            static fn(): CustomField => new CustomField(
                'reference',
                'Reference',
                CustomFieldType::Text,
                minimumLength: 0,
            ),
        ];
        yield 'reversed bounds' => [
            static fn(): CustomField => new CustomField(
                'reference',
                'Reference',
                CustomFieldType::Text,
                minimumLength: 10,
                maximumLength: 5,
            ),
        ];
        yield 'text options' => [
            static fn(): CustomField => new CustomField(
                'reference',
                'Reference',
                CustomFieldType::Text,
                options: [$option],
            ),
        ];
        yield 'dropdown without options' => [
            static fn(): CustomField => new CustomField(
                'reference',
                'Reference',
                CustomFieldType::Dropdown,
            ),
        ];
        yield 'duplicate dropdown options' => [
            static fn(): CustomField => new CustomField(
                'reference',
                'Reference',
                CustomFieldType::Dropdown,
                options: [$option, $option],
            ),
        ];
        yield 'unknown dropdown default' => [
            static fn(): CustomField => new CustomField(
                'reference',
                'Reference',
                CustomFieldType::Dropdown,
                defaultValue: 'afternoon',
                options: [$option],
            ),
        ];
        yield 'nonnumeric default' => [
            static fn(): CustomField => new CustomField(
                'reference',
                'Reference',
                CustomFieldType::Numeric,
                defaultValue: '12A',
            ),
        ];
        yield 'oversized default' => [
            static fn(): CustomField => new CustomField(
                'reference',
                'Reference',
                CustomFieldType::Text,
                defaultValue: str_repeat('a', 256),
            ),
        ];
    }

    #[DataProvider('invalidOptionProvider')]
    public function testRejectsInvalidOptions(string $value, string $label): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CustomFieldOption($value, $label);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidOptionProvider(): iterable
    {
        yield 'uppercase value' => ['Morning', 'Morning'];
        yield 'blank value' => ['', 'Morning'];
        yield 'blank label' => ['morning', ''];
        yield 'padded label' => ['morning', ' Morning '];
    }
}
