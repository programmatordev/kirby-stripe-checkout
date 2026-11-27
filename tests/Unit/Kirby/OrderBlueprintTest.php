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
            $tab = self::map($tab);

            foreach (self::tabFieldCollections($tab) as $sectionFields) {
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
        $this->assertTrue(self::map($fields['checkoutStatus'] ?? null)['disabled'] ?? false);
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
        $fields = self::tabFields($overview);
        $orderItems = self::map($fields[OrderPagePanel::handle('orderItems')] ?? null);
        $this->assertSame('structure', $orderItems['type'] ?? null);
        $this->assertTrue($orderItems['disabled'] ?? false);
        $fields = self::map($orderItems['fields'] ?? null);
        $this->assertSame([
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
        ], array_keys($fields));
        $this->assertSame('tags', self::map($fields['optionSummary'] ?? null)['type'] ?? null);
        $this->assertFalse(self::map($fields['name'] ?? null)['disabled'] ?? false);

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
        $fields = self::tabFields($overview);
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
            $stateInfo = self::map($fields[OrderPagePanel::handle($name)] ?? null);
            $this->assertSame('info', $stateInfo['type'] ?? null);
            $this->assertSame($expected['theme'], $stateInfo['theme'] ?? null);
            $this->assertSame(
                ['checkoutStatus' => $expected['checkoutStatus']],
                $stateInfo['when'] ?? null,
            );
        }

        $this->assertSame(
            ['checkoutStatus' => 'complete'],
            self::map($fields[OrderPagePanel::handle('formattedCheckoutCompletedAt')] ?? null)['when'] ?? null,
        );
        $this->assertSame(
            ['paymentStatus' => 'paid'],
            self::map($fields[OrderPagePanel::handle('formattedPaidAt')] ?? null)['when'] ?? null,
        );
        $this->assertSame(
            ['paymentStatus' => 'failed'],
            self::map($fields[OrderPagePanel::handle('formattedPaymentFailedAt')] ?? null)['when'] ?? null,
        );
    }

    public function testOverviewExposesConditionalFinancialAndFulfillmentFacts(): void
    {
        $blueprint = OrderBlueprint::load();
        $tabs = self::map($blueprint['tabs'] ?? null);
        $overview = self::map($tabs['overview'] ?? null);
        $fields = self::tabFields($overview);

        $completeCondition = ['checkoutStatus' => 'complete'];

        $statuses = self::map($fields[OrderPagePanel::handle('statusReports')] ?? null);
        $this->assertSame('stripe-checkout-order-status', $statuses['type'] ?? null);
        $this->assertSame('page.panel.statusReports', $statuses['reports'] ?? null);

        foreach (['formattedSubtotal', 'formattedDiscountTotal', 'formattedShippingTotal', 'formattedTaxTotal'] as $name) {
            $amount = self::map($fields[OrderPagePanel::handle($name)] ?? null);
            $this->assertSame($completeCondition, $amount['when'] ?? null);
        }

        $total = self::map($fields[OrderPagePanel::handle('formattedTotal')] ?? null);
        $this->assertSame($completeCondition, $total['when'] ?? null);
        $refundedTotal = self::map($fields[OrderPagePanel::handle('formattedRefundedTotal')] ?? null);
        $this->assertSame([
            'checkoutStatus' => 'complete',
            OrderPagePanel::handle('hasRefundedAmount') => true,
        ], $refundedTotal['when'] ?? null);

        $hasDiscounts = [OrderPagePanel::handle('hasDiscounts') => true];
        $this->assertSame(
            $hasDiscounts,
            self::map($fields[OrderPagePanel::handle('discountsHeadline')] ?? null)['when'] ?? null,
        );
        $orderDiscounts = self::map($fields[OrderPagePanel::handle('orderDiscounts')] ?? null);
        $this->assertSame($hasDiscounts, $orderDiscounts['when'] ?? null);
        $this->assertSame(
            ['couponName', 'promotionCode', 'amount', 'percentOff'],
            array_keys(self::map($orderDiscounts['fields'] ?? null)),
        );

        $customerSummary = self::map($fields[OrderPagePanel::handle('customerSummary')] ?? null);
        $customerSummaryFields = self::map($customerSummary['fields'] ?? null);
        $this->assertSame('object', $customerSummary['type'] ?? null);
        $this->assertSame('email', self::map($customerSummaryFields['email'] ?? null)['type'] ?? null);
        $this->assertSame('tel', self::map($customerSummaryFields['phone'] ?? null)['type'] ?? null);
        $this->assertTrue($customerSummary['disabled'] ?? false);

        $billingAddress = self::map($fields[OrderPagePanel::handle('billingAddressSummary')] ?? null);
        $billingAddressFields = self::map($billingAddress['fields'] ?? null);
        $this->assertSame('object', $billingAddress['type'] ?? null);
        $this->assertArrayHasKey('country', $billingAddressFields);
        $this->assertArrayHasKey('postalCode', $billingAddressFields);
        $this->assertTrue($billingAddress['disabled'] ?? false);

        $billingTaxIdType = self::map($fields[OrderPagePanel::handle('billingTaxIdType')] ?? null);
        $billingTaxId = self::map($fields[OrderPagePanel::handle('billingTaxId')] ?? null);
        $this->assertSame('text', $billingTaxIdType['type'] ?? null);
        $this->assertSame('text', $billingTaxId['type'] ?? null);
        $this->assertTrue($billingTaxIdType['disabled'] ?? false);
        $this->assertTrue($billingTaxId['disabled'] ?? false);

        $deliveryCondition = [
            'checkoutStatus' => 'complete',
            OrderPagePanel::handle('requiresShipping') => true,
        ];

        foreach (['shippingMethod', 'deliveryEstimate', 'shippingAddressSummary'] as $name) {
            $this->assertSame(
                $deliveryCondition,
                self::map($fields[OrderPagePanel::handle($name)] ?? null)['when'] ?? null,
            );
        }

        $notRequired = self::map($fields[OrderPagePanel::handle('shippingNotRequiredInfo')] ?? null);
        $this->assertSame('info', $notRequired['type'] ?? null);
        $this->assertSame([
            'checkoutStatus' => 'complete',
            OrderPagePanel::handle('requiresShipping') => false,
        ], $notRequired['when'] ?? null);
        $this->assertArrayHasKey(OrderPagePanel::handle('formattedCreatedAt'), $fields);
    }

    public function testKeepsDetailedInitiatingShippingAndTaxFactsInTechnicalDetails(): void
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

    /**
     * @param array<string, mixed> $tab
     * @return list<array<string, mixed>>
     */
    private static function tabFieldCollections(array $tab): array
    {
        $collections = [];
        $sections = $tab['sections'] ?? [];
        self::assertIsArray($sections);

        foreach ($sections as $section) {
            self::assertIsArray($section);
            $fields = $section['fields'] ?? null;
            self::assertIsArray($fields);
            $collections[] = self::map($fields);
        }

        $columns = $tab['columns'] ?? [];
        self::assertIsArray($columns);

        foreach ($columns as $column) {
            self::assertIsArray($column);

            if (isset($column['fields'])) {
                self::assertIsArray($column['fields']);
                $collections[] = self::map($column['fields']);
            }

            $columnSections = $column['sections'] ?? [];
            self::assertIsArray($columnSections);

            foreach ($columnSections as $section) {
                self::assertIsArray($section);

                if (isset($section['fields'])) {
                    self::assertIsArray($section['fields']);
                    $collections[] = self::map($section['fields']);
                }
            }
        }

        return $collections;
    }

    /**
     * @param array<string, mixed> $tab
     * @return array<string, mixed>
     */
    private static function tabFields(array $tab): array
    {
        $fields = [];

        foreach (self::tabFieldCollections($tab) as $collection) {
            $fields = [...$fields, ...$collection];
        }

        return $fields;
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
