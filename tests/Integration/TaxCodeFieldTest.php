<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Kirby\Cms\Page;
use Kirby\Exception\PermissionException;
use Kirby\Form\Form;
use ProgrammatorDev\StripeCheckout\Configuration\StripeConfiguration;
use ProgrammatorDev\StripeCheckout\Diagnostics\LocalDiagnostics;
use ProgrammatorDev\StripeCheckout\Kirby\TaxCodeField;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeCatalogue;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeListResult;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeRecord;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\Stripe\FakeTaxProvider;
use ProgrammatorDev\StripeCheckout\Test\Support\TestWorkspace;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

final class TaxCodeFieldTest extends KirbyTestCase
{
    private const PREFIX = 'programmatordev.stripe-checkout';

    public function testRequiredPerformanceLocationWarnsWithoutBlockingSelectionOrStorage(): void
    {
        $page = $this->restart();
        $catalogue = new TaxCodeCatalogue(
            $this->kirby->cache(self::PREFIX . '.taxCodes'),
            new FakeTaxProvider(pages: [
                'first' => new TaxCodeListResult([
                    new TaxCodeRecord('txcd_test0', 'Event', 'Event admission', requiresPerformanceLocation: true),
                ], false),
            ]),
            'unconfigured',
        );
        $catalogue->refresh();
        $field = Form::for($page)->fields()->field('taxCode');
        /** @var array{disabled: bool, selected: array{theme: string, warning: string}} $props */
        $props = $field->toArray();
        $selected = $props['selected'];

        $this->assertSame('txcd_test0', $field->toStoredValue());
        $this->assertFalse($props['disabled']);
        $this->assertSame('warning', $selected['theme']);
        $this->assertStringContainsString('performance location', $selected['warning']);
        $this->assertSame($selected, TaxCodeField::apiResponse($this->kirby, null, 1, false, 'txcd_test0')['data'][0]);
        /** @var array{data: list<array<string, mixed>>} $response */
        $response = $this->kirby->api()->call('pages/product/fields/options/tax-codes', 'GET', [
            'query' => [
                'view' => 'selected',
                'taxCode' => 'txcd_test0',
            ],
        ]);
        $this->assertSame($selected, $response['data'][0]);
    }

    public function testFieldStoresAScalarAndHydratesCachedDetails(): void
    {
        $page = $this->restart();
        $this->seed();
        $field = Form::for($page)->fields()->field('taxCode');
        /** @var array{disabled: bool, sourceInactive: bool, value: string, selected?: array{id: string, text: string, unavailable?: bool}, catalogue: array{status: string, failedAt: ?int, refreshedAt: ?int}} $props */
        $props = $field->toArray();

        $this->assertInstanceOf(TaxCodeField::class, $field);
        $this->assertSame('txcd_test0', $field->toStoredValue());
        $this->assertSame('txcd_test0', $props['value']);
        $this->assertSame('Category 0', $props['selected']['text'] ?? null);
        $this->assertArrayNotHasKey('warning', $props['selected'] ?? []);
        $this->assertSame('ready', $props['catalogue']['status']);
        $this->assertFalse($props['disabled']);
        $this->assertSame('', $field->fill('')->toStoredValue());
    }

    public function testSearchPaginationAndVariantHydrationShareTheCatalogue(): void
    {
        $page = $this->restart();
        $this->seed(25);
        $response = TaxCodeField::apiResponse($this->kirby, null, 2, false);
        $this->assertCount(5, $response['data']);
        $this->assertSame(25, $response['pagination']['total']);
        $this->assertSame(2, $response['pagination']['pages']);
        $searched = TaxCodeField::apiResponse($this->kirby, 'Category 24', 99, false);
        $this->assertSame('txcd_test24', $searched['data'][0]['id'] ?? null);
        $this->assertSame(1, $searched['pagination']['page']);
        /** @var array{automaticTax: bool, taxCodesReadable: bool} $props */
        $props = Form::for($page)->fields()->field('options')->toArray();
        $this->assertTrue($props['automaticTax']);
        $this->assertTrue($props['taxCodesReadable']);
        /** @var array{data: list<array{id: string}>} $selected */
        $selected = $this->kirby->api()->call('pages/product/fields/options/tax-codes', 'GET', [
            'query' => [
                'view' => 'selected',
                'taxCodes' => 'txcd_test24,txcd_unknown',
            ],
        ]);
        $this->assertSame('txcd_test24', $selected['data'][0]['id'] ?? null);
    }

