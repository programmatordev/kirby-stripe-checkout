<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Kirby\Cms\Permissions;
use Kirby\Content\Field;
use Kirby\Exception\PermissionException;
use Kirby\Form\Fields;
use Kirby\Panel\Panel;
use Kirby\Panel\View;
use ProgrammatorDev\StripeCheckout\Kirby\PluginPermissions;
use ProgrammatorDev\StripeCheckout\Kirby\SettingsBlueprint;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPageStore;
use ProgrammatorDev\StripeCheckout\Panel\StripeCheckoutArea;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\TestWorkspace;

final class PanelAreaTest extends KirbyTestCase
{
    public function testPermissionDefaultsAreDeniedAndAdminReceivesAllActions(): void
    {
        $defaults = (new Permissions())->toArray()[PluginPermissions::CATEGORY];
        $admin = $this->kirby->user()?->role()->permissions();

        $this->assertSame([
            'settings.read' => false,
            'settings.update' => false,
            'diagnostics.read' => false,
            'prices.read' => false,
        ], $defaults);
        $this->assertNotNull($admin);
        $this->assertTrue($admin->for(PluginPermissions::CATEGORY, 'settings.read'));
        $this->assertTrue($admin->for(PluginPermissions::CATEGORY, 'settings.update'));
        $this->assertTrue($admin->for(PluginPermissions::CATEGORY, 'diagnostics.read'));
        $this->assertTrue($admin->for(PluginPermissions::CATEGORY, 'prices.read'));
    }

    public function testMenuUsesReadPermissionsWithoutChangingAreaAccess(): void
    {
        $area = $this->area();
        $menu = $area['menu'];

        $this->assertFalse($menu([], [PluginPermissions::CATEGORY => [
            'settings.read' => false,
            'diagnostics.read' => false,
        ]]));
        $this->assertTrue($menu([], [PluginPermissions::CATEGORY => [
            'settings.read' => true,
            'diagnostics.read' => false,
        ]]));
        $this->assertTrue($menu([], [PluginPermissions::CATEGORY => [
            'settings.read' => false,
            'diagnostics.read' => true,
        ]]));
        $this->assertSame(['stripe-checkout'], array_column($area['views'], 'pattern'));
    }

    public function testAreaNormalizesTheNativePageView(): void
    {
        $definition = $this->area();
        $action = $definition['views'][0]['action'];
        $area = Panel::area('stripe-checkout', $definition);
        $data = View::data(
            $action(),
            [
                'area' => $area,
                'areas' => ['stripe-checkout' => $area],
            ],
        );
        /** @var callable(): array<string, mixed> $normalize */
        $normalize = $data['$view'];
        $view = $normalize();
        /** @var array{buttons: list<mixed>} $props */
        $props = $view['props'];

        $this->assertTrue($view['menu']);
        $this->assertSame('k-page-view', $view['component']);
        $this->assertSame([], $props['buttons']);
    }

    public function testRegisteredAreaRendersNativeTranslatedTabsThroughKirbysRouter(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            users: [[
                'id' => 'panel-admin',
                'email' => 'panel-admin@example.com',
                'language' => 'pt_PT',
                'role' => 'admin',
            ]],
            impersonate: 'panel-admin',
            request: [
                'method' => 'GET',
                'query' => ['_json' => '1'],
                'url' => 'https://kirby-stripe-checkout.test/panel/stripe-checkout',
            ],
        );
        $this->kirby = $this->environment->app();
        $response = Panel::router('stripe-checkout');

