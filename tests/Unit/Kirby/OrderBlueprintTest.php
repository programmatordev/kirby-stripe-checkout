<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Kirby;

use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Kirby\OrderBlueprint;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPagePanel;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSchema;

final class OrderBlueprintTest extends TestCase
{
    public function testOrganizesCanonicalOrderFactsForShopOperators(): void
    {
        $blueprint = OrderBlueprint::load();
        $tabs = $blueprint['tabs'] ?? null;
        $this->assertIsArray($tabs);
        $this->assertSame(['overview', 'purchase', 'customer', 'payment', 'technical'], array_keys($tabs));

        $fields = [];
        $handles = [];

        foreach ($tabs as $tab) {
            $this->assertIsArray($tab);
            $sections = $tab['sections'] ?? null;
            $this->assertIsArray($sections);

            foreach ($sections as $section) {
                $this->assertIsArray($section);
                $sectionFields = $section['fields'] ?? null;
                $this->assertIsArray($sectionFields);
                $fields = [...$fields, ...$sectionFields];
                $handles = [...$handles, ...array_keys($sectionFields)];
            }
        }

        $canonicalHandles = array_values(array_filter(
            $handles,
            static fn(string $handle): bool => OrderPagePanel::name($handle) === null,
        ));
        $this->assertEqualsCanonicalizing(
            array_values(array_diff(OrderSchema::fields(), ['checkoutAttempt'])),
            $canonicalHandles,
        );
        $this->assertCount(count($handles), array_unique($handles));

        foreach (array_diff($handles, $canonicalHandles) as $presentationHandle) {
            $this->assertTrue(OrderSchema::isReserved($presentationHandle));
        }

        $this->assertSame('select', self::map($fields['checkoutStatus'] ?? null)['type'] ?? null);
        $this->assertSame('date', self::map($fields['createdAt'] ?? null)['type'] ?? null);
        $this->assertSame('toggle', self::map($fields['refundRequiresAction'] ?? null)['type'] ?? null);
        $this->assertSame('structure', self::map($fields['lineItems'] ?? null)['type'] ?? null);
        $customer = self::map($fields['customer'] ?? null);
        $customerFields = self::map($customer['fields'] ?? null);
        $this->assertSame('object', $customer['type'] ?? null);
        $this->assertSame('email', self::map($customerFields['email'] ?? null)['type'] ?? null);
        $this->assertSame('tel', self::map($customerFields['phone'] ?? null)['type'] ?? null);
        $this->assertSame('object', self::map($fields['payment'] ?? null)['type'] ?? null);
    }

    public function testFinalItemsUseTheAuthoritativeCheckoutProjection(): void
    {
        $blueprint = OrderBlueprint::load();
        $tabs = self::map($blueprint['tabs'] ?? null);
        $purchase = self::map($tabs['purchase'] ?? null);
        $sections = self::map($purchase['sections'] ?? null);
        $items = self::map($sections['items'] ?? null);
        $itemFields = self::map($items['fields'] ?? null);
        $lineItems = self::map($itemFields['lineItems'] ?? null);
        $fields = self::map($lineItems['fields'] ?? null);
        $this->assertSame([
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
        ], array_keys($fields));
        $this->assertArrayNotHasKey('reference', $fields);
        $this->assertArrayNotHasKey('name', $fields);
    }

    public function testPresentsOperatorRelevantInitiatingAndTaxFacts(): void
    {
        $blueprint = OrderBlueprint::load();
        $tabs = self::map($blueprint['tabs'] ?? null);
        $purchase = self::map($tabs['purchase'] ?? null);
        $sections = self::map($purchase['sections'] ?? null);
        $items = self::map($sections['items'] ?? null);
        $itemFields = self::map($items['fields'] ?? null);
        $initiatingLineItems = self::map($itemFields['initiatingLineItems'] ?? null);
        $initiatingFields = self::map($initiatingLineItems['fields'] ?? null);
        $this->assertSame([
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
        ], array_keys($initiatingFields));
        $options = self::map($initiatingFields['options'] ?? null);
        $optionFields = self::map($options['fields'] ?? null);
        $this->assertSame(['optionId', 'optionName', 'valueId', 'valueName'], array_keys($optionFields));

        $adjustments = self::map($sections['adjustments'] ?? null);
        $adjustmentFields = self::map($adjustments['fields'] ?? null);
        $breakdown = self::map($adjustmentFields[OrderPagePanel::handle('taxBreakdown')] ?? null);
        $breakdownFields = self::map($breakdown['fields'] ?? null);
        $this->assertArrayHasKey('providerAmount', $breakdownFields);
        $this->assertArrayHasKey('providerTaxableAmount', $breakdownFields);
        $this->assertArrayHasKey('rateId', $breakdownFields);
        $this->assertArrayHasKey('jurisdictionLevel', $breakdownFields);
        $this->assertArrayHasKey('rateType', $breakdownFields);

        $shipping = self::map($adjustmentFields['shipping'] ?? null);
        $shippingFields = self::map($shipping['fields'] ?? null);
        $this->assertArrayNotHasKey('deliveryEstimate', $shippingFields);
        $this->assertArrayHasKey('providerSubtotal', $shippingFields);
        $this->assertSame('number', self::map($adjustmentFields[OrderPagePanel::handle('shippingDeliveryMinimum')] ?? null)['type'] ?? null);
    }

    public function testPresentsOperationalCollectionsAsNativeStructures(): void
    {
        $blueprint = OrderBlueprint::load();
        $tabs = self::map($blueprint['tabs'] ?? null);
        $technical = self::map($tabs['technical'] ?? null);
        $sections = self::map($technical['sections'] ?? null);
        $operations = self::map($sections['operations'] ?? null);
        $fields = self::map($operations['fields'] ?? null);
        $this->assertSame('structure', self::map($fields['events'] ?? null)['type'] ?? null);
        $lifecycleDeliveries = self::map($fields['lifecycleDeliveries'] ?? null);
        $this->assertSame('structure', $lifecycleDeliveries['type'] ?? null);
        $deliveryFields = self::map($lifecycleDeliveries['fields'] ?? null);
        $payload = self::map($deliveryFields['payload'] ?? null);
        $this->assertSame('structure', $payload['type'] ?? null);

        $checkoutAttempt = self::map($sections['checkoutAttempt'] ?? null);
        $attemptFields = self::map($checkoutAttempt['fields'] ?? null);
        $this->assertArrayHasKey(OrderPagePanel::handle('checkoutAttemptTokenHash'), $attemptFields);
        $request = self::map($attemptFields[OrderPagePanel::handle('checkoutSessionRequest')] ?? null);
        $this->assertSame('structure', $request['type'] ?? null);
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);
        $map = [];

        foreach ($value as $key => $entry) {
            self::assertIsString($key);
            $map[$key] = $entry;
        }

        return $map;
    }
}
