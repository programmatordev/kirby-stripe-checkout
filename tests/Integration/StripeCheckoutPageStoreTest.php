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
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Configuration\SettingSource;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPage;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPageStore;
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

        $page->update(['priceSource' => PriceSource::Kirby->value]);

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
        ]);

        $this->assertSame(
            PriceSource::Stripe->value,
            $this->fieldValue($page, 'priceSource'),
        );
        $this->assertSame('EUR', $this->fieldValue($page, 'currency'));
        $this->assertSame('yes', $this->fieldValue($page, 'defaultRequiresShipping'));
        $this->assertFalse($page->translation('pt')->exists());
        $this->assertSame(PriceSource::Stripe, $this->settings()->priceSource());
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

    public function testModelRejectsUnknownFields(): void
    {
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();

        $this->expectException(PermissionException::class);
        $this->expectExceptionMessage('Only plugin-owned');

        $page->update(['projectReference' => 'store-a']);
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
