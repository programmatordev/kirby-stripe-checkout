<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\App;
use Kirby\Http\Response;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutCancelEndpoint;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutEndpoint;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutProviderReturnEndpoint;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutUrlBuilder;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;

/** Registers localized Checkout browser routes without opening session state during boot. */
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
        ], [
            'pattern' => CheckoutUrlBuilder::SUCCESS_PATH,
            'method' => 'ALL',
            'language' => '*',
            'action' => function () use ($kirby): Response {
                if ($kirby->request()->method() !== 'GET') {
                    return new Response('', code: CheckoutRoutes::HTTP_METHOD_NOT_ALLOWED, headers: [
                        ...CheckoutProviderReturnEndpoint::HEADERS,
                        'Allow' => 'GET',
                    ]);
                }

                return (new CheckoutProviderReturnEndpoint($kirby, UiMode::Hosted))->respond();
            },
        ], [
            'pattern' => CheckoutUrlBuilder::RETURN_PATH,
            'method' => 'ALL',
            'language' => '*',
            'action' => function () use ($kirby): Response {
                if ($kirby->request()->method() !== 'GET') {
                    return new Response('', code: CheckoutRoutes::HTTP_METHOD_NOT_ALLOWED, headers: [
                        ...CheckoutProviderReturnEndpoint::HEADERS,
                        'Allow' => 'GET',
                    ]);
                }

                return (new CheckoutProviderReturnEndpoint($kirby, UiMode::Embedded))->respond();
            },
        ], [
            'pattern' => CheckoutUrlBuilder::CANCEL_PATH,
            'method' => 'ALL',
            'language' => '*',
            'action' => function () use ($kirby): Response {
                if ($kirby->request()->method() !== 'GET') {
                    return new Response('', code: CheckoutRoutes::HTTP_METHOD_NOT_ALLOWED, headers: [
                        ...CheckoutCancelEndpoint::HEADERS,
                        'Allow' => 'GET',
                    ]);
                }

                return (new CheckoutCancelEndpoint($kirby))->respond();
            },
        ]];
    }
}
