<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use DateTimeImmutable;
use Kirby\Panel\Panel;
use Kirby\Uuid\Uuid;
use Kirby\Uuid\Uuids;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticCheck;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticReport;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticStatus;
use ProgrammatorDev\StripeCheckout\Diagnostics\LocalDiagnostics;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingRunOutcome;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingRunStatus;
use ProgrammatorDev\StripeCheckout\Housekeeping\Internal\HousekeepingState;
use ProgrammatorDev\StripeCheckout\Housekeeping\Internal\HousekeepingStateStore;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Lifecycle\Internal\HookDeliveryLedger;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEventType;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Panel\DiagnosticsSection;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\CheckoutAttemptFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\OrderFixture;
use ProgrammatorDev\StripeCheckout\Test\Support\TestWorkspace;

final class LocalDiagnosticsTest extends KirbyTestCase
{
    public function testRendersHealthChecksWithoutSettingsSummary(): void
    {
        $response = DiagnosticsSection::response($this->kirby);
        $sections = array_column($response['sections'], null, 'name');
        $attention = array_column($sections['attention']['items'], null, 'id');
        $system = array_column($sections['system']['items'], null, 'id');

        $this->assertSame('Overview', $response['summary']['headline']);
        $this->assertCount(4, $response['summary']['reports']);
        $summary = array_column($response['summary']['reports'], null, 'value');
        $this->assertSame('Needs attention (2 issues)', $summary['Checkout setup']['label']);
        $this->assertSame('Not checked', $summary['Stripe account']['label']);
        $this->assertSame('Needs attention (1 issue)', $summary['Webhooks']['label']);
        $this->assertSame('Needs attention (1 issue)', $summary['Operations']['label']);

        foreach ($summary as $summaryReport) {
            $this->assertNull($summaryReport['info']);
        }

        $this->assertSame('Needs attention', $sections['attention']['headline']);
        $this->assertSame('Operations', $sections['operations']['headline']);
        $this->assertSame('System', $sections['system']['headline']);
        $this->assertSame('Environment', $sections['environment']['headline']);
        $this->assertSame('Webhook route', $system['webhookRoute']['text']);
        $this->assertSame('passive', $system['webhookRoute']['theme']);
        $this->assertSame('Ready', $system['webhookRoute']['info']);
        $this->assertSame('Storage write access', $system['storageWriteAccess']['text']);
        $this->assertSame([[
            'icon' => 'open',
            'link' => 'https://dashboard.stripe.com/apikeys',
            'rel' => 'noopener noreferrer',
            'target' => '_blank',
            'title' => 'Open Stripe API keys',
            'variant' => 'filled',
        ]], $attention['secretKey']['buttons']);
        $this->assertSame([[
            'icon' => 'open',
            'link' => 'https://dashboard.stripe.com/webhooks',
            'rel' => 'noopener noreferrer',
            'target' => '_blank',
            'title' => 'Open Stripe Webhooks',
            'variant' => 'filled',
        ]], $attention['webhookSecret']['buttons']);
        $this->assertSame([[
            'icon' => 'settings',
            'link' => Panel::url('stripe-checkout') . '?tab=settings',
            'title' => 'Open Settings',
            'variant' => 'filled',
        ]], $attention['currency']['buttons']);
        $this->assertSame('Last housekeeping run', $attention['housekeepingRun']['text']);
        $this->assertSame(
            'Configure the housekeeping command in cron, or run it manually now',
            $attention['housekeepingRun']['info'],
        );
        $stripe = array_column($sections['stripe']['items'], null, 'id');
        $this->assertSame('Last Stripe check', $stripe['remote-summary']['text']);
        $this->assertSame('Not run yet', $stripe['remote-summary']['info']);
        $this->assertSame('clock', $stripe['remote-summary']['image']['icon']);
        $this->assertSame('info', $stripe['remote-summary']['theme']);
        $this->assertArrayNotHasKey('configuration', $sections);
    }

