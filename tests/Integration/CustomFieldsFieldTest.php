<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Kirby\Content\Field;
use Kirby\Data\Yaml;
use Kirby\Exception\PermissionException;
use Kirby\Form\Form;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPageStore;
use ProgrammatorDev\StripeCheckout\Panel\StripeCheckoutArea;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;

final class CustomFieldsFieldTest extends KirbyTestCase
{
    public function testDefaultLanguageOwnsCanonicalRowsAndTranslationsStoreOnlyLabels(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(languages: [
            ['code' => 'en', 'default' => true, 'locale' => 'en_US', 'name' => 'English'],
            ['code' => 'pt', 'locale' => 'pt_PT', 'name' => 'Português'],
        ]);
        $this->kirby = $this->environment->app();
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        $page = $page->update(['customFields' => self::canonicalFixture()], 'en');
        $defaultField = Form::for($page, language: 'en')->fields()->field('customFields');
        $defaultProps = $defaultField->toArray();
        $defaultValue = $defaultProps['value'] ?? null;

        $this->assertFalse($defaultProps['serverTechnicalLocked'] ?? true);
        $this->assertIsArray($defaultValue);
        $this->assertCount(2, $defaultValue);

        $translatedField = Form::for($page, language: 'pt')->fields()->field('customFields');
        $translatedProps = $translatedField->toArray();
        $translatedValue = $translatedProps['value'] ?? null;
        $this->assertTrue($translatedProps['serverTechnicalLocked'] ?? false);
        $this->assertIsArray($translatedValue);
        $firstTranslated = $translatedValue[0] ?? null;
        $secondTranslated = $translatedValue[1] ?? null;
        $this->assertIsArray($firstTranslated);
        $this->assertIsArray($secondTranslated);
        $secondOptions = $secondTranslated['options'] ?? null;
        $this->assertIsArray($secondOptions);
        $firstOption = $secondOptions[0] ?? null;
        $this->assertIsArray($firstOption);
        $this->assertSame('Reference', $firstTranslated['label'] ?? null);

        $stored = $translatedField->fill([
            [
                ...$firstTranslated,
                'key' => 'malicious',
                'label' => 'Referência',
                'type' => 'dropdown',
            ],
            [
                ...$secondTranslated,
                'label' => 'Entrega',
                'options' => [[
                    ...$firstOption,
                    'value' => 'changed',
                    'label' => 'Manhã',
                ]],
            ],
            [
                'id' => 'orphan0000000001',
                'label' => 'Ignored',
            ],
        ])->toStoredValue();
        $this->assertIsArray($stored);
        $firstStored = $stored[0] ?? null;
        $secondStored = $stored[1] ?? null;
        $this->assertIsArray($firstStored);
        $this->assertIsArray($secondStored);
        $storedOptions = $secondStored['options'] ?? null;
        $this->assertIsArray($storedOptions);
        $firstStoredOption = $storedOptions[0] ?? null;
        $secondStoredOption = $storedOptions[1] ?? null;
        $this->assertIsArray($firstStoredOption);
        $this->assertIsArray($secondStoredOption);

        $this->assertSame(['id', 'label', 'options'], array_keys($firstStored));
        $this->assertSame('Referência', $firstStored['label'] ?? null);
        $this->assertSame('Manhã', $firstStoredOption['label'] ?? null);
        $this->assertSame('', $secondStoredOption['label'] ?? null);
        $this->assertCount(2, $stored);
    }

    public function testPageSettingsResolveLocalizedLabelsWithFallbacks(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(languages: [
            ['code' => 'en', 'default' => true, 'locale' => 'en_US', 'name' => 'English'],
            ['code' => 'pt', 'locale' => 'pt_PT', 'name' => 'Português'],
        ]);
        $this->kirby = $this->environment->app();
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        $page = $page->update(['customFields' => self::canonicalFixture()], 'en');
        $page = $page->update(['customFields' => [
            [
                'id' => 'field00000000001',
                'label' => 'Referência',
            ],
            [
                'id' => 'field00000000002',
                'label' => '',
                'options' => [[
                    'id' => 'option0000000001',
                    'label' => 'Manhã',
                ]],
            ],
        ]], 'pt');
        $storedTranslationField = $page->content('pt')->get('customFields');
        $this->assertInstanceOf(Field::class, $storedTranslationField);
        $storedTranslation = Yaml::decode($storedTranslationField->value());
        $storedFirst = $storedTranslation[0] ?? null;
        $this->assertIsArray($storedFirst);
        $this->assertSame(['id', 'label', 'options'], array_keys($storedFirst));
        $this->assertArrayNotHasKey('key', $storedFirst);
        $this->assertArrayNotHasKey('type', $storedFirst);
        $this->kirby->setCurrentLanguage('pt');
        $fields = (new StripeCheckoutPageStore($this->kirby))->settings()->customFields();

        $this->assertIsArray($fields);
        $firstField = $fields[0] ?? null;
        $secondField = $fields[1] ?? null;
        $this->assertIsArray($firstField);
        $this->assertIsArray($secondField);
        $this->assertSame('Referência', $firstField['label'] ?? null);
        $this->assertSame('Delivery', $secondField['label'] ?? null);
        $options = $secondField['options'] ?? null;
        $this->assertIsArray($options);
        $firstOption = $options[0] ?? null;
        $secondOption = $options[1] ?? null;
        $this->assertIsArray($firstOption);
        $this->assertIsArray($secondOption);
        $this->assertSame('Manhã', $firstOption['label'] ?? null);
        $this->assertSame('Afternoon', $secondOption['label'] ?? null);
        $publicFields = (new RuntimeFactory($this->kirby))->settings()->customFields();
        $this->assertSame('Referência', $publicFields[0]->label());
        $this->assertSame('Manhã', $publicFields[1]->options()[0]->label());
    }

