<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Kirby\Form\Form;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\TestWorkspace;

final class VariantFieldTest extends KirbyTestCase
{
    public function testFieldRemainsTechnicallyEditableOnSingleLanguageSites(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            beforeApp: static function (TestWorkspace $workspace): void {
                $workspace->writePageBlueprint('single-language-product', [
                    'title' => 'Product',
                    'fields' => [
                        'variants' => [
                            'type' => 'stripe-checkout-variants',
                            'priceSource' => 'kirby',
                            'currency' => 'EUR',
                        ],
                    ],
                ]);
            },
        );
        $this->kirby = $this->environment->app();
        $page = $this->kirby->site()->createChild([
            'slug' => 'product',
            'template' => 'single-language-product',
            'content' => [
                'title' => 'Product',
                'variants' => self::canonicalFixture(),
            ],
        ]);
        $props = Form::for($page)->fields()->field('variants')->toArray();

        $this->assertFalse($props['serverTechnicalLocked'] ?? false);
        $value = $props['value'];
        $this->assertIsArray($value);
        $this->assertSame(self::canonicalFixture()['groups'], $value['groups'] ?? null);
        $variants = $value['variants'] ?? null;
        $this->assertIsArray($variants);
        $this->assertCount(2, $variants);
    }

    public function testFieldExposesCanonicalDataAndLocksTechnicalEditingInTranslations(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            languages: [
                ['code' => 'en', 'default' => true, 'locale' => 'en_US', 'name' => 'English'],
                ['code' => 'pt', 'locale' => 'pt_PT', 'name' => 'Português'],
            ],
            beforeApp: static function (TestWorkspace $workspace): void {
                $workspace->writePageBlueprint('product', [
                    'title' => 'Product',
                    'fields' => [
                        'variants' => [
                            'type' => 'stripe-checkout-variants',
                            'priceSource' => 'stripe',
                            'currency' => 'EUR',
                        ],
                    ],
                ]);
            },
        );
        $this->kirby = $this->environment->app();
        $page = $this->kirby->site()->createChild([
            'slug' => 'product',
            'template' => 'product',
            'content' => [
                'title' => 'Product',
                'variants' => self::canonicalFixture(),
            ],
        ]);
        $defaultField = Form::for($page, language: 'en')->fields()->field('variants');
        $defaultProps = $defaultField->toArray();

        $this->assertFalse($defaultProps['serverTechnicalLocked'] ?? false);
        $this->assertSame('stripe', $defaultProps['priceSource']);
        $this->assertSame('EUR', $defaultProps['currency']);
        $defaultValue = $defaultProps['value'];
        $this->assertIsArray($defaultValue);
        $this->assertIsArray($defaultValue['variants'] ?? null);
        $this->assertCount(2, $defaultValue['variants']);

        $page = $page->update([
            'variants' => [
                'groups' => [[
                    'id' => 'colourGroup',
                    'label' => 'Cor',
                    'values' => [
                        ['id' => 'redValue', 'label' => 'Vermelho'],
                        ['id' => 'blueValue', 'label' => 'Azul'],
                    ],
                ]],
            ],
        ], 'pt');
        $translatedField = Form::for($page, language: 'pt')->fields()->field('variants');
        $translatedProps = $translatedField->toArray();

        $this->assertTrue($translatedProps['serverTechnicalLocked']);
        $translatedValue = $translatedProps['value'];
        $this->assertIsArray($translatedValue);
        $this->assertIsArray($translatedValue['groups'] ?? null);
        $this->assertIsArray($translatedValue['groups'][0] ?? null);
        $this->assertSame('Cor', $translatedValue['groups'][0]['label'] ?? null);
        $this->assertIsArray($translatedValue['variants'] ?? null);
        $this->assertCount(2, $translatedValue['variants']);

        $stored = $translatedField->fill([
            ...$translatedValue,
            'variants' => [[
                'id' => 'malicious',
                'choices' => [],
                'enabled' => true,
                'price' => '0.01',
            ]],
        ])->toStoredValue();

        $this->assertIsArray($stored);
        $this->assertSame(['groups'], array_keys($stored));
    }

    /** @return array<string, mixed> */
    private static function canonicalFixture(): array
    {
        return [
            'groups' => [[
                'id' => 'colourGroup',
                'label' => 'Colour',
                'values' => [
                    ['id' => 'redValue', 'label' => 'Red'],
                    ['id' => 'blueValue', 'label' => 'Blue'],
                ],
            ]],
            'variants' => [],
        ];
    }
}
