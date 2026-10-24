<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\App;
use Kirby\Http\Response;

/** Registers the canonical signed endpoint independently of language, cart and storefront configuration. */
final class WebhookRoutes
{
    public const PATH = 'stripe-checkout/webhook';

    /** @return list<array<string, mixed>> */
    public static function definition(App $kirby): array
    {
        return [[
            'pattern' => self::PATH,
            'method' => 'POST',
            'action' => fn(): Response => (new WebhookEndpoint($kirby))->respond(),
        ]];
    }

    public static function url(App $kirby): string
    {
        return rtrim((string) $kirby->url('index'), '/') . '/' . self::PATH;
    }
}
