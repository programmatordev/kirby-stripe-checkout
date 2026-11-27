<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use BackedEnum;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\DisputeStatus;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSchema;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Order\RefundStatus;

/** @internal Default, extendable native editor for the canonical order projection. */
final class OrderBlueprint
{
    /** @return array<string, mixed> */
    public static function load(): array
    {
        $sections = [
            'customer' => [
                'customer' => ['customer', OrderPagePanel::handle('customerTaxIds')],
                'addresses' => ['shippingAddress', 'billingAddress'],
                'checkoutDetails' => ['customFields', 'consent'],
            ],
            'payment' => [
                'payment' => ['payment'],
                'refunds' => ['refunds'],
                'disputes' => ['disputes'],
            ],
        ];
        $displayed = [];

        foreach ($sections as $tabSections) {
            foreach ($tabSections as $handles) {
                $displayed = [...$displayed, ...$handles];
            }
        }

        $technical = array_values(array_diff(OrderSchema::fields(), $displayed));
        $sections['technical'] = [
            'identity' => array_values(array_intersect($technical, ['title', 'uuid', 'userUuid', 'languageCode'])),
            'references' => array_values(array_intersect($technical, OrderSchema::REFERENCES)),
            'history' => array_values(array_intersect($technical, OrderSchema::TIMESTAMPS)),
            'checkoutAttempt' => [
                ...array_map(
                    static fn(string $field): string => OrderPagePanel::handle('checkoutAttempt' . ucfirst($field)),
                    OrderPagePanel::CHECKOUT_ATTEMPT_FIELDS,
                ),
                OrderPagePanel::handle('checkoutSessionRequest'),
            ],
            'checkoutFailure' => [
                OrderPagePanel::handle('checkoutFailureType'),
                OrderPagePanel::handle('checkoutFailureRequestId'),
                OrderPagePanel::handle('checkoutFailureProviderCode'),
                OrderPagePanel::handle('checkoutFailureProviderType'),
                OrderPagePanel::handle('checkoutFailureRetryable'),
                OrderPagePanel::handle('checkoutFailureOccurredAt'),
            ],
            'operations' => array_values(array_diff(
                $technical,
                ['title', 'uuid', 'userUuid', 'languageCode', 'checkoutAttempt'],
                OrderSchema::REFERENCES,
                OrderSchema::TIMESTAMPS,
            )),
        ];
        $tabs = ['overview' => self::overviewTab()];

        foreach ($sections as $tabName => $tabSections) {
            $tabs[$tabName] = self::tab($tabName, $tabSections);
        }

        return [
            'title' => 'programmatordev.stripe-checkout.orders.order',
            'icon' => 'cart',
            'buttons' => ['stripeCheckoutOrderSync', 'stripeCheckoutOrderRetryLifecycleDelivery', 'stripeCheckoutOrderOpenInStripe', 'stripeCheckoutOrderDelete'],
            'options' => array_fill_keys(['create', 'changeSlug', 'changeStatus', 'changeTemplate', 'changeTitle', 'delete', 'duplicate', 'move', 'sort', 'preview'], false),
            'tabs' => $tabs,
        ];
    }

