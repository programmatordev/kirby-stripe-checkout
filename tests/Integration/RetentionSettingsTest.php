<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Kirby\Api\Controller\Changes;
use Kirby\Content\Version;
use Kirby\Content\VersionId;
use Kirby\Data\Yaml;
use Kirby\Exception\PermissionException;
use Kirby\Filesystem\F;
use Kirby\Form\Fields;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Configuration\SettingSource;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPage;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPageStore;
use ProgrammatorDev\StripeCheckout\Panel\StripeCheckoutArea;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\TestWorkspace;

final class RetentionSettingsTest extends KirbyTestCase
{
    #[DataProvider('lockedSaveModes')]
    public function testNativeSavesPreserveMissingAndStoredLockedValues(bool $fullPayload, bool $storedToggle, bool $multilang): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout.settings' => [
                'priceSource' => 'stripe',
                'currency' => 'USD',
                'defaultRequiresShipping' => false,
                'cleanupUnpaidOrders' => false,
                'creationFailureRetentionDays' => 21,
            ],
        ], languages: $multilang ? [
            [
                'code' => 'en',
                'name' => 'English',
                'default' => true,
            ],
            [
                'code' => 'pt',
                'name' => 'Português',
            ],
        ] : null, beforeApp: static fn(TestWorkspace $workspace) => self::seedExistingSettings($workspace, [
            'currency' => 'EUR',
            'defaultRequiresShipping' => 'yes',
            'cleanupCreationFailures' => 'true',
            ...($storedToggle ? ['cleanupUnpaidOrders' => 'true'] : []),
        ], $multilang));
        $this->kirby = $this->environment->app();
        $store = new StripeCheckoutPageStore($this->kirby);
        $page = $store->initialize();
        $input = $fullPayload ? $this->viewVersions()['changes'] : [];
        Changes::publish($page, [...$input, 'cleanupcreationfailures' => false]);
        $settings = $store->settings();
        $this->assertFalse($settings->value('cleanupCreationFailures'));
        $this->assertSame($storedToggle ? true : null, $settings->value('cleanupUnpaidOrders'));
        $this->assertNull($settings->value('creationFailureRetentionDays'));
        $this->assertSame('kirby', $settings->priceSource());
        $this->assertSame('EUR', $settings->currency());
        $this->assertTrue($settings->defaultRequiresShipping());
        $this->assertFalse($store->page()?->version('changes')->exists());

        if ($multilang) {
            $this->kirby->setCurrentLanguage('pt');
            $page = $store->page();
            Changes::publish($page, ['cleanupunpaidorders' => true]);
            $this->assertSame($storedToggle ? true : null, $store->settings()->value('cleanupUnpaidOrders'));
            $this->assertArrayNotHasKey('cleanupunpaidorders', $store->page()->version('latest')->read('pt') ?? []);
        }
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function lockedSaveModes(): iterable
    {
        yield 'partial, missing toggle' => [false, false, false];
        yield 'partial, stored toggle' => [false, true, false];
        yield 'complete, missing toggle' => [true, false, false];
        yield 'complete, stored toggle' => [true, true, false];
        yield 'multilang partial, missing toggle' => [false, false, true];
        yield 'multilang partial, stored toggle' => [false, true, true];
        yield 'multilang complete, missing toggle' => [true, false, true];
        yield 'multilang complete, stored toggle' => [true, true, true];
    }

    public function testPhpLocksReplaceStalePendingValuesWithoutLosingOtherEdits(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout.settings.cleanupUnpaidOrders' => false,
        ]);
        $this->kirby = $this->environment->app();
        $store = new StripeCheckoutPageStore($this->kirby);
        $page = $store->initialize();
        $latest = $page->version('latest')->read('default') ?? [];
        // Seed the raw native version as if an edit predated the PHP lock.
        $rawChanges = new Version($page, VersionId::from('changes'));
        $rawChanges->save([
            ...$latest,
            'cleanupunpaidorders' => 'false',
            'currency' => 'EUR',
            'defaultrequiresshipping' => 'no',
            'unpaidorderretentiondays' => '45',
        ]);
        Changes::save($page, []);
        $savedChanges = $rawChanges->read('default');
        $this->assertNotNull($savedChanges);
        $this->assertSame('true', $savedChanges['cleanupunpaidorders'] ?? null);
        $this->assertSame('45', $savedChanges['unpaidorderretentiondays'] ?? null);
        Changes::publish($page, []);
        $this->assertTrue($store->settings()->value('cleanupUnpaidOrders'));
        $this->assertSame(45, $store->settings()->value('unpaidOrderRetentionDays'));
    }

    #[DataProvider('languageModes')]
    public function testExistingSettingsShowMissingDefaultsWithoutReinstallationOrWrites(bool $multilang): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            languages: $multilang ? [
                [
                    'code' => 'en',
                    'name' => 'English',
                    'default' => true,
                ],
                [
                    'code' => 'pt',
                    'name' => 'Português',
                ],
            ] : null,
            beforeApp: static fn(TestWorkspace $workspace) => self::seedExistingSettings($workspace, [], $multilang),
        );
        $this->kirby = $this->environment->app();

        if ($multilang) {
            $this->kirby->setCurrentLanguage('pt');
        }

        $store = new StripeCheckoutPageStore($this->kirby);
        $page = $store->initialize();
        $before = $page->version('latest')->read('default');
        $versions = $this->viewVersions();
        $this->assertSame($versions['latest'], $versions['changes']);
        $this->assertSame(7.0, $versions['latest']['creationfailureretentiondays']);
        $this->assertSame(30.0, $versions['latest']['unpaidorderretentiondays']);
        $this->assertTrue($versions['latest']['cleanupcreationfailures']);
        $this->assertTrue($versions['latest']['cleanupunpaidorders']);
        $this->assertSame('hosted', $versions['latest']['uimode']);
        $this->assertSame($before, $page->version('latest')->read('default'));
        $this->assertFalse($page->version('changes')->exists('current'));
        $settings = (new ConfigurationResolver())->resolve([], $store->settings())->configurationOrFail()->settings();
        $this->assertSame(SettingSource::InternalDefault, $settings->setting('unpaidOrderRetentionDays')?->source());

        if ($multilang) {
            // Store-wide settings use Kirby's normal default-language editing.
            $this->kirby->setCurrentLanguage('en');
        }

        Changes::publish($page, [
            ...$versions['changes'],
            'currency' => 'EUR',
            'defaultrequiresshipping' => 'no',
        ]);
        $this->assertSame(7, $store->settings()->value('creationFailureRetentionDays'));
        $this->assertSame(30, $store->settings()->value('unpaidOrderRetentionDays'));

        if ($multilang) {
            $this->assertArrayNotHasKey('unpaidorderretentiondays', $page->version('latest')->read('pt') ?? []);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function languageModes(): iterable
    {
        yield 'single language' => [false];
        yield 'secondary language' => [true];
    }

    public function testSavedAndPendingValuesAreNotReplacedByDefaults(): void
    {
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize()->update([
            'unpaidOrderRetentionDays' => 90,
            'cleanupUnpaidOrders' => false,
        ]);
        $latest = $page->version('latest')->read('default') ?? [];
        $page->version('changes')->save([
            ...$latest,
            'creationfailureretentiondays' => '',
            'unpaidorderretentiondays' => '45',
            'cleanupcreationfailures' => 'false',
        ], 'default');
        $before = $page->version('changes')->read('default');
        $versions = $this->viewVersions();
        $this->assertSame(90.0, $versions['latest']['unpaidorderretentiondays']);
        $this->assertFalse($versions['latest']['cleanupunpaidorders']);
        $this->assertSame('', $versions['changes']['creationfailureretentiondays']);
        $this->assertSame(45.0, $versions['changes']['unpaidorderretentiondays']);
        $this->assertFalse($versions['changes']['cleanupcreationfailures']);
        $this->assertSame($before, $page->version('changes')->read('default'));
        $this->assertSame($latest, $page->version('latest')->read('default'));
    }

    public function testPhpLockedTogglesUseNativeBooleansAndKeepTheirSavedShadows(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout' => [
                'settings' => [
                    'cleanupCreationFailures' => true,
                    'cleanupUnpaidOrders' => false,
                    'defaultRequiresShipping' => false,
                    'creationFailureRetentionDays' => 21,
                ],
            ],
        ], beforeApp: static fn(TestWorkspace $workspace) => self::seedExistingSettings($workspace, [
            'cleanupCreationFailures' => 'false',
            'cleanupUnpaidOrders' => 'true',
            'defaultRequiresShipping' => 'yes',
        ]));
        $this->kirby = $this->environment->app();
        $store = new StripeCheckoutPageStore($this->kirby);
        $page = $store->initialize();
        $before = $page->version('latest')->read('default');
        $versions = $this->viewVersions();

        foreach ($versions as $content) {
            $this->assertTrue($content['cleanupcreationfailures']);
            $this->assertFalse($content['cleanupunpaidorders']);
            $this->assertSame('no', $content['defaultrequiresshipping']);
            $this->assertSame(21.0, $content['creationfailureretentiondays']);
            $fields = Fields::for($page)->fill($content);
            $this->assertTrue($fields->field('cleanupCreationFailures')->toFormValue());
            $this->assertFalse($fields->field('cleanupUnpaidOrders')->toFormValue());
        }

        $this->assertSame($before, $page->version('latest')->read('default'));
        Changes::publish($page, [...$versions['changes'], 'currency' => 'EUR']);
        $this->assertFalse($store->settings()->value('cleanupCreationFailures'));
        $this->assertTrue($store->settings()->value('cleanupUnpaidOrders'));
        $this->assertTrue($store->settings()->defaultRequiresShipping());
        $this->assertNull($store->settings()->value('creationFailureRetentionDays'));
    }

    /** @return array<string, array<string, mixed>> */
    private function viewVersions(): array
    {
        /** @var array{props: array{versions: array<string, \stdClass>}} $view */
        $view = StripeCheckoutArea::view($this->kirby);

        /** @var array<string, array<string, mixed>> */
        return array_map(static fn(\stdClass $content): array => (array) $content, $view['props']['versions']);
    }

    /** @param array<string, mixed> $values */
    private static function seedExistingSettings(TestWorkspace $workspace, array $values = [], bool $multilang = false): void
    {
        // Boot from content that predates the retention blueprint, rather than
        // deleting fields from a model whose content Kirby has already cached.
        $workspace->writeDraftPage(StripeCheckoutPage::ID, StripeCheckoutPage::TEMPLATE, [
            'title' => 'Stripe Checkout',
            'uuid' => 'existingsettings',
            'priceSource' => 'kirby',
            'stripeCheckout' => Yaml::encode([
                'owner' => StripeCheckoutPage::OWNER,
                'schemaVersion' => StripeCheckoutPage::SCHEMA_VERSION,
            ]),
            ...$values,
        ]);

        if ($multilang) {
            $root = $workspace->roots()['content'] . '/_drafts/stripe-checkout/stripe-checkout';
            F::move($root . '.txt', $root . '.en.txt');
        }
    }

    public function testNativePanelSavePersistsToggleAndNumberValues(): void
    {
        $store = new StripeCheckoutPageStore($this->kirby);
        $page = $store->initialize();
        $this->assertTrue($store->settings()->value('cleanupCreationFailures'));
        $this->assertSame(7, $store->settings()->value('creationFailureRetentionDays'));
        Changes::publish($page, [
            'currency' => 'EUR',
            'defaultRequiresShipping' => 'no',
            'cleanupCreationFailures' => false,
            'creationFailureRetentionDays' => '14',
            'cleanupUnpaidOrders' => true,
            'unpaidOrderRetentionDays' => '45',
        ]);
        $settings = (new ConfigurationResolver())->resolve([], $store->settings())->configurationOrFail()->settings();
        $this->assertFalse($settings->cleanupCreationFailures());
        $this->assertSame(14, $settings->creationFailureRetentionDays());
        $this->assertTrue($settings->cleanupUnpaidOrders());
        $this->assertSame(45, $settings->unpaidOrderRetentionDays());
    }

    public function testPhpLockIsVisibleAndEnforcedWithoutLockingOtherPolicyFields(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout.settings.creationFailureRetentionDays' => 21,
        ]);
        $this->kirby = $this->environment->app();
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        /** @var array<string, array<string, mixed>> $fields */
        $fields = $page->blueprint()->fields();
        $this->assertTrue($fields['creationFailureRetentionDays']['disabled']);
        $this->assertIsString($fields['creationFailureRetentionDays']['help']);
        $this->assertStringContainsString('settings.creationFailureRetentionDays', $fields['creationFailureRetentionDays']['help']);
        $this->assertFalse($fields['unpaidOrderRetentionDays']['disabled'] ?? false);
        $page = $page->update(['unpaidOrderRetentionDays' => 60]);
        $this->expectException(PermissionException::class);
        $page->update(['creationFailureRetentionDays' => 1]);
    }

    public function testRetentionFieldsStayInDefaultLanguage(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(languages: [
            [
                'code' => 'en',
                'name' => 'English',
                'default' => true,
            ],
            [
                'code' => 'pt',
                'name' => 'Português',
            ],
        ]);
        $this->kirby = $this->environment->app();
        $this->kirby->setCurrentLanguage('pt');
        $store = new StripeCheckoutPageStore($this->kirby);
        $page = $store->initialize()->update([
            'cleanupUnpaidOrders' => false,
            'unpaidOrderRetentionDays' => 90,
        ], 'pt');
        $this->assertFalse($store->settings()->value('cleanupUnpaidOrders'));
        $this->assertSame(90, $store->settings()->value('unpaidOrderRetentionDays'));
        $this->assertArrayNotHasKey('unpaidorderretentiondays', $page->version('latest')->read('pt') ?? []);
    }

    public function testDirectSettingsUpdateCannotStoreInvalidRetentionDays(): void
    {
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        $this->expectException(ConfigurationException::class);
        $page->update([
            'cleanupUnpaidOrders' => false,
            'unpaidOrderRetentionDays' => 0,
        ]);
    }
}
