<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use DateTimeImmutable;
use Kirby\Uuid\Uuid;
use Kirby\Uuid\Uuids;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticCheck;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticReport;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticStatus;
use ProgrammatorDev\StripeCheckout\Diagnostics\LocalDiagnostics;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Panel\DiagnosticsSections;
use ProgrammatorDev\StripeCheckout\Test\Support\CheckoutAttemptFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\OrderFixture;
use ProgrammatorDev\StripeCheckout\Test\Support\TestWorkspace;

final class LocalDiagnosticsTest extends KirbyTestCase
{
    public function testRendersCurrentHousekeepingConfiguration(): void
    {
        $sections = DiagnosticsSections::build($this->kirby);

        $this->assertSame(
            'PHP configuration: at most 25 candidates per run; lifecycle snapshot retention 30 days. Configure the CLI command in cron or run it manually.',
            $sections['diagnostics-housekeeping']['text'],
        );
        $this->assertSame(
            'Housekeeping has not run yet. The plugin cannot detect whether the host cron entry exists.',
            $sections['diagnostics-housekeepingRun']['text'],
        );
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
        $this->assertSame(DiagnosticStatus::Warning, $checks['defaultRequiresShipping']->status());
        $this->assertSame('shippingResolver.zones', $checks['shippingResolver']->messageKey());
        $this->assertSame(DiagnosticStatus::Pass, $checks['settingSources']->status());
        $this->assertSame(DiagnosticStatus::Pass, $checks['hubPage']->status());
        $this->assertSame(DiagnosticStatus::Pass, $checks['events']->status());
        $this->assertSame(DiagnosticStatus::Pass, $checks['pendingPayments']->status());
        $this->assertSame(DiagnosticStatus::Pass, $checks['housekeepingEligibility']->status());
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

        $encoded = json_encode((new LocalDiagnostics($this->kirby))->report()->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString($publishable, $encoded);
        $this->assertStringNotContainsString($webhook, $encoded);
        $this->assertStringContainsString('"mode":"test"', $encoded);
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
        $this->assertSame(DiagnosticStatus::NotVerifiable, $checks['defaultRequiresShipping']->status());
        $this->assertStringNotContainsString($secret, $encoded);
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

        $this->assertSame(DiagnosticStatus::Pass, $checks['currency']->status());
        $this->assertSame(DiagnosticStatus::Pass, $checks['defaultRequiresShipping']->status());
    }

    public function testReportsTheCustomShippingResolverWithoutCallingIt(): void
    {
        $called = false;
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout.shipping.resolver' => static function () use (&$called): never {
                $called = true;

                throw new \LogicException('Not called.');
            },
        ]);
        $this->kirby = $this->environment->app();
        $checks = $this->checks((new LocalDiagnostics($this->kirby))->report());

        $this->assertFalse($called);
        $this->assertSame(DiagnosticStatus::Pass, $checks['shippingResolver']->status());
        $this->assertSame('shippingResolver.custom', $checks['shippingResolver']->messageKey());
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

    public function testReportsFailedEventsAndIncompleteOrdersDueForRecovery(): void
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
        $this->assertSame('0', $checks['events']->context()['pending']);
        $this->assertSame('1', $checks['events']->context()['failed']);
        $this->assertSame(DiagnosticStatus::Warning, $checks['housekeepingEligibility']->status());
        $this->assertSame('0', $checks['housekeepingEligibility']->context()['eligible']);
        $this->assertSame('1', $checks['housekeepingEligibility']->context()['recoveryDue']);
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
