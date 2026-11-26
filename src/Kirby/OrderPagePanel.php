<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use DateTimeImmutable;
use Kirby\Cms\Language;
use Kirby\Form\Fields;
use Kirby\Panel\Page;
use Kirby\Panel\Panel;
use Kirby\Panel\Ui\Buttons\ViewButtons;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Lifecycle\Internal\HookDeliveryLedger;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSchema;
use ProgrammatorDev\StripeCheckout\Order\Internal\RetentionPolicy;
use ProgrammatorDev\StripeCheckout\Order\PaymentAction;
use ProgrammatorDev\StripeCheckout\Translation\Catalogue;

/** @internal Customizes the native Order Page presentation without replacing its route or form. */
final class OrderPagePanel extends Page
{
    public const PREFIX = OrderSchema::PRESENTATION_FIELD_PREFIX;

    /**
     * Share one validated snapshot between the native versions and buttons projections.
     *
     * @var array<string, mixed>|null
     */
    private ?array $orderData = null;

    /** @var list<string> */
    public const CHECKOUT_ATTEMPT_FIELDS = [
        'tokenHash',
        'bindingFingerprint',
        'requestFingerprint',
        'idempotencyKey',
        'stripeApiVersion',
        'credentialMode',
        'credentialFingerprint',
        'operation',
        'retryUntil',
        'source',
        'cartRevision',
        'guestReference',
        'uiMode',
        'initiatingUrl',
        'successUrl',
        'cancelUrl',
        'returnUrl',
    ];

    /** @return list<array{label: string, link: string}> */
    public function breadcrumb(): array
    {
        return [[
            'label' => self::translate('tabs.orders'),
            'link' => Panel::url('stripe-checkout') . '?tab=orders',
        ], [
            'label' => $this->model->title()->toString(),
            'link' => $this->url(true),
        ]];
    }

    /** @return array<mixed> */
    public function buttons(): array
    {
        $model = $this->model;

        return ViewButtons::view($this)
            ->bind([
                'hasRetryableLifecycleDelivery' => $this->hasRetryableLifecycleDelivery(),
                'isManuallyDeletable' => $this->isManuallyDeletable(),
                'orderId' => $model instanceof OrderPage ? $model->slug() : null,
                'stripeCheckoutSessionId' => $this->stripeCheckoutSessionId(),
                'stripeDashboardUrl' => $this->stripeDashboardUrl(),
            ])
            ->defaults('open', 'preview', '-', 'settings', 'languages', 'status')
            ->render();
    }

