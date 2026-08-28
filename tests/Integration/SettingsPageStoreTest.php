<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Closure;
use Kirby\Cms\Page;
use Kirby\Content\Field;
use Kirby\Data\Yaml;
use Kirby\Exception\NotFoundException;
use Kirby\Exception\PermissionException;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Configuration\SettingSource;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Kirby\SettingsPage;
use ProgrammatorDev\StripeCheckout\Kirby\SettingsPageStore;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\TestWorkspace;

final class SettingsPageStoreTest extends KirbyTestCase
{
    private const PREFIX = 'programmatordev.stripe-checkout';

    public function testInitializationCreatesAndValidatesTheFixedDraftPage(): void
    {
        $store = new SettingsPageStore($this->kirby);
        $page = $store->initialize();
        $initializedAgain = $store->initialize();

        $this->assertSame(SettingsPage::ID, $page->id());
        $this->assertSame(SettingsPage::TEMPLATE, $page->intendedTemplate()->name());
        $this->assertTrue($page->isDraft());
        $this->assertSame($page->id(), $initializedAgain->id());
        $this->assertSame('Stripe Checkout Settings', $page->title()->value());
        // Kirby creates an empty Field object for blueprint fields. The empty
        // value proves initialization did not persist the effective default.
        $this->assertTrue(in_array(
            $this->fieldValue($page, 'priceSource'),
            [null, ''],
            true,
        ));
        $this->assertSame([
            'owner' => SettingsPage::OWNER,
            'schemaVersion' => SettingsPage::SCHEMA_VERSION,
        ], Yaml::decode($this->fieldValue($page, 'stripeCheckout')));
    }

    public function testApplicationBootInitializesThePageBeforeSettingsAreRead(): void
    {
        $store = new SettingsPageStore($this->kirby);

        $this->assertNotNull($store->page());
        $this->assertNull($store->settings()->priceSource());
        $this->assertNotNull($store->page());
        $this->assertCount(1, $this->kirby->site()->childrenAndDrafts());
    }