    public function testRendersTheLatestSanitizedRemoteReportWithoutRunningIt(): void
    {
        $report = new DiagnosticReport(
            checkedAt: new DateTimeImmutable('2026-11-15T12:00:00Z'),
            checks: [new DiagnosticCheck(
                code: 'stripeAccount',
                status: DiagnosticStatus::Pass,
                messageKey: 'stripeAccount.ready',
                context: [
                    'configuredMode' => 'test',
                    'providerMode' => 'test',
                ],
            )],
        );
        (new RuntimeFactory($this->kirby))->diagnosticReportStore()->write($report);

        $response = DiagnosticsSection::response($this->kirby);
        $sections = array_column($response['sections'], null, 'name');
        $items = array_column($sections['stripe']['items'], null, 'id');
        $summary = array_column($response['summary']['reports'], null, 'value');

        $this->assertSame(
            '2026-11-15 12:00 UTC',
            $items['remote-summary']['info'],
        );
        $this->assertSame(
            'Connected in test mode',
            $items['remote-stripeAccount']['info'],
        );
        $this->assertSame(
            'Ready',
            $summary['Stripe account']['label'],
        );
    }

    public function testPresentsTheLatestSuccessfulRunsConsistently(): void
    {
        $attemptedAt = new DateTimeImmutable('2026-11-15T12:55:00Z');
        $completedAt = $attemptedAt->modify('+1 minute');
        $outcome = new HousekeepingRunOutcome(
            attemptedAt: $attemptedAt,
            completedAt: $completedAt,
            status: HousekeepingRunStatus::Success,
            selectedOrders: 0,
            processedOrders: 0,
            prunedDeliveries: 0,
            deletedOrders: 0,
            recoveredOrders: 0,
            failedOrders: 0,
            errorCode: null,
        );
        (new HousekeepingStateStore($this->kirby))->write(
            (new HousekeepingState())->withOutcome($outcome),
        );

        $sections = array_column(DiagnosticsSection::response($this->kirby)['sections'], null, 'name');
        $operations = $sections['operations']['items'];

        $this->assertSame('housekeepingRun', $operations[0]['id']);
        $this->assertSame('Last housekeeping run', $operations[0]['text']);
        $this->assertSame('2026-11-15 12:56 UTC', $operations[0]['info']);
        $this->assertSame('clock', $operations[0]['image']['icon']);
        $this->assertSame('info', $operations[0]['theme']);
    }

    public function testPreservesAStableRemoteFailureCodeWithoutATranslation(): void
    {
        $report = new DiagnosticReport(
            checkedAt: new DateTimeImmutable('2026-11-15T12:00:00Z'),
            checks: [new DiagnosticCheck(
                code: 'stripeAccount',
                status: DiagnosticStatus::Fail,
                messageKey: 'remote.authenticationFailed',
                context: ['code' => 'diagnostics.authentication_failed'],
            )],
        );
        (new RuntimeFactory($this->kirby))->diagnosticReportStore()->write($report);

        $response = DiagnosticsSection::response($this->kirby);
        $sections = array_column($response['sections'], null, 'name');
        $stripeItems = array_column($sections['stripe']['items'], null, 'id');
        $attentionItems = array_column($sections['attention']['items'], null, 'id');

        $this->assertSame(
            '2026-11-15 12:00 UTC',
            $stripeItems['remote-summary']['info'],
        );
        $this->assertSame(
            'Stripe rejected the configured server key (diagnostics.authentication_failed)',
            $attentionItems['remote-stripeAccount']['info'],
        );
        $this->assertSame([[
            'icon' => 'open',
            'link' => 'https://dashboard.stripe.com/apikeys',
            'rel' => 'noopener noreferrer',
            'target' => '_blank',
            'title' => 'Open Stripe API keys',
            'variant' => 'filled',
        ]], $attentionItems['remote-stripeAccount']['buttons']);
    }

