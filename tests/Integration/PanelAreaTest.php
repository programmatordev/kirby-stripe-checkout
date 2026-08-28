<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Kirby\Cms\Page;
use Kirby\Cms\Permissions;
use Kirby\Content\Field;
use Kirby\Exception\Exception as KirbyException;
use Kirby\Exception\PermissionException;
use Kirby\Panel\Panel;
use Kirby\Panel\View;
use ProgrammatorDev\StripeCheckout\Kirby\PluginPermissions;
use ProgrammatorDev\StripeCheckout\Kirby\SettingsBlueprint;
use ProgrammatorDev\StripeCheckout\Kirby\SettingsPageStore;
use ProgrammatorDev\StripeCheckout\Panel\StripeCheckoutArea;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;

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

    public function testCustomRoleCanReadSettingsWithoutSetupOrDiagnosticsAccess(): void
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
        /** @var array{component: string, props: array{canSetup: bool}} $settingsView */
        $settingsView = $area['views'][1]['action']();
        /** @var array{options: array{access: bool, list: bool, read: bool, update: bool}} $blueprint */
        $blueprint = SettingsBlueprint::load($this->kirby);

        $this->assertSame('k-stripe-checkout-setup-view', $settingsView['component']);
        $this->assertFalse($settingsView['props']['canSetup']);
        $this->assertTrue($blueprint['options']['access']);
        $this->assertFalse($blueprint['options']['list']);
        $this->assertTrue($blueprint['options']['read']);
        $this->assertFalse($blueprint['options']['update']);

        $this->expectException(PermissionException::class);
        $area['views'][2]['action']();
    }

    public function testSettingsViewDoesNotCreateContentAndSetupIsExplicit(): void
    {
        $area = $this->area();
        $settingsAction = $area['views'][1]['action'];
        $setupAction = $area['dialogs']['setup']['submit'];

        /** @var array{component: string} $view */
        $view = $settingsAction();

        $this->assertSame('k-stripe-checkout-setup-view', $view['component']);
        $this->assertNull((new SettingsPageStore($this->kirby))->page());

        /** @var array{redirect: string} $result */
        $result = $setupAction();

        $this->assertStringEndsWith('/panel/stripe-checkout/settings', $result['redirect']);
        $this->assertNotNull((new SettingsPageStore($this->kirby))->page());
        $this->assertSame('k-page-view', $settingsAction()['component']);
    }

    public function testSetupAndViewsRepeatPermissionChecksServerSide(): void
    {
        $area = $this->area();
        $this->kirby->impersonate('nobody');

        foreach ([
            $area['views'][0]['action'],
            $area['views'][1]['action'],
            $area['views'][2]['action'],
            $area['dialogs']['setup']['load'],
            $area['dialogs']['setup']['submit'],
        ] as $action) {
            try {
                $action();
                $this->fail('The Panel action did not enforce its permission.');
            } catch (PermissionException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNull((new SettingsPageStore($this->kirby))->page());
    }

    public function testSetupReportsAnExistingPageCollisionWithoutChangingIt(): void
    {
        /** @var Page $page */
        $page = $this->kirby->impersonate(
            'kirby',
            fn(): Page => Page::create([
                'content' => [
                    'marker' => 'preserved',
                    'title' => 'Existing page',
                ],
                'isDraft' => true,
                'parent' => null,
                'site' => $this->kirby->site(),
                'slug' => 'stripe-checkout-settings',
                'template' => 'default',
            ]),
        );
        $area = $this->area();

        try {
            $area['dialogs']['setup']['submit']();
            $this->fail('The setup action accepted an unrelated Page collision.');
        } catch (KirbyException $error) {
            $this->assertStringContainsString('unexpected model or location', $error->getMessage());
        }

        $marker = $page->content()->get('marker');
        $this->assertInstanceOf(Field::class, $marker);
        $this->assertSame('preserved', $marker->value());
    }

    public function testPhpLockIsVisibleInTheNativeSettingsView(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout' => [
                'settings' => ['priceSource' => 'stripe'],
            ],
        ]);
        $this->kirby = $this->environment->app();
        (new SettingsPageStore($this->kirby))->initialize();
        $area = $this->area();
        /** @var array{component: string, props: array{versions: array{latest: \stdClass}}} $view */
        $view = $area['views'][1]['action']();
        $blueprint = $this->kirby->site()
            ->findPageOrDraft('stripe-checkout-settings')
            ?->blueprint();

        $this->assertSame('k-page-view', $view['component']);
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

    /**
     * @return array{
     *   menu: callable(array<mixed>, array<mixed>): bool,
     *   dialogs: array{setup: array{load: callable(): array<string, mixed>, submit: callable(): array<string, mixed>}},
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
         *   dialogs: array{setup: array{load: callable(): array<string, mixed>, submit: callable(): array<string, mixed>}},
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