    public function testFirstLoadRefreshesAndFreshReadsDoNotFetchAgain(): void
    {
        $page = $this->restart(secretKey: 'sk_test_tax_field');
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('request')->willReturn([
            json_encode([
                'object' => 'list',
                'data' => [[
                    'object' => 'tax_code',
                    'id' => 'txcd_test0',
                    'name' => 'Category 0',
                    'description' => 'Description',
                ]],
                'has_more' => false,
            ], JSON_THROW_ON_ERROR),
            200,
            [],
        ]);
        ApiRequestor::setHttpClient($client);

        /** @var array{catalogue: array{status: string}} $props */
        $props = Form::for($page)->fields()->field('taxCode')->toArray();
        $this->assertSame('ready', $props['catalogue']['status']);
        $this->assertSame('ready', TaxCodeField::apiResponse($this->kirby, null, 1, false)['catalogue']['status']);
        $this->assertSame('Category 0', TaxCodeField::apiResponse($this->kirby, null, 1, false, 'txcd_test0')['data'][0]['text'] ?? null);
    }

    public function testMonthlyPanelLoadRefreshesAnOldSnapshot(): void
    {
        $page = $this->restart(secretKey: 'sk_test_tax_field');
        $this->seed(refreshedAt: time() - 31 * 24 * 60 * 60, secretKey: 'sk_test_tax_field');
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('request')->willReturn([
            '{"object":"list","data":[{"object":"tax_code","id":"txcd_test0","name":"Updated category","description":"Updated"}],"has_more":false}',
            200,
            [],
        ]);
        ApiRequestor::setHttpClient($client);

        /** @var array{disabled: bool, sourceInactive: bool, value: string, selected?: array{id: string, text: string, unavailable?: bool}, catalogue: array{status: string, failedAt: ?int, refreshedAt: ?int}} $props */
        $props = Form::for($page)->fields()->field('taxCode')->toArray();
        $this->assertSame('Updated category', $props['selected']['text'] ?? null);
        $this->assertGreaterThan(time() - 10, $props['catalogue']['refreshedAt'] ?? 0);
    }

    public function testFailureRetainsLastGoodDetailsAndBacksOff(): void
    {
        $page = $this->restart();
        $this->seed(refreshedAt: time() - 31 * 24 * 60 * 60);
        /** @var array{disabled: bool, sourceInactive: bool, value: string, selected?: array{id: string, text: string, unavailable?: bool}, catalogue: array{status: string, failedAt: ?int, refreshedAt: ?int}} $props */
        $props = Form::for($page)->fields()->field('taxCode')->toArray();
        $this->assertSame('stale', $props['catalogue']['status']);
        $this->assertSame('Category 0', $props['selected']['text'] ?? null);
        $failedAt = $props['catalogue']['failedAt'];
        $this->assertSame($failedAt, TaxCodeField::apiResponse($this->kirby, null, 1, false)['catalogue']['failedAt']);
        $this->assertSame('stale', TaxCodeField::apiResponse($this->kirby, null, 1, true)['catalogue']['status']);
    }

    public function testMissingReferenceRemainsStored(): void
    {
        $page = $this->restart()->update(['taxCode' => 'txcd_removed']);
        $this->seed();
        $field = Form::for($page)->fields()->field('taxCode');
        $this->assertSame('txcd_removed', $field->toStoredValue());
        /** @var array{selected: array{id: string, unavailable: bool}} $props */
        $props = $field->toArray();
        $this->assertSame('txcd_removed', $props['selected']['id']);
        $this->assertTrue($props['selected']['unavailable']);
        $this->assertSame([], TaxCodeField::apiResponse($this->kirby, null, 1, false, 'txcd_removed')['data']);
    }

    public function testPermissionDenialBlocksReadsAndRefreshWithoutStripeTraffic(): void
    {
        $page = $this->restart(read: false, secretKey: 'sk_test_tax_field');
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->never())->method('request');
        ApiRequestor::setHttpClient($client);
        $this->assertTrue(Form::for($page)->fields()->field('taxCode')->toArray()['disabled']);
        $this->assertFalse(Form::for($page)->fields()->field('options')->toArray()['taxCodesReadable']);

        $methods = ['GET', 'POST'];