    public function testPresentsCompactWebhookWarningWithConfigurationAction(): void
    {
        $url = 'https://kirby-stripe-checkout.test/stripe-checkout/webhook';
        $report = new DiagnosticReport(
            checkedAt: new DateTimeImmutable('2026-11-15T12:00:00Z'),
            checks: [new DiagnosticCheck(
                code: 'stripeWebhook',
                status: DiagnosticStatus::Warning,
                messageKey: 'stripeWebhook.missing',
                context: [
                    'mode' => 'test',
                    'url' => $url,
                ],
            )],
        );
        (new RuntimeFactory($this->kirby))->diagnosticReportStore()->write($report);

        $sections = array_column(DiagnosticsSection::response($this->kirby)['sections'], null, 'name');
        $attention = array_column($sections['attention']['items'], null, 'id');
        $this->assertSame('Missing in test mode', $attention['remote-stripeWebhook']['info']);
        $this->assertSame([[
            'icon' => 'open',
            'link' => 'https://dashboard.stripe.com/webhooks',
            'rel' => 'noopener noreferrer',
            'target' => '_blank',
            'title' => 'Open Stripe Webhooks',
            'variant' => 'filled',
        ]], $attention['remote-stripeWebhook']['buttons']);
    }

    public function testReportsDependenciesIncompleteCredentialsAndInitializedSettingsLocally(): void
    {
        $report = (new LocalDiagnostics($this->kirby))->report();
        $checks = $this->checks($report);

        $this->assertSame(DiagnosticStatus::Warning, $report->status());
        $this->assertSame(DiagnosticStatus::Pass, $checks['php']->status());
        $this->assertSame(DiagnosticStatus::Pass, $checks['kirby']->status());
        $this->assertSame(DiagnosticStatus::Pass, $checks['stripePhp']->status());
        $this->assertSame(DiagnosticStatus::Pass, $checks['intl']->status());
        $this->assertSame(DiagnosticStatus::Pass, $checks['orderIdentity']->status());
        $this->assertSame(DiagnosticStatus::Pass, $checks['webhookRoute']->status());
        $this->assertSame(DiagnosticStatus::Pass, $checks['storageWriteAccess']->status());
        $this->assertSame(DiagnosticStatus::Pass, $checks['configuration']->status());
        $this->assertSame(DiagnosticStatus::Warning, $checks['secretKey']->status());
        $this->assertSame(
            ['checkout', 'webhook', 'order_sync', 'housekeeping', 'remote_diagnostics'],
            $checks['secretKey']->affectedOperations(),
        );
        $this->assertSame(DiagnosticStatus::Pass, $checks['publishableKey']->status());
        $this->assertSame(DiagnosticStatus::Warning, $checks['webhookSecret']->status());
        $this->assertSame(DiagnosticStatus::Warning, $checks['currency']->status());
        $this->assertArrayNotHasKey('defaultRequiresShipping', $checks);
        $this->assertSame(DiagnosticStatus::Pass, $checks['hubPage']->status());
        $this->assertSame(DiagnosticStatus::Pass, $checks['events']->status());
        $this->assertArrayNotHasKey('pendingPayments', $checks);
        $this->assertArrayNotHasKey('settingSources', $checks);
        $this->assertArrayNotHasKey('shippingResolver', $checks);
        $this->assertArrayNotHasKey('housekeeping', $checks);
        $this->assertArrayNotHasKey('retention', $checks);
        $this->assertArrayNotHasKey('housekeepingEligibility', $checks);
    }

