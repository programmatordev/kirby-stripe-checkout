<?php

use Kirby\Cms\App;
use Kirby\Toolkit\Date;
use ProgrammatorDev\StripeCheckout\Exception\ProductDoesNotExistException;
use ProgrammatorDev\StripeCheckout\MoneyFormatter;
use Stripe\Exception\SignatureVerificationException;
use Symfony\Component\Intl\Countries;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\AtLeastOneOf;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\IsNull;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;

function resolveAddCartItemData(array $data): array
{
    $resolver = new OptionsResolver();

    $resolver->setDefaults(['options' => null]);
    $resolver->setRequired(['id', 'quantity']);
    $resolver->setAllowedTypes('id', 'string');
    $resolver->setAllowedTypes('quantity', ['int']);
    $resolver->setAllowedTypes('options', ['null', 'scalar[]']);
    $resolver->setAllowedValues('id', Validation::createIsValidCallable(new NotBlank()));
    $resolver->setAllowedValues('quantity', Validation::createIsValidCallable(new GreaterThan(0)));
    $resolver->setAllowedValues('options', Validation::createIsValidCallable(new AtLeastOneOf([new IsNull(), new NotBlank()])));

    return $resolver->resolve($data);
}

function resolveUpdateCartItemData(array $data): array
{
    $resolver = new OptionsResolver();

    $resolver->setRequired(['quantity']);
    $resolver->setAllowedTypes('quantity', ['int']);
    $resolver->setAllowedValues('quantity', Validation::createIsValidCallable(new GreaterThan(0)));

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
                    $data = resolveAddCartItemData($data);

                    // find page
                    if (($productPage = page($data['id'])) === null) {
                        throw new ProductDoesNotExistException('Product does not exist.');
                    }

                    $cart = cart();
                    $productContent = $productPage->content();

                    $cart->addItem([
                        'id' => $productPage->id(),
                        'image' => $productContent->get('cover')->toFile()->url(),
                        'name' => $productContent->get('title')->value(),
                        'price' => (float) $productContent->get('price')->value(),
                        'quantity' => (int) $data['quantity'],
                        'options' => $data['options']
                    ]);

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
                    $data = resolveUpdateCartItemData($data);

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
                'pattern' => '(:any)/stripe/countries',
                'method' => 'GET',
                'auth' => false,
                'action' => function(string $language) use ($kirby) {
                    $countryNames = Countries::getNames($language);

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
            ],
            // handle webhooks to fulfill payments (or failure to do so)
            [
                'pattern' => 'stripe/checkout/webhook',
                'method' => 'POST',
                'auth' => false,
                'action' => function() use ($kirby) {
                    $stripeCheckout = stripeCheckout();

                    $payload = @file_get_contents('php://input');
                    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'];

                    try {
                        // validate webhook event
                        $event = $stripeCheckout->constructWebhookEvent($payload, $sigHeader);
                    }
                    catch (UnexpectedValueException) {
                        // invalid payload
                        http_response_code(400);
                        exit;
                    }
                    catch (SignatureVerificationException) {
                        // invalid signature
                        http_response_code(400);
                        exit;
                    }

                    // get checkout session with required data
                    $checkoutSession = $stripeCheckout->retrieveSession($event->data->object->id, [
                        'expand' => [
                            'line_items.data.price.product',
                            'payment_intent.payment_method',
                            'shipping_cost.shipping_rate'
                        ]
                    ]);

                    // impersonate kirby to have permissions to create pages
                    $kirby->impersonate('kirby');

                    switch ($event->type) {
                        // no need to handle duplicate events here
                        // because if an order is (tried to) be created with the same slug it will fail
                        case 'checkout.session.completed':
                            // get order currency
                            $currency = $stripeCheckout->getCurrency();
                            // create line items structure
                            $lineItems = [];

                            foreach ($checkoutSession->line_items->data as $lineItem) {
                                $price = MoneyFormatter::fromMinorUnit($lineItem->price->unit_amount, $currency);
                                $subtotal = MoneyFormatter::fromMinorUnit($lineItem->amount_subtotal, $currency);
                                $discount = MoneyFormatter::fromMinorUnit($lineItem->amount_discount, $currency);
                                $total = MoneyFormatter::fromMinorUnit($lineItem->amount_total, $currency);

                                $lineItems[] = [
                                    'name' => $lineItem->price->product->name,
                                    'description' => $lineItem->price->product->description,
                                    'price' => MoneyFormatter::format($price, $currency),
                                    'quantity' => $lineItem->quantity,
                                    'subtotal' => MoneyFormatter::format($subtotal, $currency),
                                    'discount' => MoneyFormatter::format($discount, $currency),
                                    'total' => MoneyFormatter::format($total, $currency)
                                ];
                            }

                            // get order amounts
                            $subtotalAmount = MoneyFormatter::fromMinorUnit($checkoutSession->amount_subtotal, $currency);
                            $discountAmount = MoneyFormatter::fromMinorUnit($checkoutSession->total_details->amount_discount, $currency);
                            $shippingAmount = MoneyFormatter::fromMinorUnit($checkoutSession->total_details->amount_shipping, $currency);
                            $totalAmount = MoneyFormatter::fromMinorUnit($checkoutSession->amount_total, $currency);

                            // create order
                            $orderPage = $kirby->page('orders')->createChild([
                                'slug' => $checkoutSession->metadata['order_id'],
                                'template' => 'order',
                                'model' => 'order',
                                'draft' => false,
                                'content' => [
                                    'paymentIntentId' => $checkoutSession->payment_intent?->id ?? null,
                                    'createdAt' => Date::now()->format('Y-m-d H:i:s'),
                                    'email' => $checkoutSession->customer_details->email,
                                    'paymentMethod' => $checkoutSession->payment_intent?->payment_method->type ?? 'no_cost',
                                    'lineItems' => $lineItems,
                                    'shippingDetails' => [
                                        'name' => $checkoutSession->shipping_details?->name ?? null,
                                        'country' => $checkoutSession->shipping_details?->address->country ?? null,
                                        'line1' => $checkoutSession->shipping_details?->address->line1 ?? null,
                                        'line2' => $checkoutSession->shipping_details?->address->line2 ?? null,
                                        'postalCode' => $checkoutSession->shipping_details?->address->postal_code ?? null,
                                        'city' => $checkoutSession->shipping_details?->address->city ?? null,
                                        'state' => $checkoutSession->shipping_details?->address->state ?? null
                                    ],
                                    'subtotalAmount' => MoneyFormatter::format($subtotalAmount, $currency),
                                    'discountAmount' => MoneyFormatter::format($discountAmount, $currency),
                                    'shippingAmount' => MoneyFormatter::format($shippingAmount, $currency),
                                    'totalAmount' => MoneyFormatter::format($totalAmount, $currency),
                                    'events' => [
                                        [
                                            'id' => $event->id,
                                            'type' => $event->type,
                                            'paymentStatus' => $checkoutSession->payment_status,
                                            'message' => $checkoutSession->payment_intent?->last_payment_error->message ?? null,
                                            'date' => Date::createFromFormat('U', $event->created)->format('Y-m-d H:i:s')
                                        ]
                                    ]
                                ]
                            ]);

                            // if payment status is "paid"
                            // set order as completed
                            if ($checkoutSession->payment_status === 'paid') {
                                $orderPage->changeStatus('listed');
                            }

                            break;
                        case 'checkout.session.async_payment_succeeded':
                        case 'checkout.session.async_payment_failed':
                            // find existing order page
                            $pageId = sprintf('orders/%s', $checkoutSession->metadata['order_id']);
                            $orderPage = $kirby->page($pageId);

                            // get existing events
                            $orderEvents = $orderPage->content()
                                ->get('events')
                                ->toStructure()
                                ->toArray();

                            // check if event id was already processed in the past
                            // immediately exit if it was
                            // https://docs.stripe.com/webhooks#handle-duplicate-events
                            foreach ($orderEvents as $orderEvent) {
                                if ($orderEvent['id'] === $event->id) {
                                    http_response_code(400);
                                    exit;
                                }
                            }

                            // add new event
                            $orderEvents[] = [
                                'id' => $event->id,
                                'type' => $event->type,
                                'paymentStatus' => $checkoutSession->payment_status,
                                'message' => $checkoutSession->payment_intent?->last_payment_error->message ?? null,
                                'date' => Date::createFromFormat('U', $event->created)->format('Y-m-d H:i:s')
                            ];

                            // update page events
                            $orderPage->update(['events' => $orderEvents]);

                            // set order status according to event received
                            match ($event->type) {
                                'checkout.session.async_payment_succeeded' => $orderPage->changeStatus('listed'),
                                'checkout.session.async_payment_failed' => $orderPage->changeStatus('draft'),
                            };

                            break;
                    }

                    return [
                        'status' => 'ok'
                    ];
                }
            ]
        ];
    }
];
