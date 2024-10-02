<?php

use Kirby\Cms\App;
use Kirby\Toolkit\Date;
use Kirby\Toolkit\Str;
use ProgrammatorDev\StripeCheckout\Exception\ProductDoesNotExistException;
use Stripe\Exception\SignatureVerificationException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\AtLeastOneOf;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\IsNull;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;

function resolveAddItemData(array $data): array
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

function resolveUpdateItemData(array $data): array
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
                    $data = resolveAddItemData($data);

                    // find page
                    if (($productPage = page($data['id'])) === null) {
                        throw new ProductDoesNotExistException('Product does not exist.');
                    }

                    $cart = cart();

                    $cart->addItem([
                        'id' => $productPage->id(),
                        'image' => $productPage->cover()->toFile()->url(),
                        'name' => $productPage->title()->value(),
                        'price' => (float) $productPage->price()->value(),
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
                    $data = resolveUpdateItemData($data);

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
                        'expand' => ['line_items.data.price.product', 'payment_intent.payment_method']
                    ]);

                    // impersonate kirby to have permissions to create pages
                    $kirby->impersonate('kirby');

                    switch ($event->type) {
                        // no need to handle duplicate events here
                        // because if an order is (tried to) be created with the same slug (which is based on the payment_intent)
                        // it will fail
                        case 'checkout.session.completed':
                            // create line items structure
                            $lineItems = [];

                            foreach ($checkoutSession->line_items->data as $lineItem) {
                                $lineItems[] = [
                                    'name' => $lineItem->price->product->name,
                                    'options' => $lineItem->price->product->description,
                                    'price' => $lineItem->price->unit_amount,
                                    'quantity' => $lineItem->quantity,
                                    'subtotal' => $lineItem->amount_subtotal
                                ];
                            }

                            // create order
                            $orderPage = $kirby->page('orders')->createChild([
                                'slug' => Str::slug($checkoutSession->payment_intent->id),
                                'template' => 'order',
                                'model' => 'order',
                                'draft' => false,
                                'content' => [
                                    'createdAt' => Date::now()->format('Y-m-d H:i:s'),
                                    'email' => $checkoutSession->customer_details->email,
                                    'paymentMethod' => $checkoutSession->payment_intent->payment_method->type,
                                    'lineItems' => $lineItems,
                                    'totalAmount' => $checkoutSession->payment_intent->amount,
                                    'events' => [
                                        [
                                            'id' => $event->id,
                                            'name' => $event->type,
                                            'paymentStatus' => $checkoutSession->payment_status,
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
                            $pageId = sprintf('orders/%s', Str::slug($checkoutSession->payment_intent->id));
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
                                'name' => $event->type,
                                'paymentStatus' => $checkoutSession->payment_status,
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
