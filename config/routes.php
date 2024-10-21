<?php

use Kirby\Cms\App;
use Kirby\Toolkit\Date;
use ProgrammatorDev\StripeCheckout\Exception\CheckoutEndpointException;
use ProgrammatorDev\StripeCheckout\Exception\CheckoutWebhookException;
use ProgrammatorDev\StripeCheckout\MoneyFormatter;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;

return function(App $kirby) {
    return [
        // handle checkout request
        [
            'pattern' => 'stripe/checkout',
            'method' => 'GET',
            'action' => function() use ($kirby) {
                $stripeCheckout = stripeCheckout();

                if ($stripeCheckout->getUiMode() !== Session::UI_MODE_HOSTED) {
                    throw new CheckoutEndpointException(
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

                if ($stripeCheckout->getUiMode() !== Session::UI_MODE_EMBEDDED) {
                    throw new CheckoutEndpointException(
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
                    $event = $stripeCheckout->constructEvent($payload, $sigHeader);
                }
                // invalid payload
                catch (UnexpectedValueException) {
                    throw new CheckoutWebhookException('Invalid payload.');
                }
                // invalid signature
                catch (SignatureVerificationException) {
                    throw new CheckoutWebhookException('Invalid signature.');
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
                    case Event::CHECKOUT_SESSION_COMPLETED:
                        // get order currency
                        $currency = $stripeCheckout->getCurrency();
                        // create line items structure
                        $lineItems = [];

                        foreach ($checkoutSession->line_items->data as $lineItem) {
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
                        $shippingDetails = $checkoutSession->shipping_details === null ? null : [
                            'name' => $checkoutSession->shipping_details->name ?? null,
                            'country' => $checkoutSession->shipping_details->address->country ?? null,
                            'line1' => $checkoutSession->shipping_details->address->line1 ?? null,
                            'line2' => $checkoutSession->shipping_details->address->line2 ?? null,
                            'postalCode' => $checkoutSession->shipping_details->address->postal_code ?? null,
                            'city' => $checkoutSession->shipping_details->address->city ?? null,
                            'state' => $checkoutSession->shipping_details->address->state ?? null
                        ];

                        // billing details
                        // customer_details is always be populated with billing info,
                        // even when there is no payment_intent (no-cost orders)
                        $billingDetails = $checkoutSession->customer_details->address?->country === null ? null : [
                            'name' => $checkoutSession->customer_details->name ?? null,
                            'country' => $checkoutSession->customer_details->address->country ?? null,
                            'line1' => $checkoutSession->customer_details->address->line1 ?? null,
                            'line2' => $checkoutSession->customer_details->payment_method->billing_details->address->line2 ?? null,
                            'postalCode' => $checkoutSession->customer_details->address->postal_code ?? null,
                            'city' => $checkoutSession->customer_details->address->city ?? null,
                            'state' => $checkoutSession->customer_details->address->state ?? null
                        ];

                        // tax id
                        $taxId = empty($checkoutSession->customer_details->tax_ids) ? null : [
                            'type' => $checkoutSession->customer_details->tax_ids[0]->type,
                            'value' => $checkoutSession->customer_details->tax_ids[0]->value
                        ];

                        // custom fields
                        $customFields = [];

                        foreach ($checkoutSession->custom_fields as $customField) {
                            $customFields[] = [
                                'name' => $customField->label->custom,
                                'value' => $customField->{$customField->type}->value
                            ];
                        }

                        // set order content
                        $orderContent = [
                            'paymentIntentId' => $checkoutSession->payment_intent?->id ?? null,
                            'createdAt' => Date::now()->format('Y-m-d H:i:s'),
                            'customer' => [
                                'email' => $checkoutSession->customer_details->email,
                                'name' => $checkoutSession->customer_details->name ?? null,
                                'phone' => $checkoutSession->customer_details->phone ?? null,
                            ],
                            'paymentMethod' => $checkoutSession->payment_intent?->payment_method->type ?? 'no_cost',
                            'lineItems' => $lineItems,
                            'shippingDetails' => $shippingDetails,
                            'shippingOption' => $checkoutSession->shipping_cost?->shipping_rate->display_name ?? null,
                            'billingDetails' => $billingDetails,
                            'taxId' => $taxId,
                            'subtotalAmount' => MoneyFormatter::formatFromMinorUnit($checkoutSession->amount_subtotal, $currency),
                            'discountAmount' => MoneyFormatter::formatFromMinorUnit($checkoutSession->total_details->amount_discount, $currency),
                            'shippingAmount' => MoneyFormatter::formatFromMinorUnit($checkoutSession->total_details->amount_shipping, $currency),
                            'totalAmount' => MoneyFormatter::formatFromMinorUnit($checkoutSession->amount_total, $currency),
                            'customFields' => $customFields,
                            'events' => [
                                [
                                    'id' => $event->id,
                                    'type' => $event->type,
                                    'paymentStatus' => $checkoutSession->payment_status,
                                    'message' => $checkoutSession->payment_intent?->last_payment_error->message ?? null,
                                    'date' => Date::createFromFormat('U', $event->created)->format('Y-m-d H:i:s')
                                ]
                            ]
                        ];

                        // trigger event to allow order content manipulation
                        $orderContent = kirby()->apply(
                            'stripe.checkout.orderCreate:before',
                            compact('orderContent', 'checkoutSession'),
                            'orderContent'
                        );

                        // create order
                        $orderPage = $kirby->page($stripeCheckout->getOrdersPage())
                            ->createChild([
                                'slug' => $checkoutSession->metadata['order_id'],
                                'template' => 'order',
                                'model' => 'order',
                                'draft' => false,
                                'content' => $orderContent
                            ]);

                        // set order status and trigger events according to payment status
                        // if payment status is not "unpaid", set order and trigger payment event as completed
                        if ($checkoutSession->payment_status !== Session::PAYMENT_STATUS_UNPAID) {
                            $orderPage->changeStatus('listed');

                            $kirby->trigger(
                                'stripe.checkout.payment:succeeded',
                                compact('orderPage', 'checkoutSession')
                            );
                        }
                        // if payment is "unpaid", it means it will be (possibly) paid in the future
                        // so let async events to handle the order and payment triggers for later
                        // for now just trigger that payment is pending
                        else {
                            $kirby->trigger(
                                'stripe.checkout.payment:pending',
                                compact('orderPage', 'checkoutSession')
                            );
                        }

                        break;
                    case Event::CHECKOUT_SESSION_ASYNC_PAYMENT_SUCCEEDED:
                    case Event::CHECKOUT_SESSION_ASYNC_PAYMENT_FAILED:
                        // find existing order page
                        $orderPage = $kirby->page(
                            sprintf(
                                '%s/%s',
                                $stripeCheckout->getOrdersPage(),
                                $checkoutSession->metadata['order_id']
                            )
                        );

                        // order does not exist
                        if ($orderPage === null) {
                            throw new CheckoutWebhookException('Order does not exist.');
                        }

                        // get existing events
                        $orderEvents = $orderPage->events()
                            ->toStructure()
                            ->toArray();

                        // check if event id was already processed in the past
                        // immediately exit if it was
                        // https://docs.stripe.com/webhooks#handle-duplicate-events
                        foreach ($orderEvents as $orderEvent) {
                            if ($orderEvent['id'] === $event->id) {
                                throw new CheckoutWebhookException('Duplicate event.');
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

                        // set order status and trigger events according to event received
                        // if payment succeeded, set order and trigger payment event as completed
                        if ($event->type === Event::CHECKOUT_SESSION_ASYNC_PAYMENT_SUCCEEDED) {
                            $orderPage->changeStatus('listed');

                            $kirby->trigger(
                                'stripe.checkout.payment:succeeded',
                                compact('orderPage', 'checkoutSession')
                            );
                        }
                        // if payment failed, set order and trigger payment event as failed
                        else if ($event->type === Event::CHECKOUT_SESSION_ASYNC_PAYMENT_FAILED) {
                            $orderPage->changeStatus('draft');

                            $kirby->trigger(
                                'stripe.checkout.payment:failed',
                                compact('orderPage', 'checkoutSession')
                            );
                        }

                        break;
                }

                return [
                    'status' => 'ok'
                ];
            }
        ]
    ];
};