    /** @return array<string, mixed> */
    private static function field(string $handle): array
    {
        $checkoutStateInfoFields = self::checkoutStateInfoFields();
        $checkoutStatus = $checkoutStateInfoFields[$handle] ?? null;

        if ($checkoutStatus !== null) {
            return [
                'label' => false,
                'type' => 'info',
                'text' => 'programmatordev.stripe-checkout.orders.checkoutAvailability.' . $checkoutStatus->value,
                'theme' => match ($checkoutStatus) {
                    CheckoutStatus::CreationUncertain, CheckoutStatus::Expired => 'warning',
                    CheckoutStatus::CreationFailed => 'negative',
                    default => 'info',
                },
                'when' => ['checkoutStatus' => $checkoutStatus->value],
            ];
        }

        if (($sectionName = self::headlineName($handle)) !== null) {
            $field = [
                'label' => 'programmatordev.stripe-checkout.orders.sections.' . $sectionName,
                'numbered' => false,
                'type' => 'headline',
            ];

            $field['when'] = match ($sectionName) {
                'discounts' => [OrderPagePanel::handle('hasDiscounts') => true],
                'customerSummary', 'billingAddressSummary' => [
                    'checkoutStatus' => CheckoutStatus::Complete->value,
                ],
                'deliverySummary' => [
                    'checkoutStatus' => CheckoutStatus::Complete->value,
                ],
                default => null,
            };

            if ($field['when'] === null) {
                unset($field['when']);
            }

            return $field;
        }

        $field = self::presentationField($handle) ?? match ($handle) {
            'checkoutStatus' => self::statusField(CheckoutStatus::cases(), 'checkout'),
            'paymentStatus' => self::statusField(PaymentStatus::cases(), 'payment'),
            'refundStatus' => self::statusField(RefundStatus::cases(), 'refundFilter'),
            'disputeStatus' => self::statusField(DisputeStatus::cases(), 'disputeFilter'),
            'lineItems' => self::lineItemsField(),
            'customer' => self::customerField(),
            'billingAddress', 'shippingAddress' => self::addressField(),
            'customFields' => self::customFieldsField(),
            'consent' => self::consentField(),
            'discounts' => self::discountsField(),
            'tax' => self::taxField(),
            'shipping' => self::shippingField(),
            'payment' => self::paymentField(),
            'refunds', 'disputes' => self::collectionField($handle),
            'stripeCheckout' => self::objectField(['owner', 'schemaVersion']),
            'initiatingLineItems' => self::initiatingLineItemsField(),
            'stripeShippingRateIds' => ['type' => 'tags'],
            'events' => self::eventsField(),
            'lifecycleDeliveries' => self::lifecycleDeliveriesField(),
            default => [
                'type' => in_array($handle, OrderSchema::SNAPSHOTS, true) ? 'textarea' : 'text',
            ],
        };
        $label = self::presentationLabel($handle) ?? $handle;
        $field = [
            'label' => 'programmatordev.stripe-checkout.orders.fields.' . $label,
            'disabled' => true,
            'translate' => false,
            ...$field,
        ];

        $width = match (true) {
            in_array($handle, [
                'createdAt',
                'updatedAt',
                'checkoutCompletedAt',
                'paidAt',
                'paymentFailedAt',
            ], true) => '1/4',
            default => null,
        };

        if ($width !== null) {
            $field['width'] = $width;
        }

        $when = match ($handle) {
            OrderPagePanel::handle('customerSummary'),
            OrderPagePanel::handle('billingAddressSummary') => [
                'checkoutStatus' => CheckoutStatus::Complete->value,
            ],
            OrderPagePanel::handle('shippingMethod'),
            OrderPagePanel::handle('deliveryEstimate'),
            OrderPagePanel::handle('shippingAddressSummary') => [
                'checkoutStatus' => CheckoutStatus::Complete->value,
                OrderPagePanel::handle('requiresShipping') => true,
            ],
            OrderPagePanel::handle('shippingNotRequiredInfo') => [
                'checkoutStatus' => CheckoutStatus::Complete->value,
                OrderPagePanel::handle('requiresShipping') => false,
            ],
            OrderPagePanel::handle('orderDiscounts') => [OrderPagePanel::handle('hasDiscounts') => true],
            OrderPagePanel::handle('formattedSubtotal'),
            OrderPagePanel::handle('formattedDiscountTotal'),
            OrderPagePanel::handle('formattedShippingTotal'),
            OrderPagePanel::handle('formattedTaxTotal'),
            OrderPagePanel::handle('formattedTotal') => [
                'checkoutStatus' => CheckoutStatus::Complete->value,
            ],
            OrderPagePanel::handle('formattedRefundedTotal') => [
                'checkoutStatus' => CheckoutStatus::Complete->value,
                OrderPagePanel::handle('hasRefundedAmount') => true,
            ],
            OrderPagePanel::handle('formattedCheckoutCompletedAt') => ['checkoutStatus' => CheckoutStatus::Complete->value],
            OrderPagePanel::handle('formattedPaidAt') => ['paymentStatus' => PaymentStatus::Paid->value],
            OrderPagePanel::handle('formattedPaymentFailedAt') => ['paymentStatus' => PaymentStatus::Failed->value],
            default => null,
        };

        if ($when !== null) {
            $field['when'] = $when;
        }

        if (in_array($handle, OrderSchema::TIMESTAMPS, true)) {
            $field = [...$field, ...self::dateProperties()];
        }

        if (in_array($handle, OrderSchema::FLAGS, true)) {
            $field['type'] = 'toggle';
        }

        return $field;
    }