    public function testPageValuesRefreshThroughTheSiteApiAndPreserveProjectFields(): void
    {
        $page = (new SettingsPageStore($this->kirby))->initialize();
        $page = $page->update([
            'priceSource' => PriceSource::Stripe->value,
            'projectNote' => 'Keep me',
        ]);

        $settings = $this->settings();
        $setting = $settings->setting('priceSource');

        $this->assertSame(PriceSource::Stripe, $settings->priceSource());
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
                    SettingsPage::ID,
                    SettingsPage::TEMPLATE,
                    [
                        'priceSource' => PriceSource::Kirby->value,
                        'stripeCheckout' => Yaml::encode(self::metadata()),
                        'title' => 'Stripe Checkout Settings',
                    ],
                );
            },
        );
        $this->kirby = $this->environment->app();

        $page = (new SettingsPageStore($this->kirby))->page();
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

        $page = (new SettingsPageStore($this->kirby))->initialize();
        $this->kirby->setCurrentLanguage('pt');
        $page = $page->update([
            'priceSource' => PriceSource::Stripe->value,
            'projectNote' => 'Nota local',
        ]);

        $this->assertSame(
            PriceSource::Stripe->value,
            $this->fieldValue($page, 'priceSource'),
        );
        $this->assertSame(
            'Nota local',
            $this->languageFieldValue($page, 'projectNote', 'pt'),
        );
        $this->assertSame(PriceSource::Stripe, $this->settings()->priceSource());
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

        $page = (new SettingsPageStore($this->kirby))->initialize();
        $page->update(['priceSource' => PriceSource::Stripe->value]);

        $this->assertSame(1, $updateCount);
        $this->assertSame(PriceSource::Stripe, $this->settings()->priceSource());
    }

    public function testModelKeepsProjectFieldsEditableAndRejectsSecrets(): void
    {
        $page = (new SettingsPageStore($this->kirby))->initialize();
        $page = $page->update(['projectReference' => 'store-a']);

        $this->assertSame('store-a', $this->fieldValue($page, 'projectReference'));

        $this->expectException(PermissionException::class);
        $this->expectExceptionMessage('PHP-only');

        $page->update(['secretKey' => 'sk_test_private']);
    }

    public function testModelRejectsStructuralChanges(): void
    {
        $page = (new SettingsPageStore($this->kirby))->initialize();

        $this->expectException(PermissionException::class);
        $this->expectExceptionMessage('structure is protected');

        $page->changeTitle('Changed');
    }

    /** @return iterable<string, array{Closure(SettingsPage): mixed}> */
    public static function structuralMutationProvider(): iterable
    {
        yield 'slug' => [static fn(SettingsPage $page): SettingsPage => $page->changeSlug('changed')];
        yield 'status' => [static fn(SettingsPage $page): SettingsPage => $page->changeStatus('listed')];
        yield 'template' => [static fn(SettingsPage $page): SettingsPage => $page->changeTemplate('default')];
        yield 'delete' => [static fn(SettingsPage $page): bool => $page->delete()];
        yield 'duplicate' => [static fn(SettingsPage $page): SettingsPage => $page->duplicate()];
    }

    /** @param Closure(SettingsPage): mixed $mutation */
    #[DataProvider('structuralMutationProvider')]
    public function testModelRejectsEveryProtectedStructuralMutation(
        Closure $mutation,
    ): void {
        $page = (new SettingsPageStore($this->kirby))->initialize();

        $this->expectException(PermissionException::class);
        $this->expectExceptionMessage('structure is protected');

        $mutation($page);
    }

    public function testModelRejectsFrontendRendering(): void
    {
        $page = (new SettingsPageStore($this->kirby))->initialize();

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
            SettingsPage::TEMPLATE,
            ['schemaVersion' => SettingsPage::SCHEMA_VERSION],
            'persistence.owner_mismatch',
        ];
        yield 'wrong owner' => [
            SettingsPage::TEMPLATE,
            ['owner' => 'project/plugin', 'schemaVersion' => SettingsPage::SCHEMA_VERSION],
            'persistence.owner_mismatch',
        ];
        yield 'unsupported schema' => [
            SettingsPage::TEMPLATE,
            ['owner' => SettingsPage::OWNER, 'schemaVersion' => 2],
            'persistence.schema_unsupported',
        ];
        yield 'unknown metadata' => [
            SettingsPage::TEMPLATE,
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
        $page = $this->kirby->site()->findPageOrDraft(SettingsPage::ID);
        $this->assertNotNull($page);
        $store = new SettingsPageStore($this->kirby);

        try {
            $store->initialize();
            $this->fail('Expected the Settings Page collision to be rejected.');
        } catch (ConfigurationException $exception) {
            $this->assertSame($errorCode, $exception->errorCode());
        }

        $unchanged = $this->kirby->site()->findPageOrDraft(SettingsPage::ID);

        $this->assertNotNull($unchanged);
        $this->assertSame($page->intendedTemplate()->name(), $unchanged->intendedTemplate()->name());
        $this->assertSame('preserved', $this->fieldValue($unchanged, 'marker'));
    }

    public function testMalformedPageValueProducesAStableSafeFailure(): void
    {
        $this->restartWithDraftPage(SettingsPage::TEMPLATE, [
            'priceSource' => 'remote',
            'stripeCheckout' => Yaml::encode(self::metadata()),
        ]);

        try {
            $this->settings();
            $this->fail('Expected malformed Settings Page content to fail.');
        } catch (ConfigurationException $exception) {
            $this->assertSame('persistence.content_invalid', $exception->errorCode());
            $this->assertSame('settings.priceSource', $exception->path());
            $this->assertStringNotContainsString('remote', $exception->getMessage());
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
                    SettingsPage::ID,
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
            'owner' => SettingsPage::OWNER,
            'schemaVersion' => SettingsPage::SCHEMA_VERSION,
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
