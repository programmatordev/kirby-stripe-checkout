<?php

use Kirby\Cms\App;
use Symfony\Component\Intl\Countries;

return [
    'routes' => function(App $kirby) {
        return [
            // get cart
            [
                'pattern' => 'cart',
                'method' => 'GET',
                'auth' => false,
                'action' => function() use ($kirby) {
                    $cart = cart();

                    return [
                        'status' => 'ok',
                        'data' => $cart->toArray(),
                        'snippet' => $cart->cartSnippet(true)
                    ];
                }
            ],
            // add item to cart
            [
                'pattern' => 'cart/items',
                'method' => 'POST',
                'auth' => false,
                'action' => function() use ($kirby) {
                    $data = $kirby->request()->body()->toArray();
                    $cart = cart();

                    // TODO validate data

                    $cart->addItem(
                        $data['id'],
                        $data['quantity'],
                        $data['options'] ?? null
                    );

                    return [
                        'status' => 'ok',
                        'data' => $cart->toArray(),
                        'snippet' => $cart->cartSnippet(true)
                    ];
                }
            ],
            // update cart item
            [
                'pattern' => 'cart/items/(:alphanum)',
                'method' => 'PATCH',
                'auth' => false,
                'action' => function(string $key) use ($kirby) {
                    $data = $kirby->request()->body()->toArray();
                    $cart = cart();

                    // TODO validate data

                    $cart->updateItem($key, $data['quantity']);

                    return [
                        'status' => 'ok',
                        'data' => $cart->toArray(),
                        'snippet' => $cart->cartSnippet(true)
                    ];
                }
            ],
            // delete cart item
            [
                'pattern' => 'cart/items/(:alphanum)',
                'method' => 'DELETE',
                'auth' => false,
                'action' => function(string $key) use ($kirby) {
                    $cart = cart();

                    $cart->removeItem($key);

                    return [
                        'status' => 'ok',
                        'data' => $cart->toArray(),
                        'snippet' => $cart->cartSnippet(true)
                    ];
                }
            ],
            // get cart snippet
            [
                'pattern' => 'cart/snippet',
                'method' => 'GET',
                'auth' => false,
                'action' => function() use ($kirby) {
                    return [
                        'status' => 'ok',
                        'snippet' => cart()->cartSnippet(true)
                    ];
                }
            ],
            // get Stripe allowed countries for shipping
            [
                'pattern' => 'stripe/countries',
                'method' => 'GET',
                'auth' => false,
                'action' => function() use ($kirby) {
                    // locale to display country names
                    // example: https://domain.com/api/stripe/countries?locale=pt_PT
                    $locale = get('locale') ?? null;
                    $countryNames = Countries::getNames($locale);

                    // remove unsupported countries
                    // https://docs.stripe.com/api/checkout/sessions/object?lang=php#checkout_session_object-shipping_address_collection-allowed_countries
                    unset(
                        $countryNames['AS'],
                        $countryNames['CX'],
                        $countryNames['CC'],
                        $countryNames['CU'],
                        $countryNames['HM'],
                        $countryNames['IR'],
                        $countryNames['KP'],
                        $countryNames['MH'],
                        $countryNames['FM'],
                        $countryNames['NF'],
                        $countryNames['MP'],
                        $countryNames['PW'],
                        $countryNames['SD'],
                        $countryNames['SY'],
                        $countryNames['UM'],
                        $countryNames['VI'],
                    );

                    return $countryNames;
                }
            ]
        ];
    }
];
