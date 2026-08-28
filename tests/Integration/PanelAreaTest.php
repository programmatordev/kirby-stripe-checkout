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
use ProgrammatorDev\StripeCheckout\Kirby\SettingsPageStore;
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
        ], $defaults);
        $this->assertNotNull($admin);
        $this->assertTrue($admin->for(PluginPermissions::CATEGORY, 'settings.read'));
        $this->assertTrue($admin->for(PluginPermissions::CATEGORY, 'settings.update'));
        $this->assertTrue($admin->for(PluginPermissions::CATEGORY, 'diagnostics.read'));
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
        $this->assertSame([
            'stripe-checkout',
            'stripe-checkout/settings',
            'stripe-checkout/diagnostics',
        ], array_column($area['views'], 'pattern'));
    }

    public function testAreaCanBeNormalizedAsAnActivePanelView(): void
    {
        $definition = $this->area();
        $overview = $definition['views'][0]['action'];
        $area = Panel::area('stripe-checkout', $definition);
        $data = View::data(
            $overview(),
            [
                'area' => $area,
                'areas' => ['stripe-checkout' => $area],
            ],
        );
        /** @var callable(): array<string, mixed> $normalize */
        $normalize = $data['$view'];
        $view = $normalize();

        $this->assertTrue($view['menu']);
        $this->assertSame('k-stripe-checkout-overview-view', $view['component']);
    }

    public function testRegisteredAreaRendersThroughKirbysPanelRouter(): void
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
        /** @var array{'$view': array{component: string, title: string, props: array{tabs: list<array{label: string}>}}} $body */
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('k-stripe-checkout-overview-view', $body['$view']['component']);
        $this->assertSame('Stripe Checkout', $body['$view']['title']);
        $this->assertSame('Visão geral', $body['$view']['props']['tabs'][0]['label']);
        $this->assertStringNotContainsString('area.label', $response->body());
    }

    public function testCustomRoleCanReadSettingsWithoutUpdateOrDiagnosticsAccess(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            roles: [[
                'name' => 'store-manager',
                'permissions' => [
                    'access' => [
                        'panel' => true,
                        'stripe-checkout' => true,
                    ],
                    PluginPermissions::CATEGORY => [
                        'settings.read' => true,
                        'settings.update' => false,
                        'diagnostics.read' => false,
                    ],
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
        $area = $this->area();
        /** @var array{component: string} $settingsView */
        $settingsView = $area['views'][1]['action']();
        /** @var array{options: array{access: bool, list: bool, read: bool, update: bool}} $blueprint */
        $blueprint = SettingsBlueprint::load($this->kirby);

        $this->assertSame('k-stripe-checkout-settings-view', $settingsView['component']);
        $this->assertTrue($blueprint['options']['access']);
        $this->assertFalse($blueprint['options']['list']);
        $this->assertTrue($blueprint['options']['read']);
        $this->assertFalse($blueprint['options']['update']);

        $this->expectException(PermissionException::class);
        $area['views'][2]['action']();
    }

    public function testSettingsViewUsesTheAutomaticallyInitializedPage(): void
    {
        $area = $this->area();
        $settingsAction = $area['views'][1]['action'];
        $page = (new SettingsPageStore($this->kirby))->page();

        /** @var array{component: string, props: array{areaTabs: list<array{name: string}>}} $view */
        $view = $settingsAction();

        $this->assertNotNull($page);
        $this->assertSame('k-stripe-checkout-settings-view', $view['component']);
        $this->assertSame(
            ['overview', 'settings', 'diagnostics'],
            array_column($view['props']['areaTabs'], 'name'),
        );
    }

    public function testViewsRepeatPermissionChecksServerSide(): void
    {
        $area = $this->area();
        $this->kirby->impersonate('nobody');

        foreach ([
            $area['views'][0]['action'],
            $area['views'][1]['action'],
            $area['views'][2]['action'],
        ] as $action) {
            try {
                $action();
                $this->fail('The Panel action did not enforce its permission.');
            } catch (PermissionException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNotNull((new SettingsPageStore($this->kirby))->page());
    }

    public function testSettingsViewReportsAnExistingPageCollisionWithoutChangingIt(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            beforeApp: static function (TestWorkspace $workspace): void {
                $workspace->writeDraftPage(
                    'stripe-checkout-settings',
                    'default',
                    [
                        'marker' => 'preserved',
                        'title' => 'Existing page',
                    ],
                );
            },
        );
        $this->kirby = $this->environment->app();
        $page = $this->kirby->site()->findPageOrDraft('stripe-checkout-settings');
        $this->assertNotNull($page);
        $area = $this->area();
        /** @var array{component: string, props: array{error: string}} $view */
        $view = $area['views'][1]['action']();

        $this->assertSame('k-error-view', $view['component']);
        $this->assertStringContainsString('unexpected model or location', $view['props']['error']);
        $marker = $page->content()->get('marker');
        $this->assertInstanceOf(Field::class, $marker);
        $this->assertSame('preserved', $marker->value());
    }

    public function testPhpLockIsVisibleInTheSettingsView(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout' => [
                'settings' => ['priceSource' => 'stripe'],
            ],
        ]);
        $this->kirby = $this->environment->app();
        $area = $this->area();
        /** @var array{component: string, props: array{versions: array{latest: \stdClass}}} $view */
        $view = $area['views'][1]['action']();
        $blueprint = $this->kirby->site()
            ->findPageOrDraft('stripe-checkout-settings')
            ?->blueprint();

        $this->assertSame('k-stripe-checkout-settings-view', $view['component']);
        $this->assertSame('stripe', $view['props']['versions']['latest']->pricesource);
        $this->assertNotNull($blueprint);
        $field = $blueprint->field('priceSource');
        $this->assertIsArray($field);
        $this->assertTrue($field['disabled']);
        $this->assertIsString($field['help']);
        $this->assertStringContainsString(
            'programmatordev.stripe-checkout.settings.priceSource',
            $field['help'],
        );
    }

    public function testSettingsBlueprintCannotBeReplacedByTheProject(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            beforeApp: static function (TestWorkspace $workspace): void {
                $workspace->writePageBlueprint('stripe-checkout-settings', [
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
        $sections = $blueprint['sections'] ?? null;
        $this->assertIsArray($sections);
        $settings = $sections['settings'] ?? null;
        $this->assertIsArray($settings);
        $fields = $settings['fields'] ?? null;
        $this->assertIsArray($fields);
        $this->assertArrayHasKey('priceSource', $fields);
        $this->assertArrayNotHasKey('projectField', $fields);
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
        $page = (new SettingsPageStore($this->kirby))->initialize();
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

    /**
     * @return array{
     *   menu: callable(array<mixed>, array<mixed>): bool,
     *   views: array{
     *     0: array{pattern: string, action: callable(): array<string, mixed>},
     *     1: array{pattern: string, action: callable(): array<string, mixed>},
     *     2: array{pattern: string, action: callable(): array<string, mixed>}
     *   }
     * }
     */
    private function area(): array
    {
        /** @var array{
         *   menu: callable(array<mixed>, array<mixed>): bool,
         *   views: array{
         *     0: array{pattern: string, action: callable(): array<string, mixed>},
         *     1: array{pattern: string, action: callable(): array<string, mixed>},
         *     2: array{pattern: string, action: callable(): array<string, mixed>}
         *   }
         * } $area
         */
        $area = StripeCheckoutArea::definition($this->kirby);

        return $area;
    }
}