        $this->assertNotNull($response);
        $this->assertSame(200, $response->code());
        /** @var array{'$view': array{component: string, title: string, props: array{tabs: list<array{label: string, link: string}>}}} $body */
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('k-page-view', $body['$view']['component']);
        $this->assertSame('Stripe Checkout', $body['$view']['title']);
        $this->assertSame(
            ['Visão geral', 'Definições', 'Diagnóstico'],
            array_column($body['$view']['props']['tabs'], 'label'),
        );
        $this->assertSame(
            [
                Panel::url('stripe-checkout'),
                Panel::url('stripe-checkout') . '?tab=settings',
                Panel::url('stripe-checkout') . '?tab=diagnostics',
            ],
            array_column($body['$view']['props']['tabs'], 'link'),
        );
        $this->assertStringNotContainsString('area.label', $response->body());
    }

    public function testSettingsReaderGetsOnlyOverviewAndSettingsTabs(): void
    {
        $this->restartWithPermissions([
            'settings.read' => true,
            'settings.update' => false,
            'diagnostics.read' => false,
        ]);
        $blueprint = SettingsBlueprint::load($this->kirby);
        $view = $this->view();
        /** @var array{tabs: list<array{name: string}>} $props */
        $props = $view['props'];
        /** @var array{access: bool, list: bool, read: bool, update: bool} $options */
        $options = $blueprint['options'];

        $this->assertSame('k-page-view', $view['component']);
        $this->assertSame(['overview', 'settings'], array_column($props['tabs'], 'name'));
        $this->assertTrue($options['access']);
        $this->assertFalse($options['list']);
        $this->assertTrue($options['read']);
        $this->assertFalse($options['update']);
        $this->assertFalse($blueprint['buttons']);
    }

    public function testDiagnosticsReaderGetsNativeDiagnosticSectionsWithoutUpdateAccess(): void
    {
        $this->restartWithPermissions([
            'settings.read' => false,
            'settings.update' => false,
            'diagnostics.read' => true,
        ]);
        $blueprint = SettingsBlueprint::load($this->kirby);
        $view = $this->view();
        /** @var array{tabs: list<array{name: string}>, permissions: array{update: bool}} $props */
        $props = $view['props'];
        /** @var array{access: bool, read: bool, update: bool} $options */
        $options = $blueprint['options'];
        /** @var array<string, array<string, mixed>> $tabs */
        $tabs = $blueprint['tabs'];
        /** @var array<string, array<string, mixed>> $sections */
        $sections = $tabs['diagnostics']['sections'];

        $this->assertSame(['overview', 'diagnostics'], array_column($props['tabs'], 'name'));
        $this->assertFalse($props['permissions']['update']);
        $this->assertTrue($options['access']);
        $this->assertTrue($options['read']);
        $this->assertFalse($options['update']);
        $this->assertArrayHasKey('diagnostics-summary', $sections);
        $this->assertArrayHasKey('diagnostics-php', $sections);
        $this->assertSame('info', $sections['diagnostics-php']['type']);
    }

    public function testRequestedNativeTabRemainsInsideTheArea(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(request: [
            'method' => 'GET',
            'query' => ['tab' => 'settings'],
            'url' => 'https://kirby-stripe-checkout.test/panel/stripe-checkout?tab=settings',
        ]);
        $this->kirby = $this->environment->app();
        $view = $this->view();
        /** @var array{tab: array{name: string, link: string}, prev: mixed, next: mixed} $props */
        $props = $view['props'];

        $this->assertSame('settings', $props['tab']['name']);
        $this->assertSame(
            Panel::url('stripe-checkout') . '?tab=settings',
            $props['tab']['link'],
        );
        $this->assertNull($props['prev']);
        $this->assertNull($props['next']);
    }

    public function testViewRepeatsPermissionCheckServerSide(): void
    {
        $area = $this->area();
        $this->kirby->impersonate('nobody');

        try {
            $area['views'][0]['action']();
            $this->fail('The Panel action did not enforce its permission.');
        } catch (PermissionException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNotNull((new StripeCheckoutPageStore($this->kirby))->page());
    }

    public function testSettingsEditorCanSaveThroughKirbysApiRoute(): void
    {
        $this->restartWithPermissions([
            'settings.read' => true,
            'settings.update' => true,
            'diagnostics.read' => false,
        ], ['api.allowImpersonation' => true]);

        $this->kirby->api()->call(
            'pages/stripe-checkout',
            'PATCH',
            ['body' => [
                'priceSource' => 'stripe',
                'currency' => 'EUR',
                'defaultRequiresShipping' => 'no',
            ]],
        );

        $page = (new StripeCheckoutPageStore($this->kirby))->page();

        $this->assertNotNull($page);
        $priceSource = $page->content()->get('priceSource');
        $currency = $page->content()->get('currency');
        $shipping = $page->content()->get('defaultRequiresShipping');
        $this->assertInstanceOf(Field::class, $priceSource);
        $this->assertInstanceOf(Field::class, $currency);
        $this->assertInstanceOf(Field::class, $shipping);
        $this->assertSame('stripe', $priceSource->value());
        $this->assertSame('EUR', $currency->value());
        $this->assertSame('no', $shipping->value());
    }

    public function testPhpLockedSettingCannotBeChangedThroughKirbysApiRoute(): void
    {
        $this->restartWithPermissions([
            'settings.read' => true,
            'settings.update' => true,
            'diagnostics.read' => false,
        ], [
            'api.allowImpersonation' => true,
            'programmatordev.stripe-checkout' => [
                'settings' => ['priceSource' => 'stripe'],
            ],
        ]);

        $this->expectException(PermissionException::class);
        $this->expectExceptionMessage('locked by PHP configuration');

        $this->kirby->api()->call(
            'pages/stripe-checkout',
            'PATCH',
            ['body' => ['priceSource' => 'stripe']],
        );
    }

    public function testViewReportsAnExistingPageCollisionWithoutChangingIt(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            beforeApp: static function (TestWorkspace $workspace): void {
                $workspace->writeDraftPage(
                    'stripe-checkout',
                    'default',
                    [
                        'marker' => 'preserved',
                        'title' => 'Existing page',
                    ],
                );
            },
        );
        $this->kirby = $this->environment->app();
        $page = $this->kirby->site()->findPageOrDraft('stripe-checkout');
        $this->assertNotNull($page);
        $view = $this->view();
        /** @var array{error: string} $props */
        $props = $view['props'];

        $this->assertSame('k-error-view', $view['component']);
        $this->assertStringContainsString('unexpected model or location', $props['error']);
        $marker = $page->content()->get('marker');
        $this->assertInstanceOf(Field::class, $marker);
        $this->assertSame('preserved', $marker->value());
    }

    public function testPhpLockIsVisibleInTheNativeAreaView(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout' => [
                'settings' => [
                    'priceSource' => 'stripe',
                    'currency' => 'USD',
                    'defaultRequiresShipping' => false,
                ],
            ],
        ]);
        $this->kirby = $this->environment->app();
        $view = $this->view();
        $blueprint = $this->kirby->site()
            ->findPageOrDraft('stripe-checkout')
            ?->blueprint();
        /** @var array{versions: array{latest: \stdClass}} $props */
        $props = $view['props'];

        $this->assertSame('k-page-view', $view['component']);
        $this->assertSame('stripe', $props['versions']['latest']->pricesource);
        $this->assertSame('USD', $props['versions']['latest']->currency);
        $this->assertSame('no', $props['versions']['latest']->defaultrequiresshipping);
        $this->assertNotNull($blueprint);
        $field = $blueprint->field('priceSource');
        $this->assertIsArray($field);
        $this->assertTrue($field['disabled']);
        $this->assertIsString($field['help']);
        $this->assertStringContainsString(
            'programmatordev.stripe-checkout.settings.priceSource',
            $field['help'],
        );

        foreach (['currency', 'defaultRequiresShipping'] as $fieldName) {
            $lockedField = $blueprint->field($fieldName);
            $this->assertIsArray($lockedField);
            $this->assertTrue($lockedField['disabled']);
            $help = $lockedField['help'];
            $this->assertIsString($help);
            $this->assertStringContainsString(
                'programmatordev.stripe-checkout.settings.' . $fieldName,
                $help,
            );
        }
    }

    public function testSettingsBlueprintCannotBeReplacedByTheProject(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            beforeApp: static function (TestWorkspace $workspace): void {
                $workspace->writePageBlueprint('stripe-checkout', [
                    'title' => 'Custom store settings',
                    'sections' => [
                        'custom' => [
                            'type' => 'fields',
                            'fields' => [
                                'projectField' => ['type' => 'text'],
                            ],
                        ],
                    ],
                ]);
            },
        );
        $this->kirby = $this->environment->app();
        $blueprint = SettingsBlueprint::load($this->kirby);
        /** @var array<string, array<string, mixed>> $tabs */
        $tabs = $blueprint['tabs'];
        /** @var array<string, array<string, mixed>> $sections */
        $sections = $tabs['settings']['sections'];
        /** @var array<string, mixed> $settings */
        $settings = $sections['settings'];
        /** @var array<string, mixed> $fields */
        $fields = $settings['fields'];

        $this->assertArrayHasKey('priceSource', $fields);
        $this->assertArrayHasKey('currency', $fields);
        $this->assertArrayHasKey('defaultRequiresShipping', $fields);
        $this->assertArrayNotHasKey('projectField', $fields);
        $priceSource = $fields['priceSource'];
        $this->assertIsArray($priceSource);
        $this->assertTrue($priceSource['required']);
        $this->assertSame('kirby', $priceSource['default']);
        $this->assertSame(
            'programmatordev.stripe-checkout.area.label',
            $blueprint['title'],
        );
    }

    public function testPriceSourceOptionsAreTranslated(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            users: [[
                'id' => 'panel-admin',
                'email' => 'panel-admin@example.com',
                'language' => 'pt_PT',
                'role' => 'admin',
            ]],
            impersonate: 'panel-admin',
        );
        $this->kirby = $this->environment->app();
        $this->kirby->setCurrentTranslation('pt_PT');
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        $field = Fields::for($page)->field('priceSource')->toArray();
        /** @var list<array{text: string, value: string}> $options */
        $options = $field['options'];

        $this->assertSame(['Kirby', 'Stripe'], array_column($options, 'text'));
        $this->assertSame(['kirby', 'stripe'], array_column($options, 'value'));
        $this->assertStringNotContainsString(
            'programmatordev.stripe-checkout.settings.priceSource',
            implode(' ', array_column($options, 'text')),
        );
    }

    public function testCommerceSettingOptionsAreLocalizedAndUseStableValues(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            users: [[
                'id' => 'panel-admin',
                'email' => 'panel-admin@example.com',
                'language' => 'pt_PT',
                'role' => 'admin',
            ]],
            impersonate: 'panel-admin',
        );
        $this->kirby = $this->environment->app();
        $this->kirby->setCurrentTranslation('pt_PT');
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        $currency = Fields::for($page)->field('currency')->toArray();
        $shipping = Fields::for($page)->field('defaultRequiresShipping')->toArray();
        /** @var list<array{text: string, value: string}> $currencyOptions */
        $currencyOptions = $currency['options'];
        /** @var list<array{text: string, value: string}> $shippingOptions */
        $shippingOptions = $shipping['options'];
        $eur = array_values(array_filter(
            $currencyOptions,
            static fn(array $option): bool => $option['value'] === 'EUR',
        ));

        $this->assertCount(1, $eur);
        $this->assertStringStartsWith('EUR — ', $eur[0]['text']);
        $this->assertSame(['Sim', 'Não'], array_column($shippingOptions, 'text'));
        $this->assertSame(['yes', 'no'], array_column($shippingOptions, 'value'));
    }

    /**
     * @param array<string, bool>  $permissions
     * @param array<string, mixed> $options
     */
    private function restartWithPermissions(array $permissions, array $options = []): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            options: $options,
            roles: [[
                'name' => 'store-manager',
                'permissions' => [
                    'access' => [
                        'panel' => true,
                        'stripe-checkout' => true,
                    ],
                    PluginPermissions::CATEGORY => $permissions,
                ],
            ]],
            users: [[
                'id' => 'store-manager',
                'email' => 'manager@example.com',
                'role' => 'store-manager',
            ]],
            impersonate: 'store-manager',
        );
        $this->kirby = $this->environment->app();
    }

    /** @return array<string, mixed> */
    private function view(): array
    {
        return $this->area()['views'][0]['action']();
    }

    /**
     * @return array{
     *   menu: callable(array<mixed>, array<mixed>): bool,
     *   views: array{0: array{pattern: string, action: callable(): array<string, mixed>}}
     * }
     */
    private function area(): array
    {
        /** @var array{
         *   menu: callable(array<mixed>, array<mixed>): bool,
         *   views: array{0: array{pattern: string, action: callable(): array<string, mixed>}}
         * } $area
         */
        $area = StripeCheckoutArea::definition($this->kirby);

        return $area;
    }
}
