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
            'overview' => [
                'status' => ['orderNumber', 'checkoutStatus', 'paymentStatus', 'refundStatus', 'disputeStatus'],
                'totals' => ['currency', ...OrderSchema::AMOUNTS],
                'keyDates' => ['createdAt', 'updatedAt', 'checkoutCompletedAt', 'paidAt', 'paymentFailedAt'],
            ],
            'purchase' => [
                'items' => ['lineItems', 'initiatingLineItems'],
                'adjustments' => [
                    'shipping',
                    OrderPagePanel::handle('shippingDeliveryMinimum'),
                    OrderPagePanel::handle('shippingDeliveryMaximum'),
                    OrderPagePanel::handle('shippingDeliveryUnit'),
                    'discounts',
                    'tax',
                    OrderPagePanel::handle('taxBreakdown'),
                ],
            ],
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
        $tabs = [];

        foreach ($sections as $tabName => $tabSections) {
            $tabs[$tabName] = self::tab($tabName, $tabSections);
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
            'customerTaxIds' => self::structureField(['type', 'value']),
            'shippingDeliveryMinimum', 'shippingDeliveryMaximum' => ['type' => 'number'],
            'taxBreakdown' => self::taxBreakdownField(),
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
            'shippingDeliveryMinimum' => 'deliveryMinimum',
            'shippingDeliveryMaximum' => 'deliveryMaximum',
            'shippingDeliveryUnit' => 'deliveryUnit',
            'taxBreakdown' => 'breakdown',
            'checkoutSessionRequest' => 'sessionRequest',
            'checkoutFailureType' => 'failureType',
            'checkoutFailureRequestId' => 'requestId',
            'checkoutFailureProviderCode' => 'providerCode',
            'checkoutFailureProviderType' => 'providerType',
            'checkoutFailureRetryable' => 'retryable',
            'checkoutFailureOccurredAt' => 'occurredAt',
        ];

        if (isset($labels[$name])) {
            return $labels[$name];
        }

        if (str_starts_with($name, 'checkoutAttempt')) {
            return lcfirst(substr($name, strlen('checkoutAttempt')));
        }

        return $name;
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

            $fields = [];

            foreach ($handles as $handle) {
                $fields[$handle] = self::field($handle);
            }

            $blueprintSections[$sectionName] = [
                'type' => 'fields',
                'headline' => 'programmatordev.stripe-checkout.orders.sections.' . $sectionName,
                'fields' => $fields,
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
    private static function customerField(): array
    {
        $field = self::objectField(['email', 'individualName', 'businessName', 'phone']);
        $field['fields']['email']['type'] = 'email';
        $field['fields']['phone']['type'] = 'tel';

        return $field;
    }

    /** @return array<string, mixed> */
    private static function addressField(): array
    {
        return self::objectField(['name', 'line1', 'line2', 'postalCode', 'city', 'state', 'country']);
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
            'taxBehavior',
            'taxCode',
        ]);

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
        ]);
        $field['fields']['automaticTaxEnabled']['type'] = 'toggle';
        $field['fields']['providerAmount']['type'] = 'number';

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
                'createdAt',
                'expiresAt',
                'payloadPrunedAt',
                'status',
                'attempts',
                'lastAttemptAt',
                'errorCode',
                'payload',
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
            ],
        );

        foreach (['occurredAt', 'createdAt', 'expiresAt', 'payloadPrunedAt', 'lastAttemptAt'] as $timestamp) {
            $field['fields'][$timestamp] = [
                ...$field['fields'][$timestamp],
                ...self::dateProperties(),
            ];
        }

        $field['fields']['payload'] = self::nestedField('payload', self::factsField());

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
            'disabled' => true,
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