    public function testReportsMissingPublishableKeyOnlyForEmbeddedCheckout(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout.settings.uiMode' => 'embedded',
        ]);
        $this->kirby = $this->environment->app();
        $checks = $this->checks((new LocalDiagnostics($this->kirby))->report());

        $this->assertSame(DiagnosticStatus::Warning, $checks['publishableKey']->status());
    }

    public function testReportsCredentialPresenceAndModeWithoutTheirValues(): void
    {
        $secret = 'sk_test_diagnostic-private-value';
        $publishable = 'pk_test_diagnostic-public-value';
        $webhook = 'whsec_diagnostic-webhook-value';
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout' => [
                'stripe' => [
                    'secretKey' => $secret,
                    'publishableKey' => $publishable,
                    'webhookSecret' => $webhook,
                ],
            ],
        ]);
        $this->kirby = $this->environment->app();

        $report = (new LocalDiagnostics($this->kirby))->report();
        $encoded = json_encode($report->toArray(), JSON_THROW_ON_ERROR);
        $checks = $this->checks($report);

        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString($publishable, $encoded);
        $this->assertStringNotContainsString($webhook, $encoded);
        $this->assertSame('test', $checks['secretKey']->context()['mode']);
        $this->assertSame('test', $checks['publishableKey']->context()['mode']);
        $this->assertSame([], $checks['webhookSecret']->context());
    }

    public function testInvalidConfigurationRemainsInspectable(): void
    {
        $secret = 'sk_test_diagnostic-private-value';
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout' => [
                'stripe' => ['secretKey' => $secret],
                'settings' => ['unknown' => true],
            ],
        ]);
        $this->kirby = $this->environment->app();

        $report = (new LocalDiagnostics($this->kirby))->report();
        $encoded = json_encode($report->toArray(), JSON_THROW_ON_ERROR);
        $checks = $this->checks($report);

        $this->assertSame(DiagnosticStatus::Fail, $report->status());
        $this->assertSame(DiagnosticStatus::Fail, $checks['configuration']->status());
        $this->assertSame('configuration.option_unknown', $checks['configuration']->context()['code']);
        $this->assertSame(DiagnosticStatus::NotVerifiable, $checks['secretKey']->status());
        $this->assertSame(DiagnosticStatus::NotVerifiable, $checks['currency']->status());
        $this->assertArrayNotHasKey('defaultRequiresShipping', $checks);
        $this->assertStringNotContainsString($secret, $encoded);

        $sections = array_column(DiagnosticsSection::response($this->kirby)['sections'], null, 'name');
        $attention = array_column($sections['attention']['items'], null, 'id');
        $this->assertSame([], $attention['configuration']['buttons']);
    }

    public function testReportsConfiguredCommerceDefaultsLocally(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout' => [
                'settings' => [
                    'currency' => 'EUR',
                    'defaultRequiresShipping' => false,
                ],
            ],
        ]);
        $this->kirby = $this->environment->app();
        $checks = $this->checks((new LocalDiagnostics($this->kirby))->report());
        $sections = array_column(DiagnosticsSection::response($this->kirby)['sections'], null, 'name');
        $visibleIds = [];

        foreach ($sections as $section) {
            $visibleIds = [...$visibleIds, ...array_column($section['items'], 'id')];
        }

        $this->assertSame(DiagnosticStatus::Pass, $checks['currency']->status());
        $this->assertArrayNotHasKey('defaultRequiresShipping', $checks);
        $this->assertNotContains('currency', $visibleIds);
        $this->assertNotContains('defaultRequiresShipping', $visibleIds);
    }

    public function testDoesNotPresentAnUnloadedTaxCodeCatalogueAsAnIssue(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout' => [
                'stripe' => ['secretKey' => 'sk_test_tax_diagnostics'],
                'settings' => [
                    'automaticTax' => true,
                    'currency' => 'EUR',
                ],
            ],
        ]);
        $this->kirby = $this->environment->app();

        $report = (new LocalDiagnostics($this->kirby))->report();
        $check = $report->check('taxCodes');
        $sections = array_column(DiagnosticsSection::response($this->kirby)['sections'], null, 'name');
        $visibleIds = array_merge(...array_map(
            static fn(array $section): array => array_column($section['items'], 'id'),
            array_values($sections),
        ));

        $this->assertNotNull($check);
        $this->assertSame(DiagnosticStatus::Pass, $check->status());
        $this->assertSame('taxCodes.empty', $check->messageKey());
        $this->assertNotContains('taxCodes', $visibleIds);
    }

    public function testDoesNotPresentAnUnloadedStripePriceCatalogueAsAnIssue(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout' => [
                'stripe' => ['secretKey' => 'sk_test_price_diagnostics'],
                'settings' => [
                    'currency' => 'EUR',
                    'priceSource' => 'stripe',
                ],
            ],
        ]);
        $this->kirby = $this->environment->app();

        $report = (new LocalDiagnostics($this->kirby))->report();
        $check = $report->check('prices');
        $sections = array_column(DiagnosticsSection::response($this->kirby)['sections'], null, 'name');
        $visibleIds = array_merge(...array_map(
            static fn(array $section): array => array_column($section['items'], 'id'),
            array_values($sections),
        ));

        $this->assertNotNull($check);
        $this->assertSame(DiagnosticStatus::Pass, $check->status());
        $this->assertSame('prices.empty', $check->messageKey());
        $this->assertNotContains('prices', $visibleIds);
    }

    public function testFailedTaxCodeRefreshProvidesAnExplicitRecoveryAction(): void
    {
        $secretKey = 'sk_test_failed_tax_diagnostics';
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout' => [
                'stripe' => ['secretKey' => $secretKey],
                'settings' => [
                    'automaticTax' => true,
                    'currency' => 'EUR',
                ],
            ],
        ]);
        $this->kirby = $this->environment->app();
        $runtime = new RuntimeFactory($this->kirby);
        $stripe = $runtime->configurationReport()->configurationOrFail()->stripe();
        $this->kirby->cache('programmatordev.stripe-checkout.taxCodes')->set(
            $stripe->secretKeyFingerprint('tax-codes'),
            [
                'failedAt' => 1_763_208_000,
                'items' => [],
                'refreshedAt' => null,
            ],
        );

        $check = (new LocalDiagnostics($this->kirby))->report()->check('taxCodes');
        $sections = array_column(DiagnosticsSection::response($this->kirby)['sections'], null, 'name');
        $attention = array_column($sections['attention']['items'], null, 'id');

        $this->assertNotNull($check);
        $this->assertSame(DiagnosticStatus::Warning, $check->status());
        $this->assertSame('taxCodes.failed', $check->messageKey());
        $this->assertSame('Refresh failed 2025-11-15 12:00 UTC; using 0 cached from —', $attention['taxCodes']['info']);
        $this->assertSame([[
            'endpoint' => 'stripe-checkout/diagnostics/tax-codes/refresh',
            'icon' => 'refresh',
            'successMessage' => 'Tax Codes refreshed.',
            'title' => 'Refresh Tax Codes',
            'variant' => 'filled',
        ]], $attention['taxCodes']['buttons']);
    }

    public function testReportsPageSlugSettingsThatWouldChangeOrderUuids(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'content.uuid' => 'uuid-v4',
            'slugs.maxlength' => 16,
        ]);
        $this->kirby = $this->environment->app();
        $report = (new LocalDiagnostics($this->kirby))->report();
        $checks = $this->checks($report);

        $this->assertSame(DiagnosticStatus::Fail, $report->status());
        $this->assertSame(DiagnosticStatus::Fail, $checks['orderIdentity']->status());
        $this->assertSame('orderIdentity.incompatible', $checks['orderIdentity']->messageKey());
    }

    public function testReportsDisabledContentUuids(): void
    {
        $previousUuidState = Uuids::$enabled;
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: ['content.uuid' => false]);
        $this->kirby = $this->environment->app();

        try {
            $report = (new LocalDiagnostics($this->kirby))->report();
            $checks = $this->checks($report);

            $this->assertSame(DiagnosticStatus::Fail, $report->status());
            $this->assertSame(DiagnosticStatus::Fail, $checks['orderIdentity']->status());
            $this->assertSame('orderIdentity.disabled', $checks['orderIdentity']->messageKey());
        } finally {
            Uuids::$enabled = $previousUuidState;
        }
    }

    public function testDoesNotInvokeACustomUuidGeneratorDuringDiagnostics(): void
    {
        $calls = 0;
        $previousGenerator = Uuid::$generator;

        try {
            Uuid::$generator = static function (int $length) use (&$calls): string {
                $calls++;

                return 'custom-order-identity';
            };
            $checks = $this->checks((new LocalDiagnostics($this->kirby))->report());

            $this->assertSame(0, $calls);
            $this->assertSame(DiagnosticStatus::NotVerifiable, $checks['orderIdentity']->status());
        } finally {
            Uuid::$generator = $previousGenerator;
        }
    }

    public function testReportsSettingsOwnershipProblemsWithoutThrowing(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            beforeApp: static function (TestWorkspace $workspace): void {
                $workspace->writeDraftPage(
                    'stripe-checkout',
                    'default',
                    ['title' => 'Unrelated page'],
                );
            },
        );
        $this->kirby = $this->environment->app();

        $report = (new LocalDiagnostics($this->kirby))->report();
        $checks = $this->checks($report);

        $this->assertSame(DiagnosticStatus::Fail, $report->status());
        $this->assertSame(DiagnosticStatus::Fail, $checks['hubPage']->status());
        $this->assertSame('persistence.model_mismatch', $checks['hubPage']->context()['code']);
        $this->assertSame('stripe-checkout', $checks['hubPage']->context()['path']);
    }

    public function testReportsFailedEvents(): void
    {
        $checkedAt = new DateTimeImmutable('2026-10-24T12:00:00Z');
        $createdAt = $checkedAt->modify('-8 days');
        $context = new OrderCreationContext(
            uuid: 'diagnostic-order',
            orderNumber: 'ORDER-DIAGNOSTIC',
            checkoutSource: CheckoutSource::Direct,
            cartRevision: null,
            userUuid: null,
            languageCode: null,
            uiMode: UiMode::Hosted,
            currency: 'EUR',
            lineItems: [OrderFixture::lineItem()],
        );
        $store = new OrderPageStore($this->kirby);
        $store->create(
            context: $context,
            checkoutAttempt: CheckoutAttemptFactory::create($context, $createdAt),
            createdAt: $createdAt,
        );
        $store->update($context->pageUuid(), static function (array $data) use ($createdAt): array {
            $data['events'] = [[
                'id' => 'evt_diagnostic',
                'type' => 'checkout.session.completed',
                'createdAt' => $createdAt->getTimestamp(),
                'resourceId' => 'cs_diagnostic',
                'status' => 'failed',
                'attempts' => 1,
                'lastAttemptAt' => OrderData::timestamp($createdAt),
                'errorCode' => CheckoutErrorCode::SESSION_UNAVAILABLE,
            ]];

            return $data;
        });

        $checks = $this->checks((new LocalDiagnostics($this->kirby, $checkedAt))->report());

        $this->assertSame(DiagnosticStatus::Warning, $checks['events']->status());
        $this->assertSame('events.failed', $checks['events']->messageKey());
        $this->assertSame('0', $checks['events']->context()['pending']);
        $this->assertSame('1', $checks['events']->context()['failed']);
        $sections = array_column(DiagnosticsSection::response($this->kirby)['sections'], null, 'name');
        $attention = array_column($sections['attention']['items'], null, 'id');
        $this->assertSame([[
            'icon' => 'cart',
            'link' => Panel::url('stripe-checkout') . '?tab=orders&checkoutStatus=&payment=&attention=failed_events',
            'title' => 'View affected orders',
            'variant' => 'filled',
        ]], $attention['events']['buttons']);
    }

    public function testPendingOperationalRecordsRequireAttentionWithoutFailedOnlyLinks(): void
    {
        $createdAt = new DateTimeImmutable('2026-10-24T12:00:00Z');
        $context = new OrderCreationContext(
            uuid: 'pending-diagnostic-order',
            orderNumber: 'ORDER-PENDING-DIAGNOSTIC',
            checkoutSource: CheckoutSource::Direct,
            cartRevision: null,
            userUuid: null,
            languageCode: null,
            uiMode: UiMode::Hosted,
            currency: 'EUR',
            lineItems: [OrderFixture::lineItem()],
        );
        $store = new OrderPageStore($this->kirby);
        $store->create(
            context: $context,
            checkoutAttempt: CheckoutAttemptFactory::create($context, $createdAt),
            createdAt: $createdAt,
        );
        $store->update($context->pageUuid(), static function (array $data) use ($createdAt): array {
            $deliveries = array_map(
                OrderData::map(...),
                OrderData::list($data['lifecycleDeliveries'] ?? []),
            );
            $deliveries[] = HookDeliveryLedger::pending(
                HookDeliveryLedger::event(
                    $data,
                    [],
                    LifecycleEventType::OrderCreated,
                    HookDeliveryLedger::nextRevision($deliveries),
                ),
                30,
                $createdAt,
            );
            $data['lifecycleDeliveries'] = $deliveries;
            $data['events'] = [[
                'id' => 'evt_pending_diagnostic',
                'type' => 'checkout.session.completed',
                'createdAt' => $createdAt->getTimestamp(),
                'resourceId' => 'cs_pending_diagnostic',
                'status' => 'pending',
                'attempts' => 1,
                'lastAttemptAt' => OrderData::timestamp($createdAt),
                'errorCode' => null,
            ]];

            return $data;
        });

        $report = (new LocalDiagnostics($this->kirby, $createdAt))->report();
        $eventCheck = $report->check('events');
        $lifecycleCheck = $report->check('lifecycle');

        $this->assertNotNull($eventCheck);
        $this->assertSame(DiagnosticStatus::Warning, $eventCheck->status());
        $this->assertSame('events.summary', $eventCheck->messageKey());
        $this->assertSame('1', $eventCheck->context()['pending']);
        $this->assertSame('0', $eventCheck->context()['failed']);
        $this->assertNotNull($lifecycleCheck);
        $this->assertSame(DiagnosticStatus::Warning, $lifecycleCheck->status());
        $this->assertSame('lifecycle.summary', $lifecycleCheck->messageKey());
        $this->assertSame('1', $lifecycleCheck->context()['pending']);
        $this->assertSame('0', $lifecycleCheck->context()['failed']);

        $sections = array_column(DiagnosticsSection::response($this->kirby)['sections'], null, 'name');
        $attention = array_column($sections['attention']['items'], null, 'id');
        $this->assertSame([], $attention['events']['buttons']);
        $this->assertSame([], $attention['lifecycle']['buttons']);
    }

    public function testReportsInvalidChildrenWhileSummarizingValidOrders(): void
    {
        $checkedAt = new DateTimeImmutable('2026-10-24T12:00:00Z');
        $createdAt = $checkedAt->modify('-8 days');
        $context = new OrderCreationContext(
            uuid: 'valid-diagnostic-order',
            orderNumber: 'ORDER-VALID-DIAGNOSTIC',
            checkoutSource: CheckoutSource::Direct,
            cartRevision: null,
            userUuid: null,
            languageCode: null,
            uiMode: UiMode::Hosted,
            currency: 'EUR',
            lineItems: [OrderFixture::lineItem()],
        );
        $store = new OrderPageStore($this->kirby);
        $store->create(
            context: $context,
            checkoutAttempt: CheckoutAttemptFactory::create($context, $createdAt),
            createdAt: $createdAt,
        );
        $this->environment->workspace()->writeDraftPage(
            'stripe-checkout-orders/invalid-diagnostic-order',
            'default',
            ['title' => 'Invalid diagnostic order'],
        );

        $checks = $this->checks((new LocalDiagnostics($this->kirby, $checkedAt))->report());

        $this->assertSame(DiagnosticStatus::Fail, $checks['orders']->status());
        $this->assertSame('1', $checks['orders']->context()['count']);
    }

    /** @return array<string, DiagnosticCheck> */
    private function checks(DiagnosticReport $report): array
    {
        $checks = [];

        foreach ($report->checks() as $check) {
            $checks[$check->code()] = $check;
        }

        return $checks;
    }
}
