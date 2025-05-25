<?php

use Kirby\Cms\App;
use Kirby\Toolkit\Date;
use Kirby\Toolkit\Str;
use ProgrammatorDev\StripeCheckout\Exception\InvalidEndpointException;
use ProgrammatorDev\StripeCheckout\Exception\InvalidWebhookException;
use ProgrammatorDev\StripeCheckout\MoneyFormatter;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Symfony\Component\Intl\Currencies;

return function(App $kirby) {
    return [
        // handle checkout "hosted" mode
        [
            'pattern' => 'stripe/checkout',
            'language' => '*',
            'method' => 'GET',
            'action' => function() use ($kirby) {
                $stripeCheckout = stripeCheckout();
                $checkoutSession = $stripeCheckout->createSession();

                if ($stripeCheckout->uiMode() !== Session::UI_MODE_HOSTED) {
                    throw new InvalidEndpointException(
                        'This endpoint is reserved for Stripe Checkout in "hosted" mode.'
                    );
                }

                // redirect to hosted payment form
                // https://docs.stripe.com/checkout/quickstart#redirect
                go($checkoutSession->url);
            }
        ],
        // handle checkout "embedded" mode
        [
            'pattern' => 'stripe/checkout/embedded',
            'language' => '*',
            'method' => 'POST',
            'action' => function() use ($kirby) {
                $stripeCheckout = stripeCheckout();
                $checkoutSession = $stripeCheckout->createSession();

                if ($stripeCheckout->uiMode() !== Session::UI_MODE_EMBEDDED) {
                    throw new InvalidEndpointException(
                        'This endpoint is reserved for Stripe Checkout in "embedded" mode.'
                    );
                }

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
                // invalid payload
                catch (UnexpectedValueException) {
                    throw new InvalidWebhookException('Invalid payload.');
                }
                // invalid signature
                catch (SignatureVerificationException) {
                    throw new InvalidWebhookException('Invalid signature.');
                }

                // get checkout session with required data
                $checkoutSession = $stripeCheckout->retrieveSession($event->data->object->id, [
                    'expand' => [
                        'line_items.data.price.product',
                        'payment_intent.payment_method',
                        'shipping_cost.shipping_rate'
                    ]
                ]);

                // get system timezone to be used on dates conversion
                $timezone = new DateTimeZone(date_default_timezone_get());
                // get order id
                $orderId = $checkoutSession->metadata['order_id'];
                // set "en" as the default language code if there is none
                $languageCode = !empty($checkoutSession->metadata['language_code'])
                    ? $checkoutSession->metadata['language_code']
                    : 'en';

                // impersonate kirby to have permissions to create pages
                $kirby->impersonate('kirby');
                // set the language that was used when the user made the order
                $kirby->setCurrentLanguage($languageCode);

                switch ($event->type) {
                    // no need to handle duplicate events here
                    // because if an order is (tried to) be created with the same slug it will fail
                    case Event::CHECKOUT_SESSION_COMPLETED:
                        $currency = strtoupper($checkoutSession->currency);
                        $lineItems = [];

                        foreach ($checkoutSession->line_items->data as $lineItem) {
                            $lineItems[] = [
                                'name' => $lineItem->price->product->name,
                                'description' => $lineItem->price->product->description,
                                'price' => MoneyFormatter::fromMinorUnit($lineItem->price->unit_amount, $currency, true),
                                'quantity' => $lineItem->quantity,
                                'subtotal' => MoneyFormatter::fromMinorUnit($lineItem->amount_subtotal, $currency, true),
                                'discount' => MoneyFormatter::fromMinorUnit($lineItem->amount_discount, $currency, true),
                                'total' => MoneyFormatter::fromMinorUnit($lineItem->amount_total, $currency, true),
                                'pageId' => $lineItem->price->product->metadata['page_id']
                            ];
                        }

                        $shippingDetails = $checkoutSession->shipping_details === null ? null : [
                            'name' => $checkoutSession->shipping_details->name ?? null,
                            'country' => $checkoutSession->shipping_details->address->country ?? null,
                            'line1' => $checkoutSession->shipping_details->address->line1 ?? null,
                            'line2' => $checkoutSession->shipping_details->address->line2 ?? null,
                            'postalCode' => $checkoutSession->shipping_details->address->postal_code ?? null,
                            'city' => $checkoutSession->shipping_details->address->city ?? null,
                            'state' => $checkoutSession->shipping_details->address->state ?? null
                        ];

                        // customer_details is always be populated with billing info,
                        // even when there is no payment_intent (no-cost orders)
                        $billingDetails = $checkoutSession->customer_details->address?->country === null ? null : [
                            'name' => $checkoutSession->customer_details->name ?? null,
                            'country' => $checkoutSession->customer_details->address->country ?? null,
                            'line1' => $checkoutSession->customer_details->address->line1 ?? null,
                            'line2' => $checkoutSession->customer_details->address->line2 ?? null,
                            'postalCode' => $checkoutSession->customer_details->address->postal_code ?? null,
                            'city' => $checkoutSession->customer_details->address->city ?? null,
                            'state' => $checkoutSession->customer_details->address->state ?? null
                        ];

                        $taxId = empty($checkoutSession->customer_details->tax_ids) ? null : [
                            'type' => $checkoutSession->customer_details->tax_ids[0]->type,
                            'value' => $checkoutSession->customer_details->tax_ids[0]->value
                        ];

                        $customFields = [];
                        foreach ($checkoutSession->custom_fields as $customField) {
                            $customFields[] = [
                                'name' => $customField->label->custom,
                                'value' => $customField->{$customField->type}->value,
                                'key' => $customField->key
                            ];
                        }

                        // if there was no payment intent,
                        // it means that there was no payment involved
                        // which means that it was a no-cost order
                        $paymentType = $checkoutSession->payment_intent?->payment_method->type ?? 'no_cost';
                        // find payment method translation
                        // if translation does not exist, try to generate a user-friendly name
                        // (for example: "apple_pay" to "Apple Pay")
                        $paymentMethod = t(
                            sprintf('stripe-checkout.paymentMethods.%s', $paymentType),
                            Str::ucwords(Str::replace($paymentType, '_', ' '))
                        );

                        $orderContent = [
                            'title' => $orderId,
                            'createdAt' => Date::now()->format('Y-m-d H:i:s'),
                            'customer' => [
                                'email' => $checkoutSession->customer_details->email,
                                'name' => $checkoutSession->customer_details->name ?? null,
                                'phone' => $checkoutSession->customer_details->phone ?? null,
                            ],
                            'paymentMethod' => $paymentMethod,
                            'lineItems' => $lineItems,
                            'shippingDetails' => $shippingDetails,
                            'shippingOption' => $checkoutSession->shipping_cost?->shipping_rate->display_name ?? null,
                            'billingDetails' => $billingDetails,
                            'taxId' => $taxId,
                            'subtotalAmount' => MoneyFormatter::fromMinorUnit($checkoutSession->amount_subtotal, $currency, true),
                            'discountAmount' => MoneyFormatter::fromMinorUnit($checkoutSession->total_details->amount_discount, $currency, true),
                            'shippingAmount' => MoneyFormatter::fromMinorUnit($checkoutSession->total_details->amount_shipping, $currency, true),
                            'totalAmount' => MoneyFormatter::fromMinorUnit($checkoutSession->amount_total, $currency, true),
                            'customFields' => $customFields,
                            'currency' => $currency,
                            'currencySymbol' => Currencies::getSymbol($currency),
                            'stripePaymentIntentId' => $checkoutSession->payment_intent?->id ?? null,
                            'stripeEvents' => [
                                [
                                    'id' => $event->id,
                                    'type' => $event->type,
                                    'paymentStatus' => $checkoutSession->payment_status,
                                    'message' => $checkoutSession->payment_intent?->last_payment_error->message ?? null,
                                    'createdAt' => Date::createFromFormat('U', $event->created)
                                        ->setTimezone($timezone)
                                        ->format('Y-m-d H:i:s')
                                ]
                            ]
                        ];

                        // trigger event to allow order content manipulation
                        $orderContent = $kirby->apply('stripe-checkout.order.create:before', [
                            'orderContent' => $orderContent,
                            'checkoutSession' => $checkoutSession,
                            'stripeEvent' => $event
                        ], 'orderContent');

                        // create order
                        $orderPage = $kirby->page($stripeCheckout->ordersPage())
                            ->createChild([
                                'slug' => $orderId,
                                'template' => 'order',
                                'model' => 'order',
                                'draft' => false,
                                'content' => $orderContent
                            ]);

                        // set order status and trigger events according to payment status
                        // if payment status is not "unpaid", set order and trigger payment event as completed
                        if ($checkoutSession->payment_status !== Session::PAYMENT_STATUS_UNPAID) {
                            $orderPage->update(['paidAt' => Date::now()->format('Y-m-d H:i:s')]);
                            $orderPage->changeStatus('listed');

                            $kirby->trigger('stripe-checkout.payment:succeeded', [
                                'orderPage' => $orderPage,
                                'checkoutSession' => $checkoutSession,
                                'stripeEvent' => $event
                            ]);
                        }
                        // if payment is "unpaid", it means it will be (possibly) paid in the future
                        // so let async events to handle the order and payment triggers for later
                        // for now just trigger that payment is pending
                        else {
                            $kirby->trigger('stripe-checkout.payment:pending', [
                                'orderPage' => $orderPage,
                                'checkoutSession' => $checkoutSession,
                                'stripeEvent' => $event
                            ]);
                        }

                        break;
                    case Event::CHECKOUT_SESSION_ASYNC_PAYMENT_SUCCEEDED:
                    case Event::CHECKOUT_SESSION_ASYNC_PAYMENT_FAILED:
                        // find existing order page
                        $orderPage = $kirby->page(
                            sprintf('%s/%s', $stripeCheckout->ordersPage(), $orderId)
                        );

                        // order does not exist
                        if ($orderPage === null) {
                            throw new InvalidWebhookException('Order does not exist.');
                        }

                        // get existing events
                        $orderStripeEvents = $orderPage->stripeEvents()->toStructure()->toArray();

                        // check if event id was already processed in the past
                        // immediately exit if it was
                        // https://docs.stripe.com/webhooks#handle-duplicate-events
                        foreach ($orderStripeEvents as $orderStripeEvent) {
                            if ($orderStripeEvent['id'] === $event->id) {
                                throw new InvalidWebhookException('Duplicate event.');
                            }
                        }

                        // add new event
                        $orderStripeEvents[] = [
                            'id' => $event->id,
                            'type' => $event->type,
                            'paymentStatus' => $checkoutSession->payment_status,
                            'message' => $checkoutSession->payment_intent?->last_payment_error->message ?? null,
                            'createdAt' => Date::createFromFormat('U', $event->created)
                                ->setTimezone($timezone)
                                ->format('Y-m-d H:i:s')
                        ];

                        // set order status and trigger events according to event received
                        // if payment succeeded, set order and trigger payment event as completed
                        if ($event->type === Event::CHECKOUT_SESSION_ASYNC_PAYMENT_SUCCEEDED) {
                            $orderPage->update([
                                'paidAt' => Date::now()->format('Y-m-d H:i:s'),
                                'stripeEvents' => $orderStripeEvents
                            ]);
                            $orderPage->changeStatus('listed');

                            $kirby->trigger('stripe-checkout.payment:succeeded', [
                                'orderPage' => $orderPage,
                                'checkoutSession' => $checkoutSession,
                                'stripeEvent' => $event
                            ]);
                        }
                        // if payment failed, set order and trigger payment event as failed
                        else if ($event->type === Event::CHECKOUT_SESSION_ASYNC_PAYMENT_FAILED) {
                            $orderPage->update(['stripeEvents' => $orderStripeEvents]);
                            $orderPage->changeStatus('draft');

                            $kirby->trigger('stripe-checkout.payment:failed', [
                                'orderPage' => $orderPage,
                                'checkoutSession' => $checkoutSession,
                                'stripeEvent' => $event
                            ]);
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