    public function testPhpConfigurationLocksTheCompleteCustomFieldEditor(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout' => [
                'settings' => [
                    'customFields' => [[
                        'key' => 'reference',
                        'label' => 'Reference',
                        'type' => 'text',
                    ]],
                ],
            ],
        ]);
        $this->kirby = $this->environment->app();
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        $field = $page->blueprint()->field('customFields');
        $this->assertIsArray($field);
        $this->assertTrue($field['disabled'] ?? false);

        $this->expectException(PermissionException::class);
        $this->expectExceptionMessage('locked by PHP configuration');
        $page->update(['customFields' => self::canonicalFixture()]);
    }

    public function testPanelAreaProjectsStoredPageDefinitionsWithTheirStableIds(): void
    {
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        $page->update(['customFields' => self::canonicalFixture()]);
        /** @var array{component: string, props: array{versions: array{latest: \stdClass}}} $view */
        $view = StripeCheckoutArea::view($this->kirby);
        $fields = $view['props']['versions']['latest']->customfields ?? null;

        $this->assertSame('k-page-view', $view['component']);
        $this->assertIsArray($fields);
        $firstField = $fields[0] ?? null;
        $this->assertIsArray($firstField);
        $this->assertSame('field00000000001', $firstField['id'] ?? null);
        $this->assertArrayNotHasKey('labels', $firstField);
    }

    public function testPanelAreaProjectsLocalizedPhpDefinitionsWithoutConfigurationMetadata(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout' => [
                'settings' => [
                    'customFields' => [[
                        'key' => 'reference',
                        'label' => 'Reference',
                        'labels' => ['pt' => 'Referência'],
                        'type' => 'text',
                    ]],
                ],
            ],
        ], languages: [
            ['code' => 'en', 'default' => true, 'locale' => 'en_US', 'name' => 'English'],
            ['code' => 'pt', 'locale' => 'pt_PT', 'name' => 'Português'],
        ]);
        $this->kirby = $this->environment->app();
        $this->kirby->setCurrentLanguage('pt');
        /** @var array{component: string, props: array{versions: array{latest: \stdClass}}} $view */
        $view = StripeCheckoutArea::view($this->kirby);
        $fields = $view['props']['versions']['latest']->customfields ?? null;

        $this->assertSame('k-page-view', $view['component']);
        $this->assertIsArray($fields);
        $firstField = $fields[0] ?? null;
        $this->assertIsArray($firstField);
        $this->assertSame('Referência', $firstField['label'] ?? null);
        $id = $firstField['id'] ?? null;
        $this->assertIsString($id);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{16}$/', $id);
        $this->assertArrayNotHasKey('labels', $firstField);
    }

    /** @return list<array<string, mixed>> */
    private static function canonicalFixture(): array
    {
        return [
            [
                'id' => 'field00000000001',
                'key' => 'reference',
                'label' => 'Reference',
                'type' => 'text',
                'required' => false,
                'minimumLength' => 2,
                'maximumLength' => 20,
                'defaultValue' => null,
                'options' => [],
            ],
            [
                'id' => 'field00000000002',
                'key' => 'delivery',
                'label' => 'Delivery',
                'type' => 'dropdown',
                'required' => true,
                'minimumLength' => null,
                'maximumLength' => null,
                'defaultValue' => 'morning',
                'options' => [
                    ['id' => 'option0000000001', 'value' => 'morning', 'label' => 'Morning'],
                    ['id' => 'option0000000002', 'value' => 'afternoon', 'label' => 'Afternoon'],
                ],
            ],
        ];
    }
}
