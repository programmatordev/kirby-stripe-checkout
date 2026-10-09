<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\App;
use Kirby\Http\Response;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutEndpoint;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutUrlBuilder;

/** Registers localized Checkout submission without opening browser state during boot. */
final class CheckoutRoutes
{
    public const HTTP_METHOD_NOT_ALLOWED = 405;

    /** @return list<array<string, mixed>> */
    public static function definition(App $kirby): array
    {
        return [[
            'pattern' => CheckoutUrlBuilder::SUBMISSION_PATH,
            // Match other methods explicitly instead of falling through to a Page/404.
            'method' => 'ALL',
            'language' => '*',
            'action' => function () use ($kirby): Response {
                if ($kirby->request()->method() !== 'POST') {
                    // Kirby rebinds route closures to Route, including their class scope.
                    return new Response('', code: CheckoutRoutes::HTTP_METHOD_NOT_ALLOWED, headers: [
                        ...CheckoutEndpoint::HEADERS,
                        'Allow' => 'POST',
                    ]);
                }

                return (new CheckoutEndpoint($kirby))->respond();
            },
        ]];
    }
}