        foreach ($methods as $method) {
            try {
                $this->kirby->api()->call('pages/product/fields/taxCode', $method);
                $this->fail('Tax catalogue access must require permission.');
            } catch (PermissionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testInactiveFieldIsDisabledWithoutRefreshingItsOldCatalogue(): void
    {
        $page = $this->restart(automaticTax: false, secretKey: 'sk_test_tax_field');
        $this->seed(refreshedAt: time() - 31 * 24 * 60 * 60, secretKey: 'sk_test_tax_field');
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->never())->method('request');
        ApiRequestor::setHttpClient($client);
        /** @var array{disabled: bool, sourceInactive: bool, value: string, selected?: array{id: string, text: string, unavailable?: bool}, catalogue: array{status: string, failedAt: ?int, refreshedAt: ?int}} $props */
        $props = Form::for($page)->fields()->field('taxCode')->toArray();
        $this->assertTrue($props['disabled']);
        $this->assertTrue($props['sourceInactive']);
        $this->assertSame('Category 0', $props['selected']['text'] ?? null);
        $this->assertFalse(Form::for($page)->fields()->field('options')->toArray()['automaticTax']);
    }

    public function testInactiveMalformedContentDoesNotBlockNativeFormValidation(): void
    {
        $page = $this->restart(automaticTax: false)->update(['taxCode' => 'retained_malformed_code']);
        $form = Form::for($page);
        $field = $form->fields()->field('taxCode');

        $this->assertTrue($field->isDisabled());
        $this->assertTrue($form->isValid());
        $this->assertSame('retained_malformed_code', $field->toFormValue());
    }

    public function testDiagnosticsReportCacheFactsWithoutRefreshing(): void
    {
        $this->restart(secretKey: 'sk_test_tax_field');
        $this->seed(refreshedAt: time() - 31 * 24 * 60 * 60, secretKey: 'sk_test_tax_field');
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->never())->method('request');
        ApiRequestor::setHttpClient($client);
        $checks = (new LocalDiagnostics($this->kirby))->report()['checks'];
        $check = array_values(array_filter($checks, static fn(array $check): bool => $check['id'] === 'taxCodes'))[0];
        $this->assertSame('taxCodes.ready', $check['message']);
        $this->assertSame('1', $check['values']['count']);
    }

    private function restart(?bool $read = null, bool $automaticTax = true, ?string $secretKey = null): Page
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            options: [
                'api.allowImpersonation' => true,
                self::PREFIX => [
                    'settings' => [
                        'automaticTax' => $automaticTax,
                        'currency' => 'EUR',
                    ],
                    'stripe' => ['secretKey' => $secretKey],
                ],
            ],
            beforeApp: static function (TestWorkspace $workspace): void {
                $workspace->writePageBlueprint('tax-product', [
                    'title' => 'Tax product',
                    'fields' => [
                        'taxCode' => ['type' => 'stripe-checkout-tax-code'],
                        'options' => ['type' => 'stripe-checkout-options'],
                    ],
                ]);
            },
            roles: $read === null ? null : [[
                'name' => 'tax-editor',
                'permissions' => [
                    'access' => ['panel' => true],
                    self::PREFIX => ['taxCodes.read' => $read],
                ],
            ]],
            users: $read === null ? null : [[
                'id' => 'tax-editor',
                'email' => 'tax-editor@example.com',
                'role' => 'tax-editor',
            ]],
            impersonate: $read === null ? 'kirby' : 'tax-editor',
        );
        $this->kirby = $this->environment->app();

        return $this->kirby->site()->createChild([
            'slug' => 'product',
            'template' => 'tax-product',
            'content' => [
                'title' => 'Product',
                'taxCode' => 'txcd_test0',
            ],
        ]);
    }

    private function seed(int $count = 1, ?int $refreshedAt = null, ?string $secretKey = null): void
    {
        $items = [];

        for ($index = 0; $index < $count; $index++) {
            $items[] = [
                'id' => 'txcd_test' . $index,
                'name' => 'Category ' . $index,
                'description' => 'Description',
                'requiresPerformanceLocation' => false,
            ];
        }

        $stripe = new StripeConfiguration(secretKey: $secretKey, publishableKey: null, webhookSecret: null);
        $this->kirby->cache(self::PREFIX . '.taxCodes')->set($secretKey === null ? 'unconfigured' : $stripe->secretKeyFingerprint('tax-codes'), [
            'items' => $items,
            'refreshedAt' => $refreshedAt ?? time(),
            'failedAt' => null,
        ]);
    }
}
