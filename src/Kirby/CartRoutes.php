<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\App;
use Kirby\Http\Response;
use ProgrammatorDev\StripeCheckout\Cart\CartOperation;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartEndpoint;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;

/** Registers fixed site routes without opening a session during plugin boot. */
final class CartRoutes
{
    /** @return list<array<string, mixed>> */
    public static function definition(App $kirby): array
    {
        /** @var array<string, mixed> $options */
        $options = $kirby->options();

        try {
            if ((new ConfigurationResolver())->cartEnabled($options) === false) {
                return [];
            }
        } catch (ConfigurationException) {
            // Keep the site/Panel recoverable; the endpoint reports invalid
            // configuration safely instead of failing the entire plugin boot.
        }

        $resources = [
            'stripe-checkout/cart' => ['GET' => CartOperation::Read, 'DELETE' => CartOperation::Clear],
            'stripe-checkout/cart/items' => ['POST' => CartOperation::Add],
            'stripe-checkout/cart/items/(:any)' => ['PATCH' => CartOperation::Update, 'DELETE' => CartOperation::Remove],
        ];
        $routes = [];

        foreach ($resources as $pattern => $operations) {
            $routes[] = [
                'pattern' => $pattern,
                // Kirby otherwise falls through to a Page/404 on method mismatch.
                'method' => 'ALL',
                'language' => '*',
                'action' => function (mixed ...$arguments) use ($kirby, $operations): Response {
                    $operation = $operations[$kirby->request()->method()] ?? null;

                    if ($operation === null) {
                        return new Response('', code: 405, headers: [
                            ...CartEndpoint::HEADERS,
                            'Allow' => implode(', ', array_keys($operations)),
                        ]);
                    }

                    // Multi-language routing prepends a Language; the path's
                    // item ID (when present) is always the last string argument.
                    $last = $arguments === [] ? null : $arguments[array_key_last($arguments)];

                    return (new CartEndpoint($kirby))->respond($operation, is_string($last) ? $last : null);
                },
            ];
        }

        return $routes;
    }
}