    /** @return array<string, mixed>|null */
    private static function presentationField(string $handle): ?array
    {
        return match (OrderPagePanel::name($handle)) {
            'orderItems' => self::orderItemsField(),
            'orderDiscounts' => self::orderDiscountsField(),
            'customerSummary' => self::customerSummaryField(),
            'billingAddressSummary' => self::billingAddressSummaryField(),
            'shippingAddressSummary' => self::addressSummaryField(),
            'hasDiscounts', 'hasRefundedAmount', 'requiresShipping' => [
                'label' => false,
                'type' => 'hidden',
            ],
            'shippingNotRequiredInfo' => [
                'label' => false,
                'type' => 'info',
                'text' => 'programmatordev.stripe-checkout.orders.shippingNotRequired',
                'theme' => 'info',
            ],
            'customerTaxIds' => self::structureField(['type', 'value']),
            'checkoutSessionRequest' => self::factsField(),
            'checkoutFailureRetryable' => ['type' => 'toggle'],
            'checkoutFailureOccurredAt', 'checkoutAttemptRetryUntil' => self::dateProperties(),
            null => null,
            default => ['type' => 'text'],
        };
    }

    private static function presentationLabel(string $handle): ?string
    {
        $name = OrderPagePanel::name($handle);

        if ($name === null) {
            return null;
        }

        $labels = [
            'customerTaxIds' => 'taxIds',
            'checkoutSessionRequest' => 'sessionRequest',
            'checkoutFailureType' => 'failureType',
            'checkoutFailureRequestId' => 'requestId',
            'checkoutFailureProviderCode' => 'providerCode',
            'checkoutFailureProviderType' => 'providerType',
            'checkoutFailureRetryable' => 'retryable',
            'checkoutFailureOccurredAt' => 'occurredAt',
            'shippingMethod' => 'shippingMethod',
            'deliveryEstimate' => 'deliveryEstimate',
            'shippingAddressSummary' => 'shippingAddress',
        ];

        if (isset($labels[$name])) {
            return $labels[$name];
        }

        if (str_starts_with($name, 'checkoutAttempt')) {
            return lcfirst(substr($name, strlen('checkoutAttempt')));
        }

        foreach (['billingAddress', 'shippingAddress', 'formatted'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return lcfirst(substr($name, strlen($prefix)));
            }
        }

        return $name;
    }

    private static function headline(string $sectionName): string
    {
        return OrderPagePanel::handle($sectionName . 'Headline');
    }

    /**
     * Kirby field conditions cannot express an OR across status values, so
     * each incomplete Checkout state needs its own conditional info field.
     *
     * @return array<string, CheckoutStatus>
     */
    private static function checkoutStateInfoFields(): array
    {
        return [
            OrderPagePanel::handle('checkoutCreatingInfo') => CheckoutStatus::Creating,
            OrderPagePanel::handle('checkoutCreationUncertainInfo') => CheckoutStatus::CreationUncertain,
            OrderPagePanel::handle('checkoutCreationFailedInfo') => CheckoutStatus::CreationFailed,
            OrderPagePanel::handle('checkoutOpenInfo') => CheckoutStatus::Open,
            OrderPagePanel::handle('checkoutExpiredInfo') => CheckoutStatus::Expired,
        ];
    }

    private static function headlineName(string $handle): ?string
    {
        $name = OrderPagePanel::name($handle);

        if ($name === null || str_ends_with($name, 'Headline') === false) {
            return null;
        }

        return substr($name, 0, -strlen('Headline'));
    }

