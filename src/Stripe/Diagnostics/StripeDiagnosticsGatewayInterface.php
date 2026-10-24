<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Diagnostics;

use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;

/** Supplies the read-only Stripe facts required by explicit diagnostics. */
interface StripeDiagnosticsGatewayInterface
{
    public function accountMode(): CredentialMode;

    /** @return list<WebhookEndpointRecord> */
    public function webhookEndpoints(): array;
}
