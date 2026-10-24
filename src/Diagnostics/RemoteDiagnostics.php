<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Diagnostics;

use DateTimeImmutable;
use Kirby\Http\Url;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\ReconciliationEvent;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Diagnostics\Exception\DiagnosticsException;
use ProgrammatorDev\StripeCheckout\Diagnostics\Internal\DiagnosticReportStore;
use ProgrammatorDev\StripeCheckout\Stripe\Diagnostics\StripeDiagnosticsGatewayInterface;
use ProgrammatorDev\StripeCheckout\Stripe\Diagnostics\WebhookEndpointRecord;
use Stripe\Util\ApiVersion;

/** Runs explicit read-only Stripe checks and caches their sanitized result. */
final readonly class RemoteDiagnostics
{
    private const WEBHOOK_STATUS_ENABLED = 'enabled';

    public function __construct(
        private StripeDiagnosticsGatewayInterface $gateway,
        private DiagnosticReportStore $store,
        private CredentialMode $configuredMode,
        private string $canonicalWebhookUrl,
    ) {}

    public function run(?DateTimeImmutable $checkedAt = null): DiagnosticReport
    {
        $checkedAt ??= new DateTimeImmutable();
        $accountMode = null;
        $accountFailure = null;

        try {
            $accountMode = $this->gateway->accountMode();
            $accountCheck = $this->accountCheck($accountMode);
        } catch (DiagnosticsException $error) {
            $accountFailure = $error;
            $accountCheck = $this->remoteFailure('stripeAccount', $error);
        }

        // Stripe will reject every subsequent read made with the same unauthenticated credential.
        $webhookCheck = $accountFailure?->errorCode() === DiagnosticsErrorCode::AUTHENTICATION_FAILED
            ? $this->remoteFailure('stripeWebhook', $accountFailure)
            : $this->webhookCheck($accountMode);

        $report = new DiagnosticReport($checkedAt, [
            $accountCheck,
            $webhookCheck,
        ]);
        $this->store->write($report);

        return $report;
    }

    public function latest(): ?DiagnosticReport
    {
        return $this->store->read();
    }

    private function accountCheck(CredentialMode $accountMode): DiagnosticCheck
    {
        $context = [
            'configuredMode' => $this->configuredMode->value,
            'providerMode' => $accountMode->value,
        ];

        if ($this->configuredMode !== CredentialMode::Unknown && $accountMode !== $this->configuredMode) {
            return new DiagnosticCheck(
                code: 'stripeAccount',
                status: DiagnosticStatus::Fail,
                messageKey: 'stripeAccount.modeMismatch',
                context: $context,
                affectedOperations: ['checkout', 'webhook', 'order_sync', 'housekeeping'],
            );
        }

        return new DiagnosticCheck(
            code: 'stripeAccount',
            status: DiagnosticStatus::Pass,
            messageKey: 'stripeAccount.ready',
            context: $context,
        );
    }

    private function webhookCheck(?CredentialMode $accountMode): DiagnosticCheck
    {
        $canonicalUrl = self::normalizeWebhookUrl($this->canonicalWebhookUrl);

        if ($canonicalUrl === null) {
            return new DiagnosticCheck(
                code: 'stripeWebhook',
                status: DiagnosticStatus::Fail,
                messageKey: 'stripeWebhook.canonicalInvalid',
                context: ['url' => $this->canonicalWebhookUrl],
                affectedOperations: ['webhook'],
            );
        }

        try {
            $endpoints = $this->gateway->webhookEndpoints();
        } catch (DiagnosticsException $error) {
            return $this->remoteFailure('stripeWebhook', $error);
        }

        $matches = array_values(array_filter(
            $endpoints,
            static fn(WebhookEndpointRecord $endpoint): bool => self::normalizeWebhookUrl($endpoint->url()) === $canonicalUrl,
        ));
        $mode = $accountMode ?? $this->configuredMode;

        if ($matches === []) {
            if ($mode === CredentialMode::Unknown) {
                return new DiagnosticCheck(
                    code: 'stripeWebhook',
                    status: DiagnosticStatus::NotVerifiable,
                    messageKey: 'stripeWebhook.missingUnknownMode',
                    context: ['url' => $this->canonicalWebhookUrl],
                );
            }

            // A missing live endpoint blocks deliveries; test mode can still use local Stripe CLI forwarding.
            return new DiagnosticCheck(
                code: 'stripeWebhook',
                status: $mode === CredentialMode::Live ? DiagnosticStatus::Fail : DiagnosticStatus::Warning,
                messageKey: 'stripeWebhook.missing',
                context: [
                    'mode' => $mode->value,
                    'url' => $this->canonicalWebhookUrl,
                ],
                affectedOperations: $mode === CredentialMode::Live ? ['webhook'] : [],
            );
        }

        if ($mode === CredentialMode::Unknown) {
            $mode = $matches[0]->liveMode() ? CredentialMode::Live : CredentialMode::Test;
        }

        $disabled = 0;
        $modeMismatches = 0;
        $apiVersionMismatches = 0;
        $missingEvents = [];
        $invalidEnabledEndpoints = 0;

        foreach ($matches as $endpoint) {
            if ($endpoint->status() !== self::WEBHOOK_STATUS_ENABLED) {
                $disabled++;

                continue;
            }

            $invalid = false;

            if ($endpoint->liveMode() !== ($mode === CredentialMode::Live)) {
                $modeMismatches++;
                $invalid = true;
            }

            // Matching the SDK's pinned version keeps webhook payloads compatible with the parser.
            // https://docs.stripe.com/api/webhook_endpoints/object#webhook_endpoint_object-api_version
            if ($endpoint->apiVersion() !== ApiVersion::CURRENT) {
                $apiVersionMismatches++;
                $invalid = true;
            }

            $endpointMissingEvents = $this->missingEvents($endpoint);

            if ($endpointMissingEvents !== []) {
                $missingEvents = array_values(array_unique([...$missingEvents, ...$endpointMissingEvents]));
                $invalid = true;
            }

            $invalidEnabledEndpoints += $invalid ? 1 : 0;
        }

        $context = [
            'apiVersionMismatches' => (string) $apiVersionMismatches,
            'disabled' => (string) $disabled,
            'matches' => (string) count($matches),
            'missingEvents' => $missingEvents === [] ? '—' : implode(', ', $missingEvents),
            'mode' => $mode->value,
            'modeMismatches' => (string) $modeMismatches,
            'url' => $this->canonicalWebhookUrl,
        ];

        if ($invalidEnabledEndpoints > 0 || $disabled === count($matches)) {
            return new DiagnosticCheck(
                code: 'stripeWebhook',
                status: $mode === CredentialMode::Live ? DiagnosticStatus::Fail : DiagnosticStatus::Warning,
                messageKey: 'stripeWebhook.incomplete',
                context: $context,
                affectedOperations: $mode === CredentialMode::Live ? ['webhook'] : [],
            );
        }

        if (count($matches) > 1) {
            return new DiagnosticCheck(
                code: 'stripeWebhook',
                status: DiagnosticStatus::Warning,
                messageKey: 'stripeWebhook.duplicate',
                context: $context,
            );
        }

        return new DiagnosticCheck(
            code: 'stripeWebhook',
            status: DiagnosticStatus::Pass,
            messageKey: 'stripeWebhook.ready',
            context: $context,
        );
    }

    /** @return list<string> */
    private function missingEvents(WebhookEndpointRecord $endpoint): array
    {
        $enabledEvents = $endpoint->enabledEvents();

        return in_array('*', $enabledEvents, true)
            ? []
            : array_values(array_diff(ReconciliationEvent::TYPES, $enabledEvents));
    }

    private function remoteFailure(string $code, DiagnosticsException $error): DiagnosticCheck
    {
        $status = match ($error->errorCode()) {
            DiagnosticsErrorCode::PERMISSION_DENIED,
            DiagnosticsErrorCode::REMOTE_UNAVAILABLE => DiagnosticStatus::NotVerifiable,
            default => DiagnosticStatus::Fail,
        };
        $messageKey = match ($error->errorCode()) {
            DiagnosticsErrorCode::AUTHENTICATION_FAILED => 'remote.authenticationFailed',
            DiagnosticsErrorCode::PERMISSION_DENIED => 'remote.permissionDenied',
            DiagnosticsErrorCode::REMOTE_UNAVAILABLE => 'remote.unavailable',
            DiagnosticsErrorCode::REMOTE_REJECTED => 'remote.rejected',
            default => 'remote.incompatible',
        };
        // A failed diagnostic read does not prove commerce is unavailable; rejected credentials do.
        $affectedOperations = match (true) {
            $error->errorCode() !== DiagnosticsErrorCode::AUTHENTICATION_FAILED => [],
            $code === 'stripeAccount' => ['checkout', 'webhook', 'order_sync', 'housekeeping', 'remote_diagnostics'],
            default => ['webhook', 'remote_diagnostics'],
        };

        return new DiagnosticCheck(
            code: $code,
            status: $status,
            messageKey: $messageKey,
            context: ['code' => $error->errorCode()],
            affectedOperations: $affectedOperations,
        );
    }

    private static function normalizeWebhookUrl(string $url): ?string
    {
        $parts = parse_url(Url::normalize($url));

        if ($parts === false || isset($parts['scheme'], $parts['host']) === false) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $authority = '';

        if (isset($parts['user'])) {
            $authority = $parts['user'];

            if (isset($parts['pass'])) {
                $authority .= ':' . $parts['pass'];
            }

            $authority .= '@';
        }

        $authority .= str_contains($host, ':') ? '[' . $host . ']' : $host;

        if ($port !== null && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $authority .= ':' . $port;
        }

        $path = isset($parts['path'])
            ? rtrim($parts['path'], '/')
            : '';
        $normalized = $scheme . '://' . $authority . $path;

        if (isset($parts['query'])) {
            $normalized .= '?' . $parts['query'];
        }

        if (isset($parts['fragment'])) {
            $normalized .= '#' . $parts['fragment'];
        }

        return $normalized;
    }
}