    /** @return array<mixed> */
    public function view(): array
    {
        // Orders use Kirby's native Page route; replace only its site-area
        // navigation metadata with the owning plugin area.
        return [
            ...parent::view(),
            'breadcrumbLabel' => self::translate('area.label'),
            'icon' => 'credit-card',
            'link' => Panel::url('stripe-checkout'),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function versions(): array
    {
        $versions = parent::versions();
        $latest = self::map($versions['latest'] ?? null);
        $changes = self::map($versions['changes'] ?? null);
        $model = $this->model;

        if ($model instanceof OrderPage === false) {
            return [
                'latest' => $latest,
                'changes' => $changes,
            ];
        }

        $projectionInput = self::projection($this->orderData($model));

        // Fields formats the entire blueprint. Keep only the supplied projection
        // so empty display fields cannot overwrite the native version values.
        $projection = array_intersect_key(self::map(
            Fields::for($model, Language::ensure('current'))
                ->fill(
                    input: $projectionInput,
                    passthrough: false,
                )
                ->toFormValues(),
        ), $projectionInput);

        return [
            'latest' => [...$latest, ...$projection],
            'changes' => [...$changes, ...$projection],
        ];
    }

    public function stripeDashboardUrl(): ?string
    {
        $model = $this->model;

        if ($model instanceof OrderPage === false) {
            return null;
        }

        return self::stripeDashboardUrlFor($this->orderData($model));
    }

    private function stripeCheckoutSessionId(): ?string
    {
        $model = $this->model;

        if ($model instanceof OrderPage === false) {
            return null;
        }

        $data = $this->orderData($model);
        /** @var string|null $stripeCheckoutSessionId The stored order has already crossed the Order schema boundary. */
        $stripeCheckoutSessionId = $data['stripeCheckoutSessionId'] ?? null;

        return $stripeCheckoutSessionId;
    }

    private function hasRetryableLifecycleDelivery(): bool
    {
        $model = $this->model;

        if ($model instanceof OrderPage === false) {
            return false;
        }

        $data = $this->orderData($model);
        /** @var list<array<string, mixed>> $lifecycleDeliveries */
        $lifecycleDeliveries = $data['lifecycleDeliveries'];
        $attemptedAt = new DateTimeImmutable();

        foreach ($lifecycleDeliveries as $lifecycleDelivery) {
            if (HookDeliveryLedger::isFailedRetryEligible($lifecycleDelivery, $attemptedAt)) {
                return true;
            }
        }

        return false;
    }

    private function isManuallyDeletable(): bool
    {
        $model = $this->model;

        if ($model instanceof OrderPage === false) {
            return false;
        }

        return RetentionPolicy::isManuallyEligible(
            data: $this->orderData($model),
            now: new DateTimeImmutable(),
        );
    }

    public static function handle(string $name): string
    {
        return self::PREFIX . ucfirst($name);
    }

    public static function name(string $handle): ?string
    {
        if (str_starts_with($handle, self::PREFIX) === false) {
            return null;
        }

        return lcfirst(substr($handle, strlen(self::PREFIX)));
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private static function projection(array $order): array
    {
        $customer = self::map($order['customer'] ?? null);
        $shipping = self::map($order['shipping'] ?? null);
        $deliveryEstimate = self::map($shipping['deliveryEstimate'] ?? null);
        $tax = self::map($order['tax'] ?? null);
        $checkoutAttempt = self::map($order['checkoutAttempt'] ?? null);
        $providerFailure = self::map($checkoutAttempt['providerFailure'] ?? null);
        $values = [
            self::handle('customerSummary') => self::customerSummary($customer),
            self::handle('orderItems') => self::orderItems($order),
            self::handle('shippingMethod') => $shipping['label'] ?? null,
            self::handle('deliveryEstimate') => self::deliveryEstimate($deliveryEstimate),
            self::handle('orderDiscounts') => self::list($order['discounts'] ?? null),
            self::handle('orderTaxBreakdown') => self::orderTaxBreakdown($tax['breakdown'] ?? null),
            self::handle('customerTaxIds') => self::list($customer['taxIds'] ?? null),
            self::handle('checkoutSessionRequest') => self::facts($checkoutAttempt['sessionRequest'] ?? null),
            self::handle('checkoutFailureType') => $providerFailure['type'] ?? null,
            self::handle('checkoutFailureRequestId') => $providerFailure['requestId'] ?? null,
            self::handle('checkoutFailureProviderCode') => $providerFailure['providerCode'] ?? null,
            self::handle('checkoutFailureProviderType') => $providerFailure['providerType'] ?? null,
            self::handle('checkoutFailureRetryable') => $providerFailure['retryable'] ?? null,
            self::handle('checkoutFailureOccurredAt') => $providerFailure['occurredAt'] ?? null,
            'initiatingLineItems' => self::initiatingLineItems($order['initiatingLineItems'] ?? null),
            'lifecycleDeliveries' => self::lifecycleDeliveries($order['lifecycleDeliveries'] ?? null),
        ];

        foreach (self::CHECKOUT_ATTEMPT_FIELDS as $field) {
            $values[self::handle('checkoutAttempt' . ucfirst($field))] = $checkoutAttempt[$field] ?? null;
        }

        $normalized = [];

        // Kirby normalizes form field handles before matching view values.
        foreach ($values as $field => $value) {
            $normalized[strtolower($field)] = $value;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $order */
    private static function stripeDashboardUrlFor(array $order): ?string
    {
        $stripeCheckoutSessionId = OrderData::nullableString($order['stripeCheckoutSessionId'] ?? null);
        $checkoutAttempt = OrderData::map($order['checkoutAttempt'] ?? null);
        $credentialMode = CredentialMode::from(OrderData::string($checkoutAttempt['credentialMode'] ?? null));

        if ($stripeCheckoutSessionId === null || $credentialMode === CredentialMode::Unknown) {
            return null;
        }

        // Stripe Dashboard deep links insert /test for sandbox resources.
        // https://docs.stripe.com/stripe-apps/deep-links
        $modePath = $credentialMode === CredentialMode::Test ? 'test/' : '';

        return 'https://dashboard.stripe.com/' . $modePath . 'checkout/sessions/' . rawurlencode($stripeCheckoutSessionId);
    }

    private static function translate(string $suffix): string
    {
        $translation = I18n::translate(Catalogue::PREFIX . $suffix);

        return is_string($translation) ? $translation : $suffix;
    }

    /** @return array<string, mixed> */
    private function orderData(OrderPage $model): array
    {
        return $this->orderData ??= (new OrderPageStore($model->kirby()))->data($model);
    }

    /** @return list<array<string, mixed>> */
    private static function initiatingLineItems(mixed $value): array
    {
        $items = [];

        foreach (self::list($value) as $item) {
            $item = self::map($item);
            $providerAmounts = self::map($item['providerAmounts'] ?? null);
            $items[] = [
                ...$item,
                'metadata' => self::facts($item['metadata'] ?? null),
                'providerPrice' => $providerAmounts['price'] ?? null,
                'providerSubtotal' => $providerAmounts['subtotal'] ?? null,
            ];
            unset($items[array_key_last($items)]['providerAmounts']);
        }

        return $items;
    }

    /** @param array<string, mixed> $customer */
    private static function customerSummary(array $customer): ?string
    {
        /** @var string|null $individualName */
        $individualName = $customer['individualName'] ?? null;
        /** @var string|null $businessName */
        $businessName = $customer['businessName'] ?? null;
        /** @var string|null $email */
        $email = $customer['email'] ?? null;
        $details = array_values(array_filter([$businessName, $individualName, $email]));

        return $details === [] ? null : implode(' — ', $details);
    }

    /** @param array<string, mixed> $deliveryEstimate */
    private static function deliveryEstimate(array $deliveryEstimate): ?string
    {
        if ($deliveryEstimate === []) {
            return null;
        }

        /** @var int|null $minimum */
        $minimum = $deliveryEstimate['minimum'];
        /** @var int|null $maximum */
        $maximum = $deliveryEstimate['maximum'];
        /** @var string $unit */
        $unit = $deliveryEstimate['unit'];
        $range = match (true) {
            $minimum === null => (string) $maximum,
            $maximum === null, $minimum === $maximum => (string) $minimum,
            default => $minimum . '–' . $maximum,
        };
        $count = $maximum ?? $minimum;
        $unitLabel = self::translate('orders.deliveryUnits.' . $unit . '.' . ($count === 1 ? 'one' : 'other'));

        return $range . ' ' . $unitLabel;
    }

    /**
     * Stripe can return line items before Checkout completes. Until completion,
     * the Panel presents the frozen initiating items instead of implying that
     * provider amounts are final.
     *
     * @param array<string, mixed> $order
     * @return list<array<string, mixed>>
     */
    private static function orderItems(array $order): array
    {
        $initiatingLineItems = self::list($order['initiatingLineItems'] ?? null);
        $lineItems = self::list($order['lineItems'] ?? null);
        $checkoutStatus = CheckoutStatus::from(OrderData::string($order['checkoutStatus'] ?? null));

        if ($checkoutStatus !== CheckoutStatus::Complete || $lineItems === []) {
            return array_map(static function (mixed $value): array {
                $initiatingLineItem = self::map($value);

                return self::orderItem(
                    initiatingLineItem: $initiatingLineItem,
                    lineItem: null,
                );
            }, $initiatingLineItems);
        }

        return array_map(static function (mixed $value) use ($initiatingLineItems): array {
            $lineItem = self::map($value);
            /** @var int $initiatingIndex The stored order has already crossed the Order schema boundary. */
            $initiatingIndex = $lineItem['initiatingIndex'];

            return self::orderItem(
                initiatingLineItem: self::map($initiatingLineItems[$initiatingIndex]),
                lineItem: $lineItem,
            );
        }, $lineItems);
    }

    /**
     * @param array<string, mixed> $initiatingLineItem
     * @param array<string, mixed>|null $lineItem
     * @return array<string, mixed>
     */
    private static function orderItem(array $initiatingLineItem, ?array $lineItem): array
    {
        $options = self::list($initiatingLineItem['options'] ?? null);
        $optionLabels = array_map(static function (mixed $value): string {
            $option = self::map($value);
            /** @var string $optionName */
            $optionName = $option['optionName'];
            /** @var string $valueName */
            $valueName = $option['valueName'];

            return $optionName . ': ' . $valueName;
        }, $options);
        $amounts = $lineItem ?? $initiatingLineItem;

        return [
            'name' => $initiatingLineItem['name'] ?? $lineItem['description'] ?? null,
            'description' => $initiatingLineItem['description'] ?? null,
            'optionSummary' => $optionLabels === [] ? null : implode(' · ', $optionLabels),
            'options' => $options,
            'sku' => $initiatingLineItem['sku'] ?? null,
            'quantity' => $amounts['quantity'] ?? null,
            'requiresShipping' => $initiatingLineItem['requiresShipping'] ?? null,
            'currency' => $amounts['currency'] ?? null,
            'price' => $amounts['price'] ?? null,
            'subtotal' => $amounts['subtotal'] ?? null,
            'discount' => $lineItem['discount'] ?? null,
            'tax' => $lineItem['tax'] ?? null,
            'total' => $lineItem['total'] ?? $initiatingLineItem['subtotal'] ?? null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function orderTaxBreakdown(mixed $value): array
    {
        return array_map(static function (mixed $value): array {
            $tax = self::map($value);
            /** @var string $target */
            $target = $tax['target'];

            return [
                ...$tax,
                'targetLabel' => self::translate('orders.taxTargets.' . $target),
            ];
        }, self::list($value));
    }

    /** @return list<array<string, mixed>> */
    private static function lifecycleDeliveries(mixed $value): array
    {
        $deliveries = [];

        foreach (self::list($value) as $delivery) {
            $delivery = self::map($delivery);
            $event = self::map($delivery['event'] ?? null);
            $payloadRetained = array_key_exists('orderSnapshot', $event);
            $nextActionType = self::nextActionType($event['nextAction'] ?? null);
            unset($event['orderSnapshot'], $event['nextAction']);

            // Payload retention exists so failed hooks can replay the original event.
            // Those payloads can contain customer snapshots and private payment handoff data,
            // so orders.read exposes only their availability and safe action type.
            $deliveries[] = [
                ...$delivery,
                ...$event,
                'payloadRetained' => $payloadRetained,
                'nextActionType' => $nextActionType,
            ];
            unset($deliveries[array_key_last($deliveries)]['event']);
        }

        return $deliveries;
    }

    /**
     * Flattens arbitrary provider facts into homogeneous rows that Kirby's
     * native Structure field can display without exposing a raw data dump.
     *
     * @return list<array{path: string, value: mixed}>
     */
    private static function facts(mixed $value, string $path = ''): array
    {
        if (is_array($value)) {
            if ($value === []) {
                return $path === '' ? [] : [['path' => $path, 'value' => null]];
            }

            $facts = [];

            foreach ($value as $key => $entry) {
                $entryPath = $path === ''
                    ? (string) $key
                    : $path . (is_int($key) ? '[' . $key . ']' : '.' . $key);
                $facts = [...$facts, ...self::facts($entry, $entryPath)];
            }

            return $facts;
        }

        return [['path' => $path, 'value' => is_bool($value) ? ($value ? 'true' : 'false') : $value]];
    }

    private static function nextActionType(mixed $value): ?string
    {
        return is_string($value) ? PaymentAction::fromJson($value)->type() : null;
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        if (is_array($value) === false || array_is_list($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $entry) {
            if (is_string($key)) {
                $map[$key] = $entry;
            }
        }

        return $map;
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }
}
