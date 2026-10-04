<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\App;
use Kirby\Http\Response;

/** Registers the canonical signed endpoint independently of language, cart and storefront configuration. */
final class WebhookRoutes
{
    /** @return list<array<string, mixed>> */
    public static function definition(App $kirby): array
    {
        return [[
            'pattern' => 'stripe-checkout/webhook',
            'method' => 'POST',
            'action' => fn(): Response => (new WebhookEndpoint($kirby))->respond(),
        ]];
    }
}
