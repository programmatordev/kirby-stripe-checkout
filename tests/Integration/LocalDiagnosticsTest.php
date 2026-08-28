<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Kirby\Cms\Page;
use ProgrammatorDev\StripeCheckout\Diagnostics\LocalDiagnostics;
use ProgrammatorDev\StripeCheckout\Kirby\SettingsPageStore;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;

final class LocalDiagnosticsTest extends KirbyTestCase
{
    public function testReportsDependenciesIncompleteCredentialsAndMissingSettingsLocally(): void
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
        $this->assertSame(LocalDiagnostics::WARNING, $checks['settingsPage']['status']);
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
        (new SettingsPageStore($this->kirby))->initialize();

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
        $this->kirby->impersonate(
            'kirby',
            fn(): Page => Page::create([
                'content' => ['title' => 'Unrelated page'],
                'isDraft' => true,
                'parent' => null,
                'site' => $this->kirby->site(),
                'slug' => 'stripe-checkout-settings',
                'template' => 'default',
            ]),
        );

        $report = (new LocalDiagnostics($this->kirby))->report();
        $checks = array_column($report['checks'], null, 'id');

        $this->assertSame(LocalDiagnostics::FAIL, $report['status']);
        $this->assertSame(LocalDiagnostics::FAIL, $checks['settingsPage']['status']);
        $this->assertSame('persistence.model_mismatch', $checks['settingsPage']['values']['code']);
        $this->assertSame('stripe-checkout-settings', $checks['settingsPage']['values']['path']);
    }
}
