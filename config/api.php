<?php

use Kirby\Cms\App;
use Symfony\Component\Intl\Countries;

return [
    'routes' => function(App $kirby) {
        return [
            // get cart contents
            [
                'pattern' => 'cart',
                'method' => 'GET',
                'auth' => false,
                'action' => function() use ($kirby) {
                    $cart = cart();

                    return [
                        'status' => 'ok',
                        'data' => $cart->getContents(),
                        'snippet' => $cart->getCartSnippet()
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
                    $cart->addItem($data);

                    return [
                        'status' => 'ok',
                        'data' => $cart->getContents(),
                        'snippet' => $cart->getCartSnippet()
                    ];
                }
            ],
            // update cart item
            [
                'pattern' => 'cart/items/(:alphanum)',
                'method' => 'PATCH',
                'auth' => false,
                'action' => function(string $lineItemId) use ($kirby) {
                    $data = $kirby->request()->body()->toArray();

                    $cart = cart();
                    $cart->updateItem($lineItemId, $data);

                    return [
                        'status' => 'ok',
                        'data' => $cart->getContents(),
                        'snippet' => $cart->getCartSnippet()
                    ];
                }
            ],
            // delete cart item
            [
                'pattern' => 'cart/items/(:alphanum)',
                'method' => 'DELETE',
                'auth' => false,
                'action' => function(string $lineItemId) use ($kirby) {
                    $cart = cart();
                    $cart->removeItem($lineItemId);

                    return [
                        'status' => 'ok',
                        'data' => $cart->getContents(),
                        'snippet' => $cart->getCartSnippet()
                    ];
                }
            ],
            // get cart snippet
            [
                'pattern' => 'cart/snippet',
                'method' => 'GET',
                'auth' => false,
                'action' => function() use ($kirby) {
                    $cart = cart();

                    return [
                        'status' => 'ok',
                        'snippet' => $cart->getCartSnippet()
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
