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
        $this->assertSame(['overview', 'customer', 'payment', 'technical'], array_keys($tabs));
        $this->assertSame(
            ['stripeCheckoutOrderSync', 'stripeCheckoutOrderRetryLifecycleDelivery', 'stripeCheckoutOrderOpenInStripe', 'stripeCheckoutOrderDelete'],
            $blueprint['buttons'] ?? null,
        );

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

    public function testPresentsOneOperatorItemCollectionWithoutReplacingCanonicalSnapshots(): void
    {
        $blueprint = OrderBlueprint::load();
        $tabs = self::map($blueprint['tabs'] ?? null);
        $overview = self::map($tabs['overview'] ?? null);
        $sections = self::map($overview['sections'] ?? null);
        $items = self::map($sections['items'] ?? null);
        $itemFields = self::map($items['fields'] ?? null);
        $headline = self::map($itemFields[OrderPagePanel::handle('itemsHeadline')] ?? null);
        $this->assertSame('headline', $headline['type'] ?? null);
        $orderItems = self::map($itemFields[OrderPagePanel::handle('orderItems')] ?? null);
        $this->assertSame('structure', $orderItems['type'] ?? null);
        $fields = self::map($orderItems['fields'] ?? null);
        $this->assertSame([
            'name',
            'description',
            'optionSummary',
            'options',
            'sku',
            'quantity',
            'requiresShipping',
            'currency',
            'price',
            'subtotal',
            'discount',
            'tax',
            'total',
        ], array_keys($fields));

        $technical = self::map($tabs['technical'] ?? null);
        $technicalSections = self::map($technical['sections'] ?? null);
        $operations = self::map($technicalSections['operations'] ?? null);
        $technicalFields = self::map($operations['fields'] ?? null);
        $this->assertSame('structure', self::map($technicalFields['lineItems'] ?? null)['type'] ?? null);
        $this->assertSame('structure', self::map($technicalFields['initiatingLineItems'] ?? null)['type'] ?? null);
    }

    public function testIncompleteCheckoutFactsUseNativeStateAwarePresentation(): void
    {
        $blueprint = OrderBlueprint::load();
        $tabs = self::map($blueprint['tabs'] ?? null);
        $overview = self::map($tabs['overview'] ?? null);
        $sections = self::map($overview['sections'] ?? null);
        $summary = self::map($sections['summary'] ?? null);
        $summaryFields = self::map($summary['fields'] ?? null);
        $stateInfoFields = [
            'checkoutCreatingInfo' => [
                'checkoutStatus' => 'creating',
                'theme' => 'info',
            ],
            'checkoutCreationUncertainInfo' => [
                'checkoutStatus' => 'creation_uncertain',
                'theme' => 'warning',
            ],
            'checkoutCreationFailedInfo' => [
                'checkoutStatus' => 'creation_failed',
                'theme' => 'negative',
            ],
            'checkoutOpenInfo' => [
                'checkoutStatus' => 'open',
                'theme' => 'info',
            ],
            'checkoutExpiredInfo' => [
                'checkoutStatus' => 'expired',
                'theme' => 'warning',
            ],
        ];

        foreach ($stateInfoFields as $name => $expected) {
            $stateInfo = self::map($summaryFields[OrderPagePanel::handle($name)] ?? null);
            $this->assertSame('info', $stateInfo['type'] ?? null);
            $this->assertSame($expected['theme'], $stateInfo['theme'] ?? null);
            $this->assertSame(
                ['checkoutStatus' => $expected['checkoutStatus']],
                $stateInfo['when'] ?? null,
            );
        }

        $totals = self::map($sections['totals'] ?? null);
        $totalFields = self::map($totals['fields'] ?? null);

        foreach (['discountTotal', 'shippingTotal', 'taxTotal', 'total', 'refundedTotal'] as $handle) {
            $this->assertSame(
                ['checkoutStatus' => 'complete'],
                self::map($totalFields[$handle] ?? null)['when'] ?? null,
            );
        }

        $adjustments = self::map($sections['adjustments'] ?? null);
        $adjustmentFields = self::map($adjustments['fields'] ?? null);

        foreach ($adjustmentFields as $field) {
            $this->assertSame(['checkoutStatus' => 'complete'], self::map($field)['when'] ?? null);
        }
    }

    public function testPresentsOperatorRelevantInitiatingAndTaxFacts(): void
    {
        $blueprint = OrderBlueprint::load();
        $tabs = self::map($blueprint['tabs'] ?? null);
        $technical = self::map($tabs['technical'] ?? null);
        $technicalSections = self::map($technical['sections'] ?? null);
        $operations = self::map($technicalSections['operations'] ?? null);
        $technicalFields = self::map($operations['fields'] ?? null);
        $initiatingLineItems = self::map($technicalFields['initiatingLineItems'] ?? null);
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

        $overview = self::map($tabs['overview'] ?? null);
        $sections = self::map($overview['sections'] ?? null);
        $adjustments = self::map($sections['adjustments'] ?? null);
        $adjustmentFields = self::map($adjustments['fields'] ?? null);
        $breakdown = self::map($adjustmentFields[OrderPagePanel::handle('orderTaxBreakdown')] ?? null);
        $breakdownFields = self::map($breakdown['fields'] ?? null);
        $this->assertSame(['targetLabel', 'displayName', 'amount', 'currency', 'percentage', 'jurisdiction'], array_keys($breakdownFields));
        $discounts = self::map($adjustmentFields[OrderPagePanel::handle('orderDiscounts')] ?? null);
        $discountFields = self::map($discounts['fields'] ?? null);
        $this->assertSame(['couponName', 'promotionCode', 'amount', 'currency', 'percentOff'], array_keys($discountFields));
        $this->assertSame('1/2', self::map($adjustmentFields[OrderPagePanel::handle('shippingMethod')] ?? null)['width'] ?? null);
        $this->assertSame('1/2', self::map($adjustmentFields[OrderPagePanel::handle('deliveryEstimate')] ?? null)['width'] ?? null);

        $shipping = self::map($technicalFields['shipping'] ?? null);
        $shippingFields = self::map($shipping['fields'] ?? null);
        $this->assertArrayHasKey('deliveryEstimate', $shippingFields);
        $this->assertArrayHasKey('providerSubtotal', $shippingFields);
        $tax = self::map($technicalFields['tax'] ?? null);
        $taxFields = self::map($tax['fields'] ?? null);
        $technicalBreakdown = self::map($taxFields['breakdown'] ?? null);
        $technicalBreakdownFields = self::map($technicalBreakdown['fields'] ?? null);
        $this->assertArrayHasKey('providerAmount', $technicalBreakdownFields);
        $this->assertArrayHasKey('providerTaxableAmount', $technicalBreakdownFields);
        $this->assertArrayHasKey('rateId', $technicalBreakdownFields);
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
        $this->assertSame('toggle', self::map($deliveryFields['payloadRetained'] ?? null)['type'] ?? null);
        $this->assertArrayHasKey('nextActionType', $deliveryFields);
        $this->assertArrayNotHasKey('payload', $deliveryFields);

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
