<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Support\Stripe;

use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Diagnostics\Exception\DiagnosticsException;
use ProgrammatorDev\StripeCheckout\Stripe\Diagnostics\StripeDiagnosticsGatewayInterface;
use ProgrammatorDev\StripeCheckout\Stripe\Diagnostics\WebhookEndpointRecord;

final class FakeDiagnosticsGateway implements StripeDiagnosticsGatewayInterface
{
    public CredentialMode $accountMode = CredentialMode::Test;

    public ?DiagnosticsException $accountFailure = null;

    public ?DiagnosticsException $webhookFailure = null;

    /** @var list<WebhookEndpointRecord> */
    public array $webhookEndpoints = [];

    public function accountMode(): CredentialMode
    {
        if ($this->accountFailure !== null) {
            throw $this->accountFailure;
        }

        return $this->accountMode;
    }

    public function webhookEndpoints(): array
    {
        if ($this->webhookFailure !== null) {
            throw $this->webhookFailure;
        }

        return $this->webhookEndpoints;
    }
}
