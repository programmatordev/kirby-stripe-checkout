<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Closure;
use Kirby\Api\Controller\Changes;
use Kirby\Cms\Page;
use Kirby\Content\Field;
use Kirby\Data\Yaml;
use Kirby\Exception\NotFoundException;
use Kirby\Exception\PermissionException;
use Kirby\Form\Fields;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Configuration\SettingSource;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPage;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPageStore;
use ProgrammatorDev\StripeCheckout\Panel\StripeCheckoutArea;
use ProgrammatorDev\StripeCheckout\Tax\TaxBehavior;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\TestWorkspace;
use Throwable;

final class StripeCheckoutPageStoreTest extends KirbyTestCase
{
    private const PREFIX = 'programmatordev.stripe-checkout';

    public function testInitializationCreatesAndValidatesTheFixedDraftPage(): void
    {
        $store = new StripeCheckoutPageStore($this->kirby);
        $page = $store->initialize();
        $initializedAgain = $store->initialize();

        $this->assertSame(StripeCheckoutPage::ID, $page->id());
        $this->assertSame(StripeCheckoutPage::TEMPLATE, $page->intendedTemplate()->name());
        $this->assertTrue($page->isDraft());
        $this->assertSame($page->id(), $initializedAgain->id());
        $this->assertSame('Stripe Checkout', $page->title()->value());
        $this->assertSame(PriceSource::Kirby->value, $this->fieldValue($page, 'priceSource'));
        $this->assertSame('hosted', $this->fieldValue($page, 'uiMode'));
        $this->assertSame('auto', $this->fieldValue($page, 'billingAddressCollection'));
        $this->assertSame('optional', $this->fieldValue($page, 'individualNameCollection'));
        $this->assertSame('off', $this->fieldValue($page, 'businessNameCollection'));
        $this->assertSame('false', $this->fieldValue($page, 'phoneNumberCollection'));
        $this->assertSame('off', $this->fieldValue($page, 'taxIdCollection'));
        $this->assertSame('false', $this->fieldValue($page, 'termsOfServiceConsent'));
        $this->assertSame('false', $this->fieldValue($page, 'promotionsConsent'));
        $this->assertSame('false', $this->fieldValue($page, 'allowPromotionCodes'));
        $this->assertSame('false', $this->fieldValue($page, 'automaticTax'));
        $this->assertSame('stripe_default', $this->fieldValue($page, 'taxBehavior'));

        // Kirby creates empty Field objects for required settings without a
        // safe deterministic default; their values must remain unconfigured.
        foreach (['currency', 'defaultRequiresShipping'] as $field) {
            $this->assertTrue(in_array(
                $this->fieldValue($page, $field),
                [null, ''],
                true,
            ));
        }

        $this->assertSame([
            'owner' => StripeCheckoutPage::OWNER,
            'schemaVersion' => StripeCheckoutPage::SCHEMA_VERSION,
        ], Yaml::decode($this->fieldValue($page, 'stripeCheckout')));
    }

    public function testApplicationBootInitializesThePageBeforeSettingsAreRead(): void
    {
        $store = new StripeCheckoutPageStore($this->kirby);

        $this->assertNotNull($store->page());
        $this->assertSame(PriceSource::Kirby->value, $store->settings()->priceSource());
        $this->assertNotNull($store->page());
        $this->assertCount(1, $this->kirby->site()->childrenAndDrafts());
    }

    public function testPageValuesRefreshThroughTheSiteApiAndPreserveUnknownExistingFields(): void
    {
        $this->restartWithDraftPage(StripeCheckoutPage::TEMPLATE, [
            'projectNote' => 'Keep me',
            'stripeCheckout' => Yaml::encode(self::metadata()),
        ]);
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        $page = $page->update([
            'priceSource' => PriceSource::Stripe->value,
            'currency' => 'EUR',
            'defaultRequiresShipping' => 'no',
        ]);

        $settings = $this->settings();
        $setting = $settings->setting('priceSource');

        $this->assertSame(PriceSource::Stripe, $settings->priceSource());
        $this->assertSame('EUR', $settings->currency());
        $this->assertFalse($settings->defaultRequiresShipping());
        $this->assertNotNull($setting);
        $this->assertSame(SettingSource::Page, $setting->source());
        $this->assertSame('Keep me', $this->fieldValue($page, 'projectNote'));

        $page = $page->update(['priceSource' => PriceSource::Kirby->value]);
        $refreshed = $this->settings()->setting('priceSource');

        $this->assertNotNull($refreshed);
        $this->assertSame(PriceSource::Kirby, $this->settings()->priceSource());
        $this->assertSame(SettingSource::Page, $refreshed->source());
        $this->assertSame('Keep me', $this->fieldValue($page, 'projectNote'));
    }

