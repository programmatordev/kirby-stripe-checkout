<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSchema;

/** @internal Default, extendable native editor for the canonical order projection. */
final class OrderBlueprint
{
    /** @return array<string, mixed> */
    public static function load(): array
    {
        $groups = [
            'overview' => ['orderNumber', 'checkoutStatus', 'paymentStatus', 'refundStatus', 'disputeStatus', 'currency', ...OrderSchema::AMOUNTS],
            'purchase' => ['initiatingLineItems', 'lineItems', 'customer', 'billingAddress', 'shippingAddress', 'customFields', 'consent'],
            'payment' => ['shipping', 'tax', 'discounts', 'payment', 'refunds', 'disputes', ...OrderSchema::FLAGS],
        ];
        $groups['technical'] = array_values(array_diff(OrderSchema::fields(), ...array_values($groups)));
        $tabs = [];

        foreach ($groups as $name => $handles) {
            $fields = [];

            foreach ($handles as $handle) {
                $fields[$handle] = self::field($handle);
            }

            $tabs[$name] = [
                'label' => 'programmatordev.stripe-checkout.orders.tabs.' . $name,
                'fields' => $fields,
            ];
        }

        return [
            'title' => 'programmatordev.stripe-checkout.orders.order',
            'icon' => 'cart',
            'options' => array_fill_keys(['create', 'changeSlug', 'changeStatus', 'changeTemplate', 'changeTitle', 'delete', 'duplicate', 'move', 'sort', 'preview'], false),
            'tabs' => $tabs,
        ];
    }

    /** @return array<string, mixed> */
    private static function field(string $handle): array
    {
        $field = [
            'label' => 'programmatordev.stripe-checkout.orders.fields.' . $handle,
            'type' => in_array($handle, OrderSchema::SNAPSHOTS, true) ? 'textarea' : 'text',
            'disabled' => true,
            'translate' => false,
        ];
        $members = match ($handle) {
            'stripeCheckout' => ['owner', 'schemaVersion'],
            'initiatingLineItems', 'lineItems' => ['reference', 'name', 'quantity', 'price', 'subtotal', 'currency', 'sku', 'variantId', 'stripePriceId', 'stripeProductId'],
            default => [],
        };

        if ($members !== []) {
            $field['type'] = in_array($handle, ['initiatingLineItems', 'lineItems'], true) ? 'structure' : 'object';
            $field['fields'] = [];

            foreach ($members as $member) {
                $field['fields'][$member] = [
                    'label' => 'programmatordev.stripe-checkout.orders.fields.' . $member,
                    'type' => 'text',
                    'disabled' => true,
                    'translate' => false,
                ];
            }
        }

        return $field;
    }
}
