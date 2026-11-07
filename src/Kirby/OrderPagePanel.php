<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\Language;
use Kirby\Form\Fields;
use Kirby\Panel\Page;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSchema;

/** @internal Adds display-only values to the native Order Page view. */
final class OrderPagePanel extends Page
{
    public const PREFIX = OrderSchema::PRESENTATION_FIELD_PREFIX;

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

        $projectionInput = self::projection((new OrderPageStore($model->kirby()))->data($model));

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
            self::handle('customerTaxIds') => self::list($customer['taxIds'] ?? null),
            self::handle('shippingDeliveryMinimum') => $deliveryEstimate['minimum'] ?? null,
            self::handle('shippingDeliveryMaximum') => $deliveryEstimate['maximum'] ?? null,
            self::handle('shippingDeliveryUnit') => $deliveryEstimate['unit'] ?? null,
            self::handle('taxBreakdown') => self::list($tax['breakdown'] ?? null),
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

    /** @return list<array<string, mixed>> */
    private static function lifecycleDeliveries(mixed $value): array
    {
        $deliveries = [];

        foreach (self::list($value) as $delivery) {
            $delivery = self::map($delivery);
            $event = self::map($delivery['event'] ?? null);
            $payload = [
                'orderSnapshot' => $event['orderSnapshot'] ?? null,
                'nextAction' => self::nextAction($event['nextAction'] ?? null),
            ];
            unset($event['orderSnapshot'], $event['nextAction']);
            $deliveries[] = [
                ...$delivery,
                ...$event,
                'payload' => self::facts($payload),
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

    private static function nextAction(mixed $value): mixed
    {
        return is_string($value)
            ? json_decode($value, true, flags: JSON_THROW_ON_ERROR)
            : $value;
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
