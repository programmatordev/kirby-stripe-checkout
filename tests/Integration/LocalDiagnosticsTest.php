<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use ProgrammatorDev\StripeCheckout\Diagnostics\LocalDiagnostics;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\TestWorkspace;

final class LocalDiagnosticsTest extends KirbyTestCase
{
    public function testReportsDependenciesIncompleteCredentialsAndInitializedSettingsLocally(): void
    {
        $report = (new LocalDiagnostics($this->kirby))->report();
        $checks = array_column($report['checks'], null, 'id');

        $this->assertSame(LocalDiagnostics::WARNING, $report['status']);
        $this->assertSame(LocalDiagnostics::PASS, $checks['php']['status']);
        $this->assertSame(LocalDiagnostics::PASS, $checks['kirby']['status']);
        $this->assertSame(LocalDiagnostics::PASS, $checks['stripePhp']['status']);
        $this->assertSame(LocalDiagnostics::PASS, $checks['configuration']['status']);
        $this->assertSame(LocalDiagnostics::WARNING, $checks['secretKey']['status']);
        $this->assertSame(LocalDiagnostics::WARNING, $checks['publishableKey']['status']);
        $this->assertSame(LocalDiagnostics::WARNING, $checks['webhookSecret']['status']);
        $this->assertSame(LocalDiagnostics::PASS, $checks['hubPage']['status']);
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

        $encoded = json_encode((new LocalDiagnostics($this->kirby))->report(), JSON_THROW_ON_ERROR);

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
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        $checks = array_column($report['checks'], null, 'id');

        $this->assertSame(LocalDiagnostics::FAIL, $report['status']);
        $this->assertSame(LocalDiagnostics::FAIL, $checks['configuration']['status']);
        $this->assertSame('configuration.option_unknown', $checks['configuration']['values']['code']);
        $this->assertSame(LocalDiagnostics::UNKNOWN, $checks['secretKey']['status']);
        $this->assertStringNotContainsString($secret, $encoded);
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
        $checks = array_column($report['checks'], null, 'id');

        $this->assertSame(LocalDiagnostics::FAIL, $report['status']);
        $this->assertSame(LocalDiagnostics::FAIL, $checks['hubPage']['status']);
        $this->assertSame('persistence.model_mismatch', $checks['hubPage']['values']['code']);
        $this->assertSame('stripe-checkout', $checks['hubPage']['values']['path']);
    }
}
