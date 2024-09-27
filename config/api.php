<?php

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Exception\ProductDoesNotExistException;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
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
                    $options = $kirby->option('programmatordev.stripe-checkout');

                    $payload = @file_get_contents('php://input');
                    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'];

                    try {
                        $event = StripeCheckout::constructWebhookEvent($payload, $sigHeader, $options['stripeWebhookSecret']);
                    }
                    catch (UnexpectedValueException) {
                        // invalid payload
                        exit;
                    }
                    catch (SignatureVerificationException) {
                        // invalid signature
                        exit;
                    }

                    // validate duplicated events
                    // save event ids and check if already exists to avoid duplication

                    switch ($event->type) {
                        case 'checkout.session.completed':
                        case 'checkout.session.async_payment_succeeded':
                            // fulfill order

                            // check payment_status
                            // if "unpaid" create incomplete order
                            // if "paid" complete order
                            break;
                        case 'checkout.session.async_payment_failed':
                            // fail order

                            // fail incomplete order
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