    /** @return array<string, mixed> */
    private static function overviewTab(): array
    {
        return [
            'label' => 'programmatordev.stripe-checkout.orders.tabs.overview',
            'columns' => [
                'details' => [
                    'width' => '1/1',
                    'fields' => self::overviewFields(),
                ],
            ],
        ];
    }

    /**
     * A single field grid lets Kirby collapse conditional order sections.
     *
     * @return array<string, mixed>
     */
    private static function overviewFields(): array
    {
        $fields = [];

        $fields[OrderPagePanel::handle('statusReports')] = [
            'type' => 'stripe-checkout-order-status',
            'reports' => 'page.panel.statusReports',
            'size' => 'medium',
            'width' => '1/1',
        ];

        foreach (self::checkoutStateInfoFields() as $handle => $checkoutStatus) {
            $fields[$handle] = self::fieldAtWidth($handle, '1/1');
        }

        $fields[self::headline('items')] = self::fieldAtWidth(self::headline('items'), '1/1');
        $fields[OrderPagePanel::handle('orderItems')] = self::fieldAtWidth(
            OrderPagePanel::handle('orderItems'),
            '1/1',
        );

        foreach ([
            'formattedSubtotal',
            'formattedDiscountTotal',
            'formattedShippingTotal',
            'formattedTaxTotal',
        ] as $name) {
            $handle = OrderPagePanel::handle($name);
            $fields[$handle] = self::fieldAtWidth($handle, '1/4');
        }

        $fields[OrderPagePanel::handle('formattedTotal')] = self::fieldAtWidth(
            OrderPagePanel::handle('formattedTotal'),
            '1/1',
        );
        $fields[OrderPagePanel::handle('formattedRefundedTotal')] = self::fieldAtWidth(
            OrderPagePanel::handle('formattedRefundedTotal'),
            '1/1',
        );
        $fields[self::headline('discounts')] = self::fieldAtWidth(self::headline('discounts'), '1/1');
        $fields[OrderPagePanel::handle('orderDiscounts')] = self::fieldAtWidth(
            OrderPagePanel::handle('orderDiscounts'),
            '1/1',
        );

        foreach ([
            'customerSummary' => 'customerSummary',
            'billingAddressSummary' => 'billingAddressSummary',
        ] as $sectionName => $fieldName) {
            $fields[self::headline($sectionName)] = self::fieldAtWidth(self::headline($sectionName), '1/1');
            $handle = OrderPagePanel::handle($fieldName);
            $fields[$handle] = self::fieldAtWidth($handle, '1/1');
        }

        $fields[self::headline('deliverySummary')] = self::fieldAtWidth(self::headline('deliverySummary'), '1/1');
        $fields[OrderPagePanel::handle('shippingMethod')] = self::fieldAtWidth(
            OrderPagePanel::handle('shippingMethod'),
            '1/2',
        );
        $fields[OrderPagePanel::handle('deliveryEstimate')] = self::fieldAtWidth(
            OrderPagePanel::handle('deliveryEstimate'),
            '1/2',
        );
        $fields[OrderPagePanel::handle('shippingAddressSummary')] = self::fieldAtWidth(
            OrderPagePanel::handle('shippingAddressSummary'),
            '1/1',
        );
        $fields[OrderPagePanel::handle('shippingNotRequiredInfo')] = self::fieldAtWidth(
            OrderPagePanel::handle('shippingNotRequiredInfo'),
            '1/1',
        );

        $fields[self::headline('keyDates')] = self::fieldAtWidth(self::headline('keyDates'), '1/1');

        foreach ([
            'formattedCreatedAt',
            'formattedCheckoutCompletedAt',
            'formattedPaidAt',
            'formattedPaymentFailedAt',
        ] as $name) {
            $handle = OrderPagePanel::handle($name);
            $fields[$handle] = self::fieldAtWidth($handle, '1/3');
        }

        foreach (['hasDiscounts', 'hasRefundedAmount', 'requiresShipping'] as $name) {
            $handle = OrderPagePanel::handle($name);
            $fields[$handle] = self::field($handle);
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private static function fieldAtWidth(string $handle, string $width): array
    {
        return [
            ...self::field($handle),
            'width' => $width,
        ];
    }

    /**
     * @param list<string> $handles
     * @return array<string, mixed>
     */
    private static function fields(array $handles): array
    {
        $fields = [];

        foreach ($handles as $handle) {
            $fields[$handle] = self::field($handle);
        }

        return $fields;
    }

    /**
     * @param array<string, list<string>> $sections
     * @return array<string, mixed>
     */
    private static function tab(string $name, array $sections): array
    {
        $blueprintSections = [];

        foreach ($sections as $sectionName => $handles) {
            if ($handles === []) {
                continue;
            }

            $blueprintSections[$sectionName] = [
                'type' => 'fields',
                'headline' => 'programmatordev.stripe-checkout.orders.sections.' . $sectionName,
                'fields' => self::fields($handles),
            ];
        }

        return [
            'label' => 'programmatordev.stripe-checkout.orders.tabs.' . $name,
            'sections' => $blueprintSections,
        ];
    }

    /**
     * @param list<BackedEnum> $cases
     * @return array<string, mixed>
     */
    private static function statusField(array $cases, string $translationGroup): array
    {
        $options = [];

        foreach ($cases as $case) {
            $options[(string) $case->value] = [
                '*' => 'programmatordev.stripe-checkout.orders.index.option.' . $translationGroup . '.' . $case->value,
            ];
        }

        return [
            'type' => 'select',
            'options' => $options,
        ];
    }

    /** @return array<string, mixed> */
    private static function lineItemsField(): array
    {
        $field = self::structureField(
            members: [
                'stripeLineItemId',
                'initiatingIndex',
                'stripePriceId',
                'stripeProductId',
                'quantity',
                'description',
                'currency',
                'price',
                'subtotal',
                'discount',
                'tax',
                'total',
                'discounts',
            ],
            columns: [
                'description' => [],
                'quantity' => ['align' => 'right'],
                'total' => ['align' => 'right'],
                'currency' => [],
            ],
            types: [
                'initiatingIndex' => 'number',
                'quantity' => 'number',
            ],
            help: 'programmatordev.stripe-checkout.orders.lineItemsHelp',
        );
        $field['fields']['discounts'] = self::nestedField('discounts', self::discountsField());

        return $field;
    }

    /** @return array<string, mixed> */
    private static function orderItemsField(): array
    {
        $field = self::structureField(
            members: [
                'name',
                'description',
                'optionSummary',
                'sku',
                'quantity',
                'requiresShipping',
                'currency',
                'price',
                'subtotal',
                'discount',
                'tax',
                'total',
            ],
            columns: [
                'name' => [],
                'optionSummary' => [],
                'quantity' => ['align' => 'right'],
                'total' => ['align' => 'right'],
            ],
            types: [
                'optionSummary' => 'tags',
                'quantity' => 'number',
                'requiresShipping' => 'toggle',
            ],
        );
        $field['label'] = false;

        return $field;
    }

    /** @return array<string, mixed> */
    private static function orderDiscountsField(): array
    {
        $field = self::structureField(
            members: ['couponName', 'promotionCode', 'amount', 'percentOff'],
            columns: [
                'couponName' => [],
                'promotionCode' => [],
                'amount' => ['align' => 'right'],
                'percentOff' => ['align' => 'right'],
            ],
        );
        $field['label'] = false;

        return $field;
    }

    /** @return array<string, mixed> */
    private static function customerSummaryField(): array
    {
        $field = self::customerField();
        $field['label'] = false;

        foreach ($field['fields'] as $name => $member) {
            $field['fields'][$name] = [
                ...$member,
                'width' => '1/2',
            ];
        }

        return $field;
    }

    /** @return array<string, mixed> */
    private static function billingAddressSummaryField(): array
    {
        return [
            ...self::addressSummaryField(),
            'label' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function addressSummaryField(): array
    {
        $field = self::addressField();

        foreach ($field['fields'] as $name => $member) {
            $field['fields'][$name] = [
                ...$member,
                'width' => match ($name) {
                    'postalCode', 'city', 'state' => '1/3',
                    default => '1/2',
                },
            ];
        }

        return $field;
    }

    /** @return array{type: 'object', fields: array<string, array<string, mixed>>} */
    private static function customerField(): array
    {
        $field = self::objectField(['email', 'individualName', 'businessName', 'phone']);
        $field['fields']['email']['type'] = 'email';
        $field['fields']['phone']['type'] = 'tel';

        return $field;
    }

    /** @return array{type: 'object', fields: array<string, array<string, mixed>>} */
    private static function addressField(): array
    {
        return self::objectField(['name', 'country', 'line1', 'line2', 'postalCode', 'city', 'state']);
    }

    /** @return array<string, mixed> */
    private static function customFieldsField(): array
    {
        return self::structureField(
            members: ['key', 'type', 'label', 'required', 'configured', 'answered', 'value'],
            columns: [
                'label' => [],
                'value' => [],
                'type' => [],
            ],
            types: [
                'required' => 'toggle',
                'configured' => 'toggle',
                'answered' => 'toggle',
            ],
        );
    }

    /** @return array<string, mixed> */
    private static function consentField(): array
    {
        return self::objectField(['termsOfService', 'promotions']);
    }

    /** @return array<string, mixed> */
    private static function discountsField(): array
    {
        return self::structureField(
            members: [
                'discountId',
                'couponId',
                'promotionCodeId',
                'couponName',
                'promotionCode',
                'amount',
                'currency',
                'providerAmount',
                'percentOff',
                'appliesToProducts',
                'firstTimeTransaction',
                'minimumAmount',
                'minimumAmountCurrency',
                'providerMinimumAmount',
            ],
            columns: [
                'couponName' => [],
                'promotionCode' => [],
                'amount' => ['align' => 'right'],
                'currency' => [],
            ],
            types: [
                'providerAmount' => 'number',
                'appliesToProducts' => 'tags',
                'firstTimeTransaction' => 'toggle',
                'providerMinimumAmount' => 'number',
            ],
        );
    }

    /** @return array<string, mixed> */
    private static function shippingField(): array
    {
        $field = self::objectField([
            'optionKey',
            'quoteFingerprint',
            'label',
            'currency',
            'subtotal',
            'providerSubtotal',
            'tax',
            'providerTax',
            'total',
            'providerTotal',
            'deliveryEstimate',
            'taxBehavior',
            'taxCode',
        ]);
        $deliveryEstimate = self::objectField([
            'minimum',
            'maximum',
            'unit',
        ]);
        $deliveryEstimate['fields']['minimum']['type'] = 'number';
        $deliveryEstimate['fields']['maximum']['type'] = 'number';
        $field['fields']['deliveryEstimate'] = self::nestedField('deliveryEstimate', $deliveryEstimate);

        foreach (['providerSubtotal', 'providerTax', 'providerTotal'] as $providerAmount) {
            $field['fields'][$providerAmount]['type'] = 'number';
        }

        return $field;
    }

    /** @return array<string, mixed> */
    private static function taxField(): array
    {
        $field = self::objectField([
            'automaticTaxEnabled',
            'calculationStatus',
            'provider',
            'currency',
            'amount',
            'providerAmount',
            'breakdown',
        ]);
        $field['fields']['automaticTaxEnabled']['type'] = 'toggle';
        $field['fields']['providerAmount']['type'] = 'number';
        $field['fields']['breakdown'] = self::nestedField('breakdown', self::taxBreakdownField());

        return $field;
    }

    /** @return array<string, mixed> */
    private static function taxBreakdownField(): array
    {
        return self::structureField(
            members: [
                'target',
                'targetId',
                'amount',
                'providerAmount',
                'currency',
                'taxableAmount',
                'providerTaxableAmount',
                'inclusive',
                'rateId',
                'percentage',
                'effectivePercentage',
                'jurisdiction',
                'jurisdictionLevel',
                'country',
                'state',
                'taxType',
                'rateType',
                'displayName',
                'taxabilityReason',
            ],
            columns: [
                'displayName' => [],
                'amount' => ['align' => 'right'],
                'currency' => [],
                'percentage' => ['align' => 'right'],
            ],
            types: [
                'providerAmount' => 'number',
                'providerTaxableAmount' => 'number',
                'inclusive' => 'toggle',
            ],
        );
    }

    /** @return array<string, mixed> */
    private static function paymentField(): array
    {
        $field = self::objectField([
            'status',
            'currency',
            'amount',
            'amountReceived',
            'amountCaptured',
            'methodType',
            'paymentIntentStatus',
            'chargeStatus',
            'failureCode',
            'stripePaymentIntentId',
            'stripeChargeId',
            'stripePaymentMethodId',
            'createdAt',
            'chargeCreatedAt',
            'chargePaid',
            'chargeCaptured',
        ]);

        foreach (['chargePaid', 'chargeCaptured'] as $flag) {
            $field['fields'][$flag]['type'] = 'toggle';
        }

        return $field;
    }

    /** @return array<string, mixed> */
    private static function initiatingLineItemsField(): array
    {
        $field = self::structureField(
            members: [
                'reference',
                'name',
                'description',
                'quantity',
                'images',
                'metadata',
                'options',
                'sku',
                'variantId',
                'requiresShipping',
                'priceSource',
                'stripePriceId',
                'stripeProductId',
                'taxCode',
                'currency',
                'price',
                'providerPrice',
                'subtotal',
                'providerSubtotal',
            ],
            columns: [
                'name' => [],
                'quantity' => ['align' => 'right'],
                'subtotal' => ['align' => 'right'],
                'currency' => [],
            ],
            types: [
                'quantity' => 'number',
                'providerPrice' => 'number',
                'providerSubtotal' => 'number',
                'requiresShipping' => 'toggle',
            ],
            help: 'programmatordev.stripe-checkout.orders.initiatingLineItemsHelp',
        );
        $field['fields']['options'] = self::nestedField('options', self::structureField([
            'optionId',
            'optionName',
            'valueId',
            'valueName',
        ]));
        $field['fields']['images']['type'] = 'tags';
        $field['fields']['metadata'] = self::nestedField('metadata', self::factsField());

        return $field;
    }

    /** @return array<string, mixed> */
    private static function eventsField(): array
    {
        return self::structureField(
            members: [
                'id',
                'type',
                'createdAt',
                'resourceId',
                'stripePaymentIntentId',
                'stripeChargeId',
                'status',
                'attempts',
                'lastAttemptAt',
                'errorCode',
            ],
            columns: [
                'type' => [],
                'status' => [],
                'attempts' => ['align' => 'right'],
                'lastAttemptAt' => [],
            ],
            types: ['attempts' => 'number'],
        );
    }

    /** @return array<string, mixed> */
    private static function lifecycleDeliveriesField(): array
    {
        $field = self::structureField(
            members: [
                'deliveryId',
                'type',
                'pageUuid',
                'occurredAt',
                'revision',
                'languageCode',
                'checkoutStatus',
                'paymentStatus',
                'refundStatus',
                'disputeStatus',
                'triggerType',
                'triggerId',
                'actionFingerprint',
                'nextActionType',
                'createdAt',
                'expiresAt',
                'payloadRetained',
                'payloadPrunedAt',
                'status',
                'attempts',
                'lastAttemptAt',
                'errorCode',
            ],
            columns: [
                'type' => [],
                'status' => [],
                'attempts' => ['align' => 'right'],
                'occurredAt' => [],
            ],
            types: [
                'revision' => 'number',
                'attempts' => 'number',
                'payloadRetained' => 'toggle',
            ],
        );

        foreach (['occurredAt', 'createdAt', 'expiresAt', 'payloadPrunedAt', 'lastAttemptAt'] as $timestamp) {
            $field['fields'][$timestamp] = [
                ...$field['fields'][$timestamp],
                ...self::dateProperties(),
            ];
        }

        return $field;
    }

    /** @return array<string, mixed> */
    private static function factsField(): array
    {
        return self::structureField(
            members: ['path', 'value'],
            columns: [
                'path' => [],
                'value' => [],
            ],
        );
    }

    /** @return array<string, mixed> */
    private static function collectionField(string $handle): array
    {
        $members = $handle === 'refunds'
            ? ['stripeRefundId', 'stripePaymentIntentId', 'stripeChargeId', 'currency', 'amount', 'status', 'reason', 'failureReason', 'pendingReason', 'createdAt', 'firstObservedAt', 'updatedAt']
            : ['stripeDisputeId', 'stripePaymentIntentId', 'stripeChargeId', 'currency', 'amount', 'status', 'reason', 'evidenceDueBy', 'evidenceHasEvidence', 'evidencePastDue', 'evidenceSubmissionCount', 'balanceTransactions', 'createdAt', 'firstObservedAt', 'updatedAt'];
        $field = self::structureField(
            members: $members,
            columns: [
                'amount' => ['align' => 'right'],
                'currency' => [],
                'status' => [],
                'firstObservedAt' => [],
            ],
        );
        $field['limit'] = 10;

        // Native dates display local ISO timestamps; provider-created Unix times remain exact values in text fields.
        foreach (['firstObservedAt', 'updatedAt'] as $dateField) {
            $field['fields'][$dateField] = [
                ...$field['fields'][$dateField],
                ...self::dateProperties(),
            ];
        }

        $field['fields']['createdAt']['help'] = 'programmatordev.stripe-checkout.orders.refundCreatedAtHelp';

        if ($handle === 'disputes') {
            foreach (['evidenceHasEvidence', 'evidencePastDue'] as $evidenceFlag) {
                $field['fields'][$evidenceFlag]['type'] = 'toggle';
            }

            $field['fields']['evidenceDueBy']['help'] = 'programmatordev.stripe-checkout.orders.disputeEvidenceDueByHelp';
            $field['fields']['balanceTransactions'] = self::nestedField('balanceTransactions', self::structureField(
                members: ['stripeBalanceTransactionId', 'currency', 'amount', 'fee', 'net', 'createdAt'],
                columns: [
                    'amount' => ['align' => 'right'],
                    'currency' => [],
                    'net' => ['align' => 'right'],
                ],
                help: 'programmatordev.stripe-checkout.orders.disputeBalanceHelp',
            ));
        }

        return $field;
    }

    /**
     * @param list<string> $members
     * @return array{type: 'object', fields: array<string, array<string, mixed>>}
     */
    private static function objectField(array $members): array
    {
        $fields = [];

        foreach ($members as $member) {
            $fields[$member] = self::member($member);
        }

        return [
            'type' => 'object',
            'fields' => $fields,
        ];
    }

    /**
     * @param list<string> $members
     * @param array<string, array<string, mixed>> $columns
     * @param array<string, string> $types
     * @return array{type: 'structure', fields: array<string, array<string, mixed>>, columns?: array<string, array<string, mixed>>, help?: string}
     */
    private static function structureField(
        array $members,
        array $columns = [],
        array $types = [],
        ?string $help = null,
    ): array {
        $fields = [];

        foreach ($members as $member) {
            $fields[$member] = self::member($member, $types[$member] ?? 'text');
        }

        $field = [
            'type' => 'structure',
            'fields' => $fields,
        ];

        if ($columns !== []) {
            $field['columns'] = $columns;
        }

        if ($help !== null) {
            $field['help'] = $help;
        }

        return $field;
    }

    /** @return array<string, mixed> */
    private static function member(string $name, string $type = 'text'): array
    {
        return [
            'label' => 'programmatordev.stripe-checkout.orders.fields.' . $name,
            'type' => $type,
            'translate' => false,
        ];
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function nestedField(string $name, array $field): array
    {
        return [
            ...self::member($name),
            ...$field,
        ];
    }

    /** @return array<string, mixed> */
    private static function dateProperties(): array
    {
        return [
            'type' => 'date',
            'time' => [
                'display' => 'HH:mm:ss',
                // Kirby otherwise rounds date/time values to its default five-minute step.
                'step' => [
                    'unit' => 'second',
                    'size' => 1,
                ],
            ],
        ];
    }
}
