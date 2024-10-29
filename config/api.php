<?php

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Exception\CartException;
use Symfony\Component\Intl\Countries;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\AtLeastOneOf;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\IsNull;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;

function resolveCartItemAdd(array $data): array
{
    $resolver = new OptionsResolver();

    $resolver->define('id')
        ->required()
        ->allowedTypes('string')
        ->allowedValues(Validation::createIsValidCallable(new NotBlank()));

    $resolver->define('quantity')
        ->required()
        ->allowedTypes('int')
        ->allowedValues(Validation::createIsValidCallable(new GreaterThan(0)));

    $resolver->define('options')
        ->default(null)
        ->allowedTypes('null', 'scalar[]')
        ->allowedValues(Validation::createIsValidCallable(new AtLeastOneOf([new IsNull(), new NotBlank()])));

    return $resolver->resolve($data);
}

function resolveCartItemUpdate(array $data): array
{
    $resolver = new OptionsResolver();

    $resolver->define('quantity')
        ->required()
        ->allowedTypes('int')
        ->allowedValues(Validation::createIsValidCallable(new GreaterThan(0)));

    return $resolver->resolve($data);
}

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
                        'data' => $cart->getContents()
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
                    $data = resolveCartItemAdd($data);

                    // find page
                    if (($productPage = page($data['id'])) === null) {
                        throw new CartException('Product does not exist.');
                    }

                    // set item data to add to cart
                    $itemContent = [
                        'id' => $productPage->id(),
                        'image' => $productPage->cover()->toFile()->url(),
                        'name' => $productPage->title()->value(),
                        'price' => $productPage->price()->toFloat(),
                        'quantity' => (int) $data['quantity'],
                        'options' => $data['options']
                    ];

                    // trigger event to allow cart item data manipulation
                    $itemContent = kirby()->apply(
                        'stripe-checkout.cart.addItem:before',
                        compact('itemContent', 'productPage'),
                        'itemContent'
                    );

                    $cart = cart();
                    $cart->addItem($itemContent);

                    return [
                        'status' => 'ok',
                        'data' => $cart->getContents()
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
                    $data = resolveCartItemUpdate($data);

                    $cart = cart();
                    $cart->updateItem($lineItemId, (int) $data['quantity']);

                    return [
                        'status' => 'ok',
                        'data' => $cart->getContents()
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
                        'data' => $cart->getContents()
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