    public function testPhpLockWinsAndPreventsChangingTheStoredShadow(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            options: [self::PREFIX => [
                'settings' => ['priceSource' => PriceSource::Stripe->value],
            ]],
            beforeApp: static function (TestWorkspace $workspace): void {
                $workspace->writeDraftPage(
                    StripeCheckoutPage::ID,
                    StripeCheckoutPage::TEMPLATE,
                    [
                        'priceSource' => PriceSource::Kirby->value,
                        'stripeCheckout' => Yaml::encode(self::metadata()),
                        'title' => 'Stripe Checkout',
                    ],
                );
            },
        );
        $this->kirby = $this->environment->app();

        $page = (new StripeCheckoutPageStore($this->kirby))->page();
        $this->assertNotNull($page);
        $setting = $this->settings()->setting('priceSource');

        $this->assertNotNull($setting);
        $this->assertSame(PriceSource::Stripe->value, $setting->value());
        $this->assertSame(SettingSource::Php, $setting->source());
        $this->assertTrue($setting->isLocked());
        $this->assertTrue($setting->hasShadowedValue());
        $this->assertSame(PriceSource::Kirby->value, $setting->shadowedValue());

        $page = $page->update(['priceSource' => PriceSource::Kirby->value]);

        $this->expectException(PermissionException::class);
        $this->expectExceptionMessage('locked by PHP configuration');
        $page->update(['priceSource' => PriceSource::Stripe->value]);
    }

    public function testTechnicalSettingsAreWrittenToTheDefaultLanguage(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            languages: [
                [
                    'code' => 'en',
                    'default' => true,
                    'locale' => 'en_US',
                    'name' => 'English',
                ],
                [
                    'code' => 'pt',
                    'locale' => 'pt_PT',
                    'name' => 'Português',
                ],
            ],
        );
        $this->kirby = $this->environment->app();

        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        $this->kirby->setCurrentLanguage('pt');
        $page = $page->update([
            'priceSource' => PriceSource::Stripe->value,
            'currency' => 'EUR',
            'defaultRequiresShipping' => 'yes',
            'billingAddressCollection' => 'required',
            'individualNameCollection' => 'off',
            'phoneNumberCollection' => 'true',
            'allowPromotionCodes' => 'true',
            'automaticTax' => 'true',
            'taxBehavior' => 'inclusive',
        ]);

        $this->assertSame(
            PriceSource::Stripe->value,
            $this->fieldValue($page, 'priceSource'),
        );
        $this->assertSame('EUR', $this->fieldValue($page, 'currency'));
        $this->assertSame('yes', $this->fieldValue($page, 'defaultRequiresShipping'));
        $this->assertSame('required', $this->fieldValue($page, 'billingAddressCollection'));
        $this->assertSame('off', $this->fieldValue($page, 'individualNameCollection'));
        $this->assertSame('true', $this->fieldValue($page, 'phoneNumberCollection'));
        $this->assertSame('true', $this->fieldValue($page, 'allowPromotionCodes'));
        $this->assertSame('true', $this->fieldValue($page, 'automaticTax'));
        $this->assertSame('inclusive', $this->fieldValue($page, 'taxBehavior'));
        $this->assertFalse($page->translation('pt')->exists());
        $this->assertSame(PriceSource::Stripe, $this->settings()->priceSource());
        $this->assertTrue($this->settings()->phoneNumberCollection());
        $this->assertTrue($this->settings()->allowPromotionCodes());
        $this->assertTrue($this->settings()->automaticTax());
        $this->assertSame(TaxBehavior::Inclusive, $this->settings()->taxBehavior());
    }

    public function testCheckoutDestinationsRemainTranslated(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(languages: [
            ['code' => 'en', 'default' => true, 'locale' => 'en_GB', 'name' => 'English'],
            ['code' => 'pt', 'locale' => 'pt_PT', 'name' => 'Português'],
        ]);
        $this->kirby = $this->environment->app();
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        $this->kirby->setCurrentLanguage('pt');
        $page = $page->update([
            'successDestination' => '/pt/obrigado',
            'uiMode' => 'embedded',
        ]);

        $this->assertSame('embedded', $this->fieldValue($page, 'uiMode'));
        $this->assertSame(
            '/pt/obrigado',
            $this->languageFieldValue($page, 'successDestination', 'pt'),
        );
        $this->assertTrue($page->translation('pt')->exists());
        $this->assertSame('/pt/obrigado', (new StripeCheckoutPageStore($this->kirby))->settings()->successDestination());

        $this->kirby->setCurrentLanguage('en');

        $this->assertNull((new StripeCheckoutPageStore($this->kirby))->settings()->successDestination());
    }

    public function testOptionPresetsRemainOwnedByTheDefaultLanguage(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(languages: [
            ['code' => 'en', 'default' => true, 'locale' => 'en_US', 'name' => 'English'],
            ['code' => 'pt', 'locale' => 'pt_PT', 'name' => 'Português'],
        ]);
        $this->kirby = $this->environment->app();
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        $this->kirby->setCurrentLanguage('pt');
        $page = $page->update(['optionPresets' => [[
            'label' => 'T-shirt',
            'options' => [[
                'label' => 'Size',
                'values' => ['Small', 'Large'],
            ]],
        ]]]);

        $this->assertNotSame('', $this->fieldValue($page, 'optionPresets'));
        $this->assertFalse($page->translation('pt')->exists());
    }

    public function testEveryPhpSettingLockPreservesItsStoredPageShadow(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            options: [self::PREFIX => [
                'settings' => [
                    'currency' => 'USD',
                    'defaultRequiresShipping' => false,
                ],
            ]],
            beforeApp: static function (TestWorkspace $workspace): void {
                $workspace->writeDraftPage(
                    StripeCheckoutPage::ID,
                    StripeCheckoutPage::TEMPLATE,
                    [
                        'currency' => 'EUR',
                        'defaultRequiresShipping' => 'yes',
                        'stripeCheckout' => Yaml::encode(self::metadata()),
                        'title' => 'Stripe Checkout',
                    ],
                );
            },
        );
        $this->kirby = $this->environment->app();
        $page = (new StripeCheckoutPageStore($this->kirby))->page();

        $this->assertNotNull($page);
        $this->assertSame('USD', $this->settings()->currency());
        $this->assertFalse($this->settings()->defaultRequiresShipping());

        $error = null;

        try {
            $page->update(['currency' => 'GBP']);
        } catch (Throwable $error) {
        }

        $this->assertInstanceOf(PermissionException::class, $error);
        $this->assertStringContainsString('locked by PHP configuration', $error->getMessage());
        $this->assertSame('EUR', $this->fieldValue($page, 'currency'));
        $this->assertSame('yes', $this->fieldValue($page, 'defaultRequiresShipping'));
    }

    #[DataProvider('taxModes')]
    public function testTaxPolicySurvivesNativeSavesAcrossTaxAndPriceSourceModes(
        bool $automaticTax,
        PriceSource $priceSource,
    ): void {
        $store = new StripeCheckoutPageStore($this->kirby);
        $page = $store->initialize();
        Changes::publish($page, [
            'currency' => 'EUR',
            'defaultRequiresShipping' => 'no',
            'automaticTax' => true,
            'taxBehavior' => 'inclusive',
        ]);

        $this->assertTrue($this->settings()->automaticTax());
        $this->assertSame(TaxBehavior::Inclusive, $this->settings()->taxBehavior());

        $page = $store->page();
        $this->assertNotNull($page);
        Changes::publish($page, [
            'automaticTax' => $automaticTax,
            'priceSource' => $priceSource->value,
        ]);

        $this->assertSame($automaticTax, $this->settings()->automaticTax());
        $this->assertSame($priceSource, $this->settings()->priceSource());
        $this->assertSame(TaxBehavior::Inclusive, $this->settings()->taxBehavior());
        $page = $store->page();
        $this->assertNotNull($page);
        $this->assertSame('inclusive', $this->fieldValue($page, 'taxBehavior'));
        $this->assertSame(
            $automaticTax && $priceSource === PriceSource::Kirby,
            Fields::for($page)->fill($page->content()->toArray())->field('taxBehavior')->isActive(),
        );
    }

    /** @return iterable<string, array{bool, PriceSource}> */
    public static function taxModes(): iterable
    {
        yield 'tax enabled with Kirby prices' => [true, PriceSource::Kirby];
        yield 'tax disabled with Kirby prices' => [false, PriceSource::Kirby];
        yield 'tax enabled with Stripe Prices' => [true, PriceSource::Stripe];
        yield 'tax disabled with Stripe Prices' => [false, PriceSource::Stripe];
    }

    public function testPhpLockedTaxSettingsShowEffectiveValuesWithoutOverwritingSavedShadows(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            options: [self::PREFIX => [
                'settings' => [
                    'automaticTax' => false,
                    'taxBehavior' => 'exclusive',
                ],
            ]],
            beforeApp: static function (TestWorkspace $workspace): void {
                $workspace->writeDraftPage(StripeCheckoutPage::ID, StripeCheckoutPage::TEMPLATE, [
                    'title' => 'Stripe Checkout',
                    'priceSource' => 'kirby',
                    'defaultRequiresShipping' => 'no',
                    'uuid' => 'taxsettings',
                    'automaticTax' => 'true',
                    'taxBehavior' => 'inclusive',
                    'stripeCheckout' => Yaml::encode(self::metadata()),
                ]);
            },
        );
        $this->kirby = $this->environment->app();
        $store = new StripeCheckoutPageStore($this->kirby);
        $page = $store->initialize();
        /** @var array{props: array{versions: array<string, \stdClass>}} $view */
        $view = StripeCheckoutArea::view($this->kirby);
        $input = (array) $view['props']['versions']['changes'];

        $this->assertFalse($input['automatictax']);
        $this->assertSame('exclusive', $input['taxbehavior']);
        // Native forms include disabled effective values; publishing them must
        // keep the merchant's stored shadows rather than copy PHP into content.
        Changes::publish($page, [...$input, 'currency' => 'EUR']);

        $page = $store->page();
        $this->assertNotNull($page);

        $this->assertSame('true', $this->fieldValue($page, 'automaticTax'));
        $this->assertSame('inclusive', $this->fieldValue($page, 'taxBehavior'));
        $this->assertFalse($this->settings()->automaticTax());
        $this->assertSame(TaxBehavior::Exclusive, $this->settings()->taxBehavior());

        $this->expectException(PermissionException::class);
        $page->update(['taxBehavior' => 'exclusive']);
    }

    public function testUpdatesUseKirbyHooksAndRefreshThroughANewOperation(): void
    {
        $updateCount = 0;

        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            hooks: [
                'page.update:after' => function () use (&$updateCount): void {
                    $updateCount++;
                },
            ],
        );
        $this->kirby = $this->environment->app();

        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        $page->update(['priceSource' => PriceSource::Stripe->value]);

        $this->assertSame(1, $updateCount);
        $this->assertSame(PriceSource::Stripe, $this->settings()->priceSource());
    }

    public function testPanelChangesCanBePublished(): void
    {
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();

        Changes::save($page, [
            'priceSource' => PriceSource::Kirby->value,
            'currency' => 'EUR',
            'defaultRequiresShipping' => 'no',
        ]);
        Changes::publish($page, []);

        $page = (new StripeCheckoutPageStore($this->kirby))->page();

        $this->assertNotNull($page);
        $this->assertSame('EUR', $this->fieldValue($page, 'currency'));
        $this->assertSame('no', $this->fieldValue($page, 'defaultRequiresShipping'));
        $this->assertFalse($page->version('changes')->exists('current'));
    }

    public function testCompletePanelPayloadPreservesAnUnchangedLegacyField(): void
    {
        $this->restartWithDraftPage(StripeCheckoutPage::TEMPLATE, [
            'currency' => 'EUR',
            'defaultRequiresShipping' => 'no',
            'priceSource' => PriceSource::Kirby->value,
            'stripeCheckout' => Yaml::encode(self::metadata()),
            'title' => 'Stripe Checkout',
            'variantPresets' => '',
        ]);
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();

        $page = $page->update([
            'currency' => 'EUR',
            'defaultRequiresShipping' => 'no',
            'priceSource' => PriceSource::Stripe->value,
            'stripeCheckout' => $this->fieldValue($page, 'stripeCheckout'),
            'title' => $this->fieldValue($page, 'title'),
            'uuid' => $this->fieldValue($page, 'uuid'),
            'variantPresets' => '',
        ]);

        $this->assertSame(PriceSource::Stripe->value, $this->fieldValue($page, 'priceSource'));
        $this->assertSame('', $this->fieldValue($page, 'variantPresets'));
    }

    public function testModelRejectsUnknownFields(): void
    {
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();

        $this->expectException(PermissionException::class);
        $this->expectExceptionMessage('Only plugin-owned');

        $page->update(['projectReference' => 'store-a']);
    }

    public function testModelRejectsChangesToAnExistingUnknownField(): void
    {
        $this->restartWithDraftPage(StripeCheckoutPage::TEMPLATE, [
            'projectReference' => 'store-a',
            'stripeCheckout' => Yaml::encode(self::metadata()),
            'title' => 'Stripe Checkout',
        ]);
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();

        $this->expectException(PermissionException::class);
        $this->expectExceptionMessage('Only plugin-owned');

        $page->update(['projectReference' => 'store-b']);
    }

    public function testModelRejectsSecrets(): void
    {
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();

        $this->expectException(PermissionException::class);
        $this->expectExceptionMessage('PHP-only');

        $page->update(['secretKey' => 'sk_test_private']);
    }

    public function testModelRejectsStructuralChanges(): void
    {
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();

        $this->expectException(PermissionException::class);
        $this->expectExceptionMessage('structure is protected');

        $page->changeTitle('Changed');
    }

    /** @return iterable<string, array{Closure(StripeCheckoutPage): mixed}> */
    public static function structuralMutationProvider(): iterable
    {
        yield 'slug' => [static fn(StripeCheckoutPage $page): StripeCheckoutPage => $page->changeSlug('changed')];
        yield 'status' => [static fn(StripeCheckoutPage $page): StripeCheckoutPage => $page->changeStatus('listed')];
        yield 'template' => [static fn(StripeCheckoutPage $page): StripeCheckoutPage => $page->changeTemplate('default')];
        yield 'delete' => [static fn(StripeCheckoutPage $page): bool => $page->delete()];
        yield 'duplicate' => [static fn(StripeCheckoutPage $page): StripeCheckoutPage => $page->duplicate()];
    }

    /** @param Closure(StripeCheckoutPage): mixed $mutation */
    #[DataProvider('structuralMutationProvider')]
    public function testModelRejectsEveryProtectedStructuralMutation(
        Closure $mutation,
    ): void {
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();

        $this->expectException(PermissionException::class);
        $this->expectExceptionMessage('structure is protected');

        $mutation($page);
    }

    public function testModelRejectsFrontendRendering(): void
    {
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();

        $this->expectException(NotFoundException::class);
        $page->render();
    }

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function invalidPageProvider(): iterable
    {
        yield 'wrong template' => [
            'default',
            self::metadata(),
            'persistence.model_mismatch',
        ];
        yield 'missing owner' => [
            StripeCheckoutPage::TEMPLATE,
            ['schemaVersion' => StripeCheckoutPage::SCHEMA_VERSION],
            'persistence.owner_mismatch',
        ];
        yield 'wrong owner' => [
            StripeCheckoutPage::TEMPLATE,
            ['owner' => 'project/plugin', 'schemaVersion' => StripeCheckoutPage::SCHEMA_VERSION],
            'persistence.owner_mismatch',
        ];
        yield 'unsupported schema' => [
            StripeCheckoutPage::TEMPLATE,
            ['owner' => StripeCheckoutPage::OWNER, 'schemaVersion' => 2],
            'persistence.schema_unsupported',
        ];
        yield 'unknown metadata' => [
            StripeCheckoutPage::TEMPLATE,
            [...self::metadata(), 'kind' => 'settings'],
            'persistence.content_invalid',
        ];
    }

    /** @param array<string, mixed> $metadata */
    #[DataProvider('invalidPageProvider')]
    public function testExistingCollisionsAreRejectedWithoutModification(
        string $template,
        array $metadata,
        string $errorCode,
    ): void {
        $this->restartWithDraftPage($template, [
            'marker' => 'preserved',
            'stripeCheckout' => Yaml::encode($metadata),
        ]);
        $page = $this->kirby->site()->findPageOrDraft(StripeCheckoutPage::ID);
        $this->assertNotNull($page);
        $store = new StripeCheckoutPageStore($this->kirby);

        try {
            $store->initialize();
            $this->fail('Expected the Stripe Checkout Page collision to be rejected.');
        } catch (ConfigurationException $exception) {
            $this->assertSame($errorCode, $exception->errorCode());
        }

        $unchanged = $this->kirby->site()->findPageOrDraft(StripeCheckoutPage::ID);

        $this->assertNotNull($unchanged);
        $this->assertSame($page->intendedTemplate()->name(), $unchanged->intendedTemplate()->name());
        $this->assertSame('preserved', $this->fieldValue($unchanged, 'marker'));
    }

    public function testMalformedPageValueProducesAStableSafeFailure(): void
    {
        $this->restartWithDraftPage(StripeCheckoutPage::TEMPLATE, [
            'priceSource' => 'remote',
            'stripeCheckout' => Yaml::encode(self::metadata()),
        ]);

        try {
            $this->settings();
            $this->fail('Expected malformed Stripe Checkout Page content to fail.');
        } catch (ConfigurationException $exception) {
            $this->assertSame('persistence.content_invalid', $exception->errorCode());
            $this->assertSame('settings.priceSource', $exception->path());
            $this->assertStringNotContainsString('remote', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidCommerceSettingProvider(): iterable
    {
        yield 'unsupported currency' => ['currency', 'XXX'];
        yield 'lowercase currency' => ['currency', 'eur'];
        yield 'invalid shipping default' => ['defaultRequiresShipping', 'sometimes'];
        yield 'invalid UI mode' => ['uiMode', 'inline'];
        yield 'invalid billing collection' => ['billingAddressCollection', 'optional'];
        yield 'invalid individual name collection' => ['individualNameCollection', 'auto'];
        yield 'invalid business name collection' => ['businessNameCollection', 'auto'];
        yield 'invalid phone collection' => ['phoneNumberCollection', 'yes'];
        yield 'invalid tax ID collection' => ['taxIdCollection', 'required'];
        yield 'invalid terms consent' => ['termsOfServiceConsent', 'yes'];
        yield 'invalid promotions consent' => ['promotionsConsent', 'yes'];
        yield 'invalid promotion codes' => ['allowPromotionCodes', 'yes'];
        yield 'invalid automatic tax' => ['automaticTax', 'yes'];
        yield 'invalid tax behavior' => ['taxBehavior', 'automatic'];
    }

    #[DataProvider('invalidCommerceSettingProvider')]
    public function testMalformedCommercePageValuesProduceSafeFailures(
        string $field,
        string $value,
    ): void {
        $this->restartWithDraftPage(StripeCheckoutPage::TEMPLATE, [
            $field => $value,
            'stripeCheckout' => Yaml::encode(self::metadata()),
        ]);

        try {
            $this->settings();
            $this->fail('Expected malformed Stripe Checkout Page content to fail.');
        } catch (ConfigurationException $exception) {
            $this->assertSame('persistence.content_invalid', $exception->errorCode());
            $this->assertSame('settings.' . $field, $exception->path());
            $this->assertStringNotContainsString($value, $exception->getMessage());
        }
    }

    private function settings(): \ProgrammatorDev\StripeCheckout\Configuration\Settings
    {
        /** @phpstan-ignore-next-line method.notFound */
        return $this->kirby->site()->stripeCheckout()->settings();
    }

    /** @param array<string, mixed> $content */
    private function restartWithDraftPage(string $template, array $content): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            beforeApp: static function (TestWorkspace $workspace) use ($content, $template): void {
                $workspace->writeDraftPage(
                    StripeCheckoutPage::ID,
                    $template,
                    [...$content, 'title' => 'Collision marker'],
                );
            },
        );
        $this->kirby = $this->environment->app();
    }

    /** @return array{owner: string, schemaVersion: int} */
    private static function metadata(): array
    {
        return [
            'owner' => StripeCheckoutPage::OWNER,
            'schemaVersion' => StripeCheckoutPage::SCHEMA_VERSION,
        ];
    }

    private function fieldValue(Page $page, string $fieldName): mixed
    {
        return $this->languageFieldValue(
            $page,
            $fieldName,
            $this->kirby->defaultLanguage()?->code(),
        );
    }

    private function languageFieldValue(
        Page $page,
        string $fieldName,
        ?string $languageCode,
    ): mixed {
        $field = $page->content($languageCode)->get($fieldName);

        return $field instanceof Field ? $field->value() : null;
    }
}
