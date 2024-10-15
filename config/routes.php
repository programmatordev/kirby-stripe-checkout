<?php

use Kirby\Cms\App;
use Kirby\Toolkit\Date;
use ProgrammatorDev\StripeCheckout\Exception\StripeCheckoutUiModeIsInvalidException;
use ProgrammatorDev\StripeCheckout\MoneyFormatter;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use Stripe\Exception\SignatureVerificationException;

return function(App $kirby) {
    return [
        // handle checkout request
        [
            'pattern' => 'stripe/checkout',
            'method' => 'GET',
            'action' => function() use ($kirby) {
                $stripeCheckout = stripeCheckout();

                if ($stripeCheckout->getUiMode() !== StripeCheckout::UI_MODE_HOSTED) {
                    throw new StripeCheckoutUiModeIsInvalidException(
                        'This endpoint is reserved for Stripe Checkout in "hosted" mode.'
                    );
                }

                $cart = cart();
                $checkoutSession = $stripeCheckout->createSession($cart);

                // redirect to hosted payment form
                // https://docs.stripe.com/checkout/quickstart#redirect
                go($checkoutSession->url);
            }
        ],
        // get checkout client secret when in "embedded" mode
        [
            'pattern' => 'stripe/checkout/embedded',
            'method' => 'POST',
            'action' => function() use ($kirby) {
                $stripeCheckout = stripeCheckout();

                if ($stripeCheckout->getUiMode() !== StripeCheckout::UI_MODE_EMBEDDED) {
                    throw new StripeCheckoutUiModeIsInvalidException(
                        'This endpoint is reserved for Stripe Checkout in "embedded" mode.'
                    );
                }

                $cart = cart();
                $checkoutSession = $stripeCheckout->createSession($cart);

                // return JSON with required data for embedded checkout
                // https://docs.stripe.com/checkout/embedded/quickstart#fetch-checkout-session
                return [
                    'clientSecret' => $checkoutSession->client_secret
                ];
            }
        ],
        // handle webhooks to fulfill payments (or failure to do so)
        [
            'pattern' => 'stripe/checkout/webhook',
            'method' => 'POST',
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
                $session = $stripeCheckout->retrieveSession($event->data->object->id, [
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

                        foreach ($session->line_items->data as $lineItem) {
                            $lineItems[] = [
                                'name' => $lineItem->price->product->name,
                                'description' => $lineItem->price->product->description,
                                'price' => MoneyFormatter::formatFromMinorUnit($lineItem->price->unit_amount, $currency),
                                'quantity' => $lineItem->quantity,
                                'subtotal' => MoneyFormatter::formatFromMinorUnit($lineItem->amount_subtotal, $currency),
                                'discount' => MoneyFormatter::formatFromMinorUnit($lineItem->amount_discount, $currency),
                                'total' => MoneyFormatter::formatFromMinorUnit($lineItem->amount_total, $currency)
                            ];
                        }

                        // shipping details
                        $shippingDetails = $session->shipping_details === null ? null : [
                            'name' => $session->shipping_details->name ?? null,
                            'country' => $session->shipping_details->address->country ?? null,
                            'line1' => $session->shipping_details->address->line1 ?? null,
                            'line2' => $session->shipping_details->address->line2 ?? null,
                            'postalCode' => $session->shipping_details->address->postal_code ?? null,
                            'city' => $session->shipping_details->address->city ?? null,
                            'state' => $session->shipping_details->address->state ?? null
                        ];

                        // billing details
                        // customer_details is always be populated with billing info,
                        // even when there is no payment_intent (no-cost orders)
                        $billingDetails = $session->customer_details->address?->country === null ? null : [
                            'name' => $session->customer_details->name ?? null,
                            'country' => $session->customer_details->address->country ?? null,
                            'line1' => $session->customer_details->address->line1 ?? null,
                            'line2' => $session->customer_details->payment_method->billing_details->address->line2 ?? null,
                            'postalCode' => $session->customer_details->address->postal_code ?? null,
                            'city' => $session->customer_details->address->city ?? null,
                            'state' => $session->customer_details->address->state ?? null
                        ];

                        // tax id
                        $taxId = empty($session->customer_details->tax_ids) ? null : [
                            'type' => $session->customer_details->tax_ids[0]->type,
                            'value' => $session->customer_details->tax_ids[0]->value
                        ];

                        // custom fields
                        $customFields = [];

                        foreach ($session->custom_fields as $customField) {
                            $customFields[] = [
                                'name' => $customField->label->custom,
                                'value' => $customField->{$customField->type}->value
                            ];
                        }

                        // set order content
                        $orderContent = [
                            'paymentIntentId' => $session->payment_intent?->id ?? null,
                            'createdAt' => Date::now()->format('Y-m-d H:i:s'),
                            'customer' => [
                                'email' => $session->customer_details->email,
                                'name' => $session->customer_details->name ?? null,
                                'phone' => $session->customer_details->phone ?? null,
                            ],
                            'paymentMethod' => $session->payment_intent?->payment_method->type ?? 'no_cost',
                            'lineItems' => $lineItems,
                            'shippingDetails' => $shippingDetails,
                            'shippingOption' => $session->shipping_cost?->shipping_rate->display_name ?? null,
                            'billingDetails' => $billingDetails,
                            'taxId' => $taxId,
                            'subtotalAmount' => MoneyFormatter::formatFromMinorUnit($session->amount_subtotal, $currency),
                            'discountAmount' => MoneyFormatter::formatFromMinorUnit($session->total_details->amount_discount, $currency),
                            'shippingAmount' => MoneyFormatter::formatFromMinorUnit($session->total_details->amount_shipping, $currency),
                            'totalAmount' => MoneyFormatter::formatFromMinorUnit($session->amount_total, $currency),
                            'customFields' => $customFields,
                            'events' => [
                                [
                                    'id' => $event->id,
                                    'type' => $event->type,
                                    'paymentStatus' => $session->payment_status,
                                    'message' => $session->payment_intent?->last_payment_error->message ?? null,
                                    'date' => Date::createFromFormat('U', $event->created)->format('Y-m-d H:i:s')
                                ]
                            ]
                        ];

                        // trigger event to allow order content manipulation
                        $orderContent = kirby()->apply(
                            'stripe.checkout.orderCreate:before',
                            compact('orderContent', 'session'),
                            'orderContent'
                        );

                        // create order
                        $orderPage = $kirby->page('orders')->createChild([
                            'slug' => $session->metadata['order_id'],
                            'template' => 'order',
                            'model' => 'order',
                            'draft' => false,
                            'content' => $orderContent
                        ]);

                        // if payment status is "paid"
                        // set order as completed
                        if ($session->payment_status === 'paid') {
                            $orderPage->changeStatus('listed');
                        }

                        break;
                    case 'checkout.session.async_payment_succeeded':
                    case 'checkout.session.async_payment_failed':
                        // find existing order page
                        $pageId = sprintf('orders/%s', $session->metadata['order_id']);
                        $orderPage = $kirby->page($pageId);

                        // get existing events
                        $orderEvents = $orderPage->events()->toStructure()->toArray();

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
                            'paymentStatus' => $session->payment_status,
                            'message' => $session->payment_intent?->last_payment_error->message ?? null,
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
};
