<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use DateTimeImmutable;
use Kirby\Cache\MemoryCache;
use Kirby\Cache\NullCache;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticsErrorCode;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticStatus;
use ProgrammatorDev\StripeCheckout\Diagnostics\Exception\DiagnosticsException;
use ProgrammatorDev\StripeCheckout\Diagnostics\Internal\DiagnosticReportStore;
use ProgrammatorDev\StripeCheckout\Diagnostics\RemoteDiagnostics;
use ProgrammatorDev\StripeCheckout\Stripe\Diagnostics\WebhookEndpointRecord;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\Stripe\FakeDiagnosticsGateway;
use Stripe\Util\ApiVersion;

final class RemoteDiagnosticsTest extends KirbyTestCase
{
    private const WEBHOOK_URL = 'https://kirby-stripe-checkout.test/stripe-checkout/webhook';

    public function testCachesASanitizedPassingReportForOneWildcardEndpoint(): void
    {
        $gateway = new FakeDiagnosticsGateway();
        $gateway->webhookEndpoints = [new WebhookEndpointRecord(
            endpointId: 'we_test',
            url: 'HTTPS://KIRBY-STRIPE-CHECKOUT.TEST:443/stripe-checkout/webhook/',
            enabledEvents: ['*'],
            liveMode: false,
            apiVersion: ApiVersion::CURRENT,
            status: 'enabled',
        )];
        $diagnostics = $this->diagnostics($gateway, CredentialMode::Test);

        $report = $diagnostics->run(new DateTimeImmutable('2026-10-23T10:15:00Z'));
        $latest = $diagnostics->latest();

        $this->assertSame(DiagnosticStatus::Pass, $report->status());
        $this->assertNotNull($latest);
        $this->assertSame($report->toArray(), $latest->toArray());
        $encoded = json_encode($latest->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('we_test', $encoded);
    }

    public function testMissingLiveEndpointFailsWhileMissingTestEndpointWarns(): void
    {
        $gateway = new FakeDiagnosticsGateway();
        $gateway->accountMode = CredentialMode::Live;
        $liveReport = $this->diagnostics($gateway, CredentialMode::Live)->run();
        $liveCheck = $liveReport->check('stripeWebhook');

        $this->assertNotNull($liveCheck);
        $this->assertSame(DiagnosticStatus::Fail, $liveCheck->status());
        $this->assertSame(['webhook'], $liveCheck->affectedOperations());

        $gateway->accountMode = CredentialMode::Test;
        $testReport = $this->diagnostics($gateway, CredentialMode::Test)->run();
        $testCheck = $testReport->check('stripeWebhook');

        $this->assertNotNull($testCheck);
        $this->assertSame(DiagnosticStatus::Warning, $testCheck->status());
        $this->assertSame([], $testCheck->affectedOperations());
    }

    public function testDuplicateExactEndpointsWarnAndUnrelatedEndpointsAreIgnored(): void
    {
        $gateway = new FakeDiagnosticsGateway();
        $gateway->webhookEndpoints = [
            $this->endpoint('we_first'),
            $this->endpoint('we_second'),
            new WebhookEndpointRecord(
                endpointId: 'we_unrelated',
                url: 'https://kirby-stripe-checkout.test/other',
                enabledEvents: ['checkout.session.completed'],
                liveMode: false,
                apiVersion: null,
                status: 'disabled',
            ),
        ];
        $report = $this->diagnostics($gateway, CredentialMode::Test)->run();
        $check = $report->check('stripeWebhook');

        $this->assertNotNull($check);
        $this->assertSame(DiagnosticStatus::Warning, $check->status());
        $this->assertSame('stripeWebhook.duplicate', $check->messageKey());
        $this->assertSame('2', $check->context()['matches']);
    }

    public function testIncompleteLiveEndpointReportsMissingConfiguration(): void
    {
        $gateway = new FakeDiagnosticsGateway();
        $gateway->accountMode = CredentialMode::Live;
        $gateway->webhookEndpoints = [new WebhookEndpointRecord(
            endpointId: 'we_live',
            url: self::WEBHOOK_URL,
            enabledEvents: ['checkout.session.completed'],
            liveMode: true,
            apiVersion: null,
            status: 'enabled',
        )];
        $report = $this->diagnostics($gateway, CredentialMode::Live)->run();
        $check = $report->check('stripeWebhook');

        $this->assertNotNull($check);
        $this->assertSame(DiagnosticStatus::Fail, $check->status());
        $this->assertSame('stripeWebhook.incomplete', $check->messageKey());
        $this->assertSame('1', $check->context()['apiVersionMismatches']);
        $this->assertStringContainsString('refund.created', $check->context()['missingEvents']);
    }

    public function testDisabledLiveEndpointFailsConfiguration(): void
    {
        $gateway = new FakeDiagnosticsGateway();
        $gateway->accountMode = CredentialMode::Live;
        $gateway->webhookEndpoints = [new WebhookEndpointRecord(
            endpointId: 'we_disabled',
            url: self::WEBHOOK_URL,
            enabledEvents: ['*'],
            liveMode: true,
            apiVersion: ApiVersion::CURRENT,
            status: 'disabled',
        )];
        $report = $this->diagnostics($gateway, CredentialMode::Live)->run();
        $check = $report->check('stripeWebhook');

        $this->assertNotNull($check);
        $this->assertSame(DiagnosticStatus::Fail, $check->status());
        $this->assertSame('stripeWebhook.incomplete', $check->messageKey());
        $this->assertSame('1', $check->context()['disabled']);
        $this->assertSame(['webhook'], $check->affectedOperations());
    }

    public function testReportsConfiguredAndProviderAccountModeMismatch(): void
    {
        $gateway = new FakeDiagnosticsGateway();
        $gateway->accountMode = CredentialMode::Live;
        $gateway->webhookEndpoints = [new WebhookEndpointRecord(
            endpointId: 'we_live',
            url: self::WEBHOOK_URL,
            enabledEvents: ['*'],
            liveMode: true,
            apiVersion: ApiVersion::CURRENT,
            status: 'enabled',
        )];
        $report = $this->diagnostics($gateway, CredentialMode::Test)->run();
        $accountCheck = $report->check('stripeAccount');
        $webhookCheck = $report->check('stripeWebhook');

        $this->assertNotNull($accountCheck);
        $this->assertSame(DiagnosticStatus::Fail, $accountCheck->status());
        $this->assertSame('stripeAccount.modeMismatch', $accountCheck->messageKey());
        $this->assertSame('test', $accountCheck->context()['configuredMode']);
        $this->assertSame('live', $accountCheck->context()['providerMode']);
        $this->assertNotNull($webhookCheck);
        $this->assertSame(DiagnosticStatus::Pass, $webhookCheck->status());
    }

    public function testReportsWebhookEndpointModeMismatch(): void
    {
        $gateway = new FakeDiagnosticsGateway();
        $gateway->accountMode = CredentialMode::Test;
        $gateway->webhookEndpoints = [new WebhookEndpointRecord(
            endpointId: 'we_wrong_mode',
            url: self::WEBHOOK_URL,
            enabledEvents: ['*'],
            liveMode: true,
            apiVersion: ApiVersion::CURRENT,
            status: 'enabled',
        )];
        $report = $this->diagnostics($gateway, CredentialMode::Test)->run();
        $check = $report->check('stripeWebhook');

        $this->assertNotNull($check);
        $this->assertSame(DiagnosticStatus::Warning, $check->status());
        $this->assertSame('stripeWebhook.incomplete', $check->messageKey());
        $this->assertSame('1', $check->context()['modeMismatches']);
    }

    public function testRestrictedRemoteReadIsNotVerifiable(): void
    {
        $gateway = new FakeDiagnosticsGateway();
        $gateway->webhookFailure = new DiagnosticsException(DiagnosticsErrorCode::PERMISSION_DENIED);
        $report = $this->diagnostics($gateway, CredentialMode::Test)->run();
        $check = $report->check('stripeWebhook');

        $this->assertNotNull($check);
        $this->assertSame(DiagnosticStatus::NotVerifiable, $check->status());
        $this->assertSame('remote.permissionDenied', $check->messageKey());
    }

    public function testTreatsAnInvalidCachedReportAsMissing(): void
    {
        $cache = new MemoryCache();
        $cache->set('latest', ['status' => 'pass']);
        $store = new DiagnosticReportStore($cache);

        $this->assertNull($store->read());
    }

    public function testCompletesWhenDiagnosticCachingIsDisabled(): void
    {
        $diagnostics = new RemoteDiagnostics(
            gateway: new FakeDiagnosticsGateway(),
            store: new DiagnosticReportStore(new NullCache()),
            configuredMode: CredentialMode::Test,
            canonicalWebhookUrl: self::WEBHOOK_URL,
        );

        $report = $diagnostics->run();

        $this->assertNotNull($report->check('stripeAccount'));
        $this->assertNull($diagnostics->latest());
    }

    public function testCompletesWhenDiagnosticCacheIsUnavailable(): void
    {
        $cache = new class extends NullCache {
            public function get(string $key, mixed $default = null): mixed
            {
                throw new \RuntimeException('Cache unavailable.');
            }

            public function set(string $key, mixed $value, int $minutes = 0): bool
            {
                throw new \RuntimeException('Cache unavailable.');
            }
        };
        $diagnostics = new RemoteDiagnostics(
            gateway: new FakeDiagnosticsGateway(),
            store: new DiagnosticReportStore($cache),
            configuredMode: CredentialMode::Test,
            canonicalWebhookUrl: self::WEBHOOK_URL,
        );

        $report = $diagnostics->run();

        $this->assertNotNull($report->check('stripeAccount'));
        $this->assertNull($diagnostics->latest());
    }

    private function diagnostics(FakeDiagnosticsGateway $gateway, CredentialMode $configuredMode): RemoteDiagnostics
    {
        return new RemoteDiagnostics(
            gateway: $gateway,
            store: new DiagnosticReportStore(
                $this->kirby->cache('programmatordev.stripe-checkout.diagnostics'),
            ),
            configuredMode: $configuredMode,
            canonicalWebhookUrl: self::WEBHOOK_URL,
        );
    }

    private function endpoint(string $endpointId): WebhookEndpointRecord
    {
        return new WebhookEndpointRecord(
            endpointId: $endpointId,
            url: self::WEBHOOK_URL,
            enabledEvents: ['*'],
            liveMode: false,
            apiVersion: ApiVersion::CURRENT,
            status: 'enabled',
        );
    }
}
