<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Diagnostics;

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Kirby\SettingsPageStore;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use Stripe\Stripe;

/**
 * Reports only local, non-sensitive integration facts without Stripe traffic.
 *
 * @internal
 */
final class LocalDiagnostics
{
    public const FAIL = 'fail';
    public const PASS = 'pass';
    public const UNKNOWN = 'unknown';
    public const WARNING = 'warning';

    public function __construct(
        private readonly App $kirby,
    ) {}

    /**
     * @return array{
     *   status: string,
     *   checks: list<array{id: string, status: string, message: string, values: array<string, string>}>
     * }
     */
    public function report(): array
    {
        $checks = [
            $this->dependency('php', PHP_VERSION, version_compare(PHP_VERSION, '8.2.0', '>=')),
            $this->dependency('kirby', App::version(), version_compare((string) App::version(), '5.5.3', '>=')),
            $this->dependency('stripePhp', defined(Stripe::class . '::VERSION') ? Stripe::VERSION : null, class_exists(Stripe::class)),
        ];

        $configuration = (new RuntimeFactory($this->kirby))->configurationReport();

        if ($configuration->isValid() === false) {
            $error = $configuration->error();
            $checks[] = $this->failure('configuration', $error);

            foreach (['secretKey', 'publishableKey', 'webhookSecret'] as $id) {
                $checks[] = $this->check($id, self::UNKNOWN, 'credential.unknown');
            }
        } else {
            $checks[] = $this->check('configuration', self::PASS, 'configuration.ready');
            $stripe = $configuration->configurationOrFail()->stripe();
            $checks[] = $this->credential('secretKey', $stripe->hasSecretKey(), $stripe->serverMode());
            $checks[] = $this->credential('publishableKey', $stripe->hasPublishableKey(), $stripe->publishableMode());
            $checks[] = $this->credential('webhookSecret', $stripe->hasWebhookSecret(), CredentialMode::Unknown);
        }

        try {
            $settingsPage = (new SettingsPageStore($this->kirby))->page();
            $checks[] = $settingsPage === null
                ? $this->check('settingsPage', self::WARNING, 'settings.missing')
                : $this->check('settingsPage', self::PASS, 'settings.ready');
        } catch (ConfigurationException $error) {
            $checks[] = $this->failure('settingsPage', $error, 'settings.invalid');
        }

        return [
            'status' => $this->overallStatus($checks),
            'checks' => $checks,
        ];
    }

    /** @return array{id: string, status: string, message: string, values: array<string, string>} */
    private function dependency(string $id, ?string $version, bool $available): array
    {
        return $available === true && $version !== null
            ? $this->check($id, self::PASS, 'dependency.ready', ['version' => $version])
            : $this->check($id, self::FAIL, 'dependency.missing');
    }

    /** @return array{id: string, status: string, message: string, values: array<string, string>} */
    private function credential(string $id, bool $configured, CredentialMode $mode): array
    {
        if ($configured === false) {
            return $this->check($id, self::WARNING, 'credential.missing');
        }

        return $this->check(
            $id,
            self::PASS,
            'credential.configured',
            ['mode' => $mode->value],
        );
    }

    /** @return array{id: string, status: string, message: string, values: array<string, string>} */
    private function failure(
        string $id,
        ?ConfigurationException $error,
        string $message = 'configuration.invalid',
    ): array {
        return $this->check($id, self::FAIL, $message, [
            'code' => $error?->errorCode() ?? 'configuration.root_invalid',
            'path' => $error?->path() ?? 'programmatordev.stripe-checkout',
        ]);
    }

    /**
     * @param array<string, string> $values
     * @return array{id: string, status: string, message: string, values: array<string, string>}
     */
    private function check(
        string $id,
        string $status,
        string $message,
        array $values = [],
    ): array {
        return compact('id', 'status', 'message', 'values');
    }

    /**
     * @param list<array{status: string}> $checks
     */
    private function overallStatus(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        return match (true) {
            in_array(self::FAIL, $statuses, true) => self::FAIL,
            in_array(self::WARNING, $statuses, true) => self::WARNING,
            in_array(self::UNKNOWN, $statuses, true) => self::UNKNOWN,
            default => self::PASS,
        };
    }
}
