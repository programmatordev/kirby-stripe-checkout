<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use DateTimeImmutable;
use Kirby\Api\Controller\Changes;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\User;
use Kirby\Content\LockedContentException;
use Kirby\Exception\LogicException;
use Kirby\Exception\NotFoundException;
use Kirby\Exception\PermissionException;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Form\Form;
use Kirby\Panel\Panel;
use Kirby\Toolkit\I18n;
use Kirby\Uuid\Uri;
use Kirby\Uuid\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Diagnostics\LocalDiagnostics;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPage;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPagePanel;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Kirby\OrdersPage;
use ProgrammatorDev\StripeCheckout\Kirby\PersistenceErrorCode;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderQueryException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\DisputeCollection;
use ProgrammatorDev\StripeCheckout\Order\Internal\DisputeSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\PaymentSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\RefundCollection;
use ProgrammatorDev\StripeCheckout\Order\Internal\RefundSnapshot;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Order\Payment;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use ProgrammatorDev\StripeCheckout\Test\Support\CheckoutAttemptFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\TestWorkspace;
use RuntimeException;
use Stripe\Charge;
use Stripe\Dispute;
use Stripe\PaymentIntent;
use Stripe\Refund;

final class OrderPageStoreTest extends KirbyTestCase
{
    #[DataProvider('storageFailureRetryability')]
    public function testStorageFailuresClassifyWhetherAnotherExplicitAttemptCanHelp(string $errorCode, bool $retryable): void
    {
        $this->assertSame($retryable, (new OrderStorageException($errorCode))->isRetryable());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function storageFailureRetryability(): iterable
    {
        yield 'busy lock' => [PersistenceErrorCode::BUSY, true];
        yield 'write failure' => [PersistenceErrorCode::WRITE_FAILED, true];
        yield 'verification failure' => [PersistenceErrorCode::VERIFY_FAILED, true];
        yield 'invalid content' => [PersistenceErrorCode::CONTENT_INVALID, false];
    }

    public function testBootCreatesOnlyTheOwnedContainerAndSetupIsIdempotent(): void
    {
        $store = new OrderPageStore($this->kirby);
        $container = $store->container();
        $this->assertInstanceOf(OrdersPage::class, $container);
        $this->assertTrue($container->isDraft());
        $this->assertSame($container->id(), $store->initialize()->id());
        $this->assertCount(0, $store->orders());
    }

    public function testCreatesAndQueriesNativeDraftPages(): void
    {
        $page = $this->createOrder();
        $api = new StripeCheckout($this->kirby);
        $this->assertTrue($page->isDraft());
        $this->assertSame($page->uuid()->id(), $page->slug());
        $this->assertSame('ORD-' . strtoupper($page->slug()), $this->value($page, 'orderNumber'));
        $this->assertSame('creating', $this->value($page, 'checkoutStatus'));
        $this->assertSame('32.00', $this->value($page, 'subtotal'));
        $this->assertCount(1, $api->orders()->filterBy('paymentStatus', 'unpaid'));
        $this->assertSame($page->id(), $api->order($page->uuid()->toString())?->id());
        $this->assertNull($api->order($page->id()));
        $this->assertNull($api->order($page->slug()));
        $this->assertNull($api->order('page://missing'));
    }

    public function testPanelProjectionExposesNestedOrderFactsWithoutChangingContent(): void
    {
        $page = $this->createOrder(createdAt: new DateTimeImmutable('2026-01-01T12:00:00Z'));
        $versions = $page->panel()->versions();
        $requestHandle = strtolower(OrderPagePanel::handle('checkoutSessionRequest'));
        $tokenHandle = strtolower(OrderPagePanel::handle('checkoutAttemptTokenHash'));
        $orderItemsHandle = strtolower(OrderPagePanel::handle('orderItems'));

        foreach (['latest', 'changes'] as $version) {
            $values = $versions[$version] ?? null;
            $this->assertIsArray($values);
            $this->assertSame($page->title()->value(), $values['title'] ?? null);
            $this->assertNotEmpty($values[$tokenHandle] ?? null);
            $request = $values[$requestHandle] ?? null;
            $this->assertIsArray($request);
            $this->assertContains(['path' => 'client_reference_id', 'value' => $page->uuid()->toString()], $request);
            $this->assertContains(['path' => 'mode', 'value' => 'payment'], $request);
            $orderItems = $values[$orderItemsHandle] ?? null;
            $this->assertIsArray($orderItems);
            $this->assertCount(1, $orderItems);
            $orderItem = $orderItems[0] ?? null;
            $this->assertIsArray($orderItem);
            $this->assertSame('Product', $orderItem['name'] ?? null);
            $this->assertSame(2.0, $orderItem['quantity'] ?? null);
            $this->assertSame('€32.00', $orderItem['subtotal'] ?? null);
            $this->assertSame('€32.00', $orderItem['total'] ?? null);
            $this->assertArrayNotHasKey('confirmed', $orderItem);
            $this->assertArrayNotHasKey('stripepriceid', $orderItem);
            $this->assertIsArray($values['initiatinglineitems'] ?? null);
            $deliveries = $values['lifecycledeliveries'] ?? null;
            $this->assertIsArray($deliveries);
            $this->assertCount(1, $deliveries);
            $delivery = $deliveries[0] ?? null;
            $this->assertIsArray($delivery);
            $this->assertSame('order.created', $delivery['type'] ?? null);
            $this->assertNotEmpty($delivery['deliveryid'] ?? null);
            $this->assertSame($page->uuid()->toString(), $delivery['pageuuid'] ?? null);
            $this->assertSame('2026-01-01 12:00:00', $delivery['occurredat'] ?? null);
            $this->assertTrue($delivery['payloadretained'] ?? false);
            $this->assertSame('', $delivery['nextactiontype'] ?? null);
            $this->assertArrayNotHasKey('payload', $delivery);
            $this->assertArrayNotHasKey('ordersnapshot', $delivery);
            $this->assertArrayNotHasKey('nextaction', $delivery);
        }

        $content = $page->content('default')->toArray();
        $this->assertArrayNotHasKey($requestHandle, $content);
        $this->assertArrayNotHasKey($tokenHandle, $content);
    }

    public function testPanelPresentsSelectedOptionsAsOperatorTags(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-01T12:00:00Z');
        $page = $this->createOrder(
            createdAt: $createdAt,
            selectedOptions: [new SelectedOption(
                optionId: 'size',
                optionName: 'Size',
                valueId: 'large',
                valueName: 'Large',
            )],
        );
        $values = $page->panel()->versions()['latest'] ?? null;
        $this->assertIsArray($values);
        $orderItems = $values[strtolower(OrderPagePanel::handle('orderItems'))] ?? null;
        $this->assertIsArray($orderItems);
        $orderItem = $orderItems[0] ?? null;
        $this->assertIsArray($orderItem);
        $this->assertSame(['Size: Large'], $orderItem['optionsummary'] ?? null);
        $this->assertArrayNotHasKey('options', $orderItem);
    }

    public function testPanelFormatsKeyDatesInTheApplicationTimezone(): void
    {
        $page = $this->createOrder(createdAt: new DateTimeImmutable('2026-07-01T12:00:00Z'));
        $previousTimezone = date_default_timezone_get();

        try {
            date_default_timezone_set('Europe/Lisbon');
            $values = $page->panel()->versions()['latest'] ?? null;
        } finally {
            date_default_timezone_set($previousTimezone);
        }

        $this->assertIsArray($values);
        $this->assertSame(
            '2026-07-01 13:00:00',
            $values[strtolower(OrderPagePanel::handle('formattedCreatedAt'))] ?? null,
        );
    }

    public function testPanelDoesNotPresentOpenSessionLinesAsACompletedPurchase(): void
    {
        $page = $this->createOrder(createdAt: new DateTimeImmutable('2026-01-01T12:00:00Z'));
        $page = (new OrderPageStore($this->kirby))->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'open',
            'stripeCheckoutSessionId' => 'cs_open',
            'stripeShippingRateIds' => [],
            'checkoutOpenedAt' => $data['createdAt'],
            'lineItems' => [[
                'stripeLineItemId' => 'li_open',
                'initiatingIndex' => 0,
                'stripePriceId' => 'price_open',
                'stripeProductId' => 'prod_open',
                'quantity' => 2,
                'description' => 'Provider line',
                'currency' => 'EUR',
                'price' => '16.00',
                'subtotal' => '32.00',
                'discount' => '0',
                'tax' => '0',
                'total' => '32.00',
                'discounts' => [],
            ]],
        ]);
        $values = $page->panel()->versions()['latest'] ?? null;
        $this->assertIsArray($values);
        $orderItems = $values[strtolower(OrderPagePanel::handle('orderItems'))] ?? null;
        $this->assertIsArray($orderItems);
        $orderItem = $orderItems[0] ?? null;
        $this->assertIsArray($orderItem);
        $this->assertSame('', $orderItem['discount'] ?? null);
        $this->assertSame('', $orderItem['tax'] ?? null);
        $this->assertArrayNotHasKey('confirmed', $orderItem);
    }

    public function testPanelUsesFinalSessionFactsAfterCheckoutCompletes(): void
    {
        $page = $this->createOrder(createdAt: new DateTimeImmutable('2026-01-01T12:00:00Z'));
        $page = (new OrderPageStore($this->kirby))->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'complete',
            'paymentStatus' => 'paid',
            'stripeCheckoutSessionId' => 'cs_complete',
            'stripeShippingRateIds' => [],
            'checkoutCompletedAt' => $data['createdAt'],
            'paidAt' => $data['createdAt'],
            'discountTotal' => '0',
            'shippingTotal' => '0',
            'taxTotal' => '0',
            'total' => '32.00',
            'customFields' => [],
            'discounts' => [],
            'customer' => [
                'email' => 'buyer@example.test',
                'individualName' => null,
                'businessName' => null,
                'phone' => null,
                'taxIds' => [[
                    'type' => 'eu_vat',
                    'value' => null,
                ]],
            ],
            'billingAddress' => [
                'name' => null,
                'line1' => null,
                'line2' => null,
                'postalCode' => null,
                'city' => null,
                'state' => null,
                'country' => null,
            ],
            'lineItems' => [[
                'stripeLineItemId' => 'li_complete',
                'initiatingIndex' => 0,
                'stripePriceId' => 'price_complete',
                'stripeProductId' => 'prod_complete',
                'quantity' => 2,
                'description' => 'Provider line',
                'currency' => 'EUR',
                'price' => '16.00',
                'subtotal' => '32.00',
                'discount' => '0',
                'tax' => '0',
                'total' => '32.00',
                'discounts' => [],
            ]],
        ]);
        $values = $page->panel()->versions()['latest'] ?? null;
        $this->assertIsArray($values);
        $orderItems = $values[strtolower(OrderPagePanel::handle('orderItems'))] ?? null;
        $this->assertIsArray($orderItems);
        $orderItem = $orderItems[0] ?? null;
        $this->assertIsArray($orderItem);
        $this->assertSame('€0.00', $orderItem['discount'] ?? null);
        $this->assertSame('€0.00', $orderItem['tax'] ?? null);
        $this->assertArrayNotHasKey('confirmed', $orderItem);
        $this->assertFalse($values[strtolower(OrderPagePanel::handle('hasBillingAddress'))] ?? true);
        $this->assertFalse($values[strtolower(OrderPagePanel::handle('hasBillingTaxId'))] ?? true);
        $this->assertFalse($values[strtolower(OrderPagePanel::handle('hasBillingInformation'))] ?? true);
    }

    public function testPanelBreadcrumbReturnsToTheStripeCheckoutOrderList(): void
    {
        $page = $this->createOrder();
        $panel = $page->panel();
        $view = $panel->view();

        $this->assertSame('Stripe Checkout', $view['breadcrumbLabel'] ?? null);
        $this->assertSame('credit-card', $view['icon'] ?? null);
        $this->assertSame(Panel::url('stripe-checkout'), $view['link'] ?? null);
        $this->assertSame([[
            'label' => 'Orders',
            'link' => Panel::url('stripe-checkout') . '?tab=orders',
        ], [
            'label' => $page->title()->toString(),
            'link' => $panel->url(true),
        ]], $view['breadcrumb'] ?? null);
    }

    public function testPanelLinksAnAssociatedSessionToTheMatchingStripeDashboardMode(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            users: [[
                'id' => 'panel-admin',
                'email' => 'panel-admin@example.com',
                'role' => 'admin',
            ]],
            impersonate: 'panel-admin',
        );
        $this->kirby = $this->environment->app();
        $page = $this->createOrder(createdAt: new DateTimeImmutable('2026-01-01T12:00:00Z'));
        $buttons = $page->panel()->buttons();
        $this->assertCount(1, $buttons);
        $syncButton = $buttons[0] ?? null;
        $this->assertIsArray($syncButton);
        $syncProps = $syncButton['props'] ?? null;
        $this->assertIsArray($syncProps);
        $this->assertSame('refresh', $syncProps['icon'] ?? null);
        $this->assertSame('Sync with Stripe', $syncProps['text'] ?? null);
        $this->assertTrue($syncProps['disabled'] ?? false);
        $this->assertSame(
            'This order has no Stripe Checkout Session to sync.',
            $syncProps['title'] ?? null,
        );

        $updated = (new OrderPageStore($this->kirby))->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'open',
            'stripeCheckoutSessionId' => 'cs_test_dashboard/reference',
            'stripeShippingRateIds' => [],
            'checkoutOpenedAt' => $data['createdAt'],
        ]);
        $expected = 'https://dashboard.stripe.com/test/checkout/sessions/cs_test_dashboard%2Freference';
        $buttons = $updated->panel()->buttons();
        $this->assertCount(2, $buttons);
        $syncButton = $buttons[0] ?? null;
        $this->assertIsArray($syncButton);
        $syncProps = $syncButton['props'] ?? null;
        $this->assertIsArray($syncProps);
        $this->assertFalse($syncProps['disabled'] ?? true);
        $this->assertSame(
            'stripe-checkout/orders/' . $updated->slug() . '/sync',
            $syncProps['dialog'] ?? null,
        );
        $button = $buttons[1] ?? null;
        $this->assertIsArray($button);
        $this->assertSame('k-view-button', $button['component'] ?? null);
        $props = $button['props'] ?? null;
        $this->assertIsArray($props);
        $this->assertSame('open', $props['icon'] ?? null);
        $this->assertSame($expected, $props['link'] ?? null);
        $this->assertSame('_blank', $props['target'] ?? null);
        $this->assertSame('noopener noreferrer', $props['rel'] ?? null);
        $this->assertSame('Open in Stripe', $props['text'] ?? null);

        $livePage = $this->createOrder(
            createdAt: new DateTimeImmutable('2026-01-02T12:00:00Z'),
            credentialMode: CredentialMode::Live,
        );
        $livePage = (new OrderPageStore($this->kirby))->update($livePage->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'open',
            'stripeCheckoutSessionId' => 'cs_live_dashboard',
            'stripeShippingRateIds' => [],
            'checkoutOpenedAt' => $data['createdAt'],
        ]);
        $liveButton = $livePage->panel()->buttons()[1] ?? null;
        $this->assertIsArray($liveButton);
        $liveProps = $liveButton['props'] ?? null;
        $this->assertIsArray($liveProps);
        $this->assertSame(
            'https://dashboard.stripe.com/checkout/sessions/cs_live_dashboard',
            $liveProps['link'] ?? null,
        );
    }

    public function testUserQueriesDoNotDiscloseOtherUsersOrGuestOrders(): void
    {
        $first = new User([
            'id' => 'buyer-one',
            'email' => 'one@example.test',
        ]);
        $second = new User([
            'id' => 'buyer-two',
            'email' => 'two@example.test',
        ]);
        $owned = $this->createOrder($first->uuid()->toString());
        $this->createOrder($second->uuid()->toString());
        $this->createOrder();
        $api = new StripeCheckout($this->kirby);
        $this->assertCount(1, $api->ordersFor($first));
        $this->assertNull($api->orderFor($second, $owned->uuid()->toString()));
        $this->assertSame($owned->id(), $api->orderFor($first, $owned->uuid()->toString())?->id());
    }

    public function testCanonicalWritesPreserveCustomFieldsAndRejectRegressions(): void
    {
        $page = $this->createOrder()->update(['internalNote' => 'Keep me']);
        $store = new OrderPageStore($this->kirby);
        $opened = $store->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'open',
            'stripeCheckoutSessionId' => 'cs_test',
            'stripeShippingRateIds' => [],
            'checkoutOpenedAt' => $data['createdAt'],
        ]);
        $this->assertSame('Keep me', $this->value($opened, 'internalNote'));
        $this->assertSame('open', $this->value($opened, 'checkoutStatus'));
        $this->expectException(OrderDataException::class);
        $store->update($opened->uuid()->toString(), static function (array $data): array {
            $data['checkoutStatus'] = 'creating';
            unset($data['stripeCheckoutSessionId'], $data['stripeShippingRateIds'], $data['checkoutOpenedAt']);

            return $data;
        });
    }

    public function testPersistsAndRehydratesCollectionAndDiscountSnapshots(): void
    {
        $page = $this->createOrder();
        $panel = $page->panel();
        $this->assertInstanceOf(OrderPagePanel::class, $panel);
        $reports = $panel->statusReports();
        $this->assertSame('Checkout status', $reports[0]['value']);
        $this->assertSame('Creating', $reports[0]['label']);
        $this->assertSame('info', $reports[0]['theme']);
        $this->assertSame('Unpaid', $reports[1]['label']);
        $this->assertSame('info', $reports[1]['theme']);
        $this->assertSame('Not applicable', $reports[2]['label']);
        $this->assertSame('passive', $reports[2]['theme']);
        $this->assertSame('Not applicable', $reports[3]['label']);
        $this->assertSame('passive', $reports[3]['theme']);

        $store = new OrderPageStore($this->kirby);
        $updated = $store->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'complete',
            'paymentStatus' => 'paid',
            'stripeCheckoutSessionId' => 'cs_test',
            'stripeShippingRateIds' => [],
            'stripeCustomerId' => 'cus_test',
            'checkoutCompletedAt' => $data['createdAt'],
            'paidAt' => $data['createdAt'],
            'discountTotal' => '5.00',
            'shippingTotal' => '0',
            'taxTotal' => '0',
            'tax' => [
                'automaticTaxEnabled' => true,
                'calculationStatus' => 'complete',
                'provider' => 'stripe',
                'currency' => 'EUR',
                'amount' => '0.00',
                'providerAmount' => 0,
                'breakdown' => [],
            ],
            'total' => '27.00',
            'customer' => [
                'email' => 'buyer@example.test',
                'individualName' => 'Ana Silva',
                'businessName' => null,
                'phone' => null,
                'taxIds' => [
                    [
                        'type' => 'eu_vat',
                        'value' => 'PT123456789',
                    ],
                ],
            ],
            'billingAddress' => [
                'name' => 'Ana Silva',
                'line1' => 'Rua Um, 10',
                'line2' => null,
                'postalCode' => '1000-001',
                'city' => 'Lisboa',
                'state' => null,
                'country' => 'PT',
            ],
            'customFields' => [
                [
                    'key' => 'note',
                    'type' => 'text',
                    'label' => 'Order note',
                    'required' => false,
                    'configured' => true,
                    'answered' => false,
                    'value' => null,
                ],
                [
                    'key' => 'deliveryslot',
                    'type' => 'dropdown',
                    'label' => 'Delivery slot',
                    'required' => true,
                    'configured' => true,
                    'answered' => true,
                    'value' => 'morning',
                ],
            ],
            'consent' => [
                'termsOfService' => 'accepted',
                'promotions' => 'opt_in',
            ],
            'discounts' => [[
                'discountId' => 'di_test',
                'couponId' => 'coupon-test',
                'promotionCodeId' => null,
                'couponName' => 'Five euros',
                'promotionCode' => null,
                'amount' => '5.00',
                'currency' => 'EUR',
                'providerAmount' => 500,
                'percentOff' => '15',
                'appliesToProducts' => [],
                'firstTimeTransaction' => null,
                'minimumAmount' => null,
                'minimumAmountCurrency' => null,
                'providerMinimumAmount' => null,
            ]],
        ]);
        $data = $store->data($updated);
        $customer = OrderData::map($data['customer']);
        $billingAddress = OrderData::map($data['billingAddress']);
        $customFields = OrderData::list($data['customFields']);
        $customField = OrderData::map($customFields[0]);
        $consent = OrderData::map($data['consent']);
        $discounts = OrderData::list($data['discounts']);
        $discount = OrderData::map($discounts[0]);

        $this->assertSame('buyer@example.test', $customer['email'] ?? null);
        $this->assertSame('PT', $billingAddress['country'] ?? null);
        $this->assertFalse($customField['answered'] ?? true);
        $this->assertSame('accepted', $consent['termsOfService'] ?? null);
        $this->assertSame('5.00', $discount['amount'] ?? null);
        $this->assertSame('cus_test', $data['stripeCustomerId']);
        $tax = OrderData::map($data['tax']);
        $this->assertTrue($tax['automaticTaxEnabled']);
        $this->assertSame('complete', $tax['calculationStatus']);
        $this->assertSame('0.00', $tax['amount']);

        $values = $updated->panel()->versions()['latest'] ?? null;
        $this->assertIsArray($values);
        $customerSummary = OrderData::map(
            $values[strtolower(OrderPagePanel::handle('customerSummary'))] ?? null,
        );
        $this->assertSame('buyer@example.test', $customerSummary['email'] ?? null);
        $this->assertSame('Ana Silva', $customerSummary['individualname'] ?? null);
        $this->assertSame(
            'Accepted',
            $values[strtolower(OrderPagePanel::handle('termsOfServiceConsent'))] ?? null,
        );
        $this->assertSame(
            'Opted in',
            $values[strtolower(OrderPagePanel::handle('promotionalEmailConsent'))] ?? null,
        );

        $billingAddressSummary = OrderData::map(
            $values[strtolower(OrderPagePanel::handle('billingAddressSummary'))] ?? null,
        );
        $this->assertSame('Rua Um, 10', $billingAddressSummary['line1'] ?? null);
        $this->assertSame('1000-001', $billingAddressSummary['postalcode'] ?? null);
        $this->assertSame(
            'eu_vat',
            $values[strtolower(OrderPagePanel::handle('billingTaxIdType'))] ?? null,
        );
        $this->assertSame(
            'PT123456789',
            $values[strtolower(OrderPagePanel::handle('billingTaxId'))] ?? null,
        );
        $this->assertFalse(
            $values[strtolower(OrderPagePanel::handle('requiresShipping'))] ?? true,
        );
        $this->assertSame(
            '€32.00',
            $values[strtolower(OrderPagePanel::handle('formattedSubtotal'))] ?? null,
        );
        $this->assertSame(
            '€27.00',
            $values[strtolower(OrderPagePanel::handle('formattedTotal'))] ?? null,
        );
        $orderDiscounts = OrderData::list(
            $values[strtolower(OrderPagePanel::handle('orderDiscounts'))] ?? null,
        );
        $orderDiscount = OrderData::map($orderDiscounts[0] ?? null);
        $this->assertSame('€5.00', $orderDiscount['amount'] ?? null);
        $this->assertSame('15%', $orderDiscount['percentoff'] ?? null);
        $this->assertArrayNotHasKey('currency', $orderDiscount);
        $checkoutCustomFields = OrderData::list(
            $values[strtolower(OrderPagePanel::handle('checkoutCustomFields'))] ?? null,
        );
        $this->assertSame([
            [
                'label' => 'Order note',
                'value' => '–',
            ],
            [
                'label' => 'Delivery slot',
                'value' => 'morning',
            ],
        ], $checkoutCustomFields);
        $this->assertTrue(
            $values[strtolower(OrderPagePanel::handle('hasCheckoutCustomFields'))] ?? false,
        );
        $this->assertFalse(
            $values[strtolower(OrderPagePanel::handle('hasRefundedAmount'))] ?? true,
        );
        $this->assertIsString($values[strtolower(OrderPagePanel::handle('formattedCreatedAt'))] ?? null);

        $panel = $updated->panel();
        $this->assertInstanceOf(OrderPagePanel::class, $panel);
        $reports = $panel->statusReports();
        $this->assertSame('Complete', $reports[0]['label']);
        $this->assertSame('positive', $reports[0]['theme']);
        $this->assertSame('Paid', $reports[1]['label']);
        $this->assertSame('positive', $reports[1]['theme']);

        $this->expectException(OrderDataException::class);
        $store->update($updated->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'taxTotal' => '1.00',
        ]);
    }

    public function testPanelPresentsFailedPaymentDetailsAndFallback(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-01T12:00:00Z');
        $page = $this->createOrder(createdAt: $createdAt);
        $amount = Money::of('32.00', 'EUR');
        $payment = Payment::fromSnapshot(new PaymentSnapshot(
            amount: $amount,
            amountReceived: Money::zero('EUR'),
            stripePaymentIntentId: 'pi_failed',
            stripeChargeId: 'ch_failed',
            stripePaymentMethodId: 'pm_failed',
            paymentIntentStatus: PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD,
            chargeStatus: Charge::STATUS_FAILED,
            methodType: 'card',
            createdAt: $createdAt->getTimestamp(),
            chargeCreatedAt: $createdAt->getTimestamp(),
            chargePaid: false,
            chargeCaptured: false,
            amountCaptured: Money::zero('EUR'),
            failureCode: 'card_declined',
            failureMessage: 'The card was declined.',
        ), PaymentStatus::Failed, 'EUR');
        $store = new OrderPageStore($this->kirby);
        $page = $store->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'complete',
            'paymentStatus' => 'failed',
            'stripeCheckoutSessionId' => 'cs_failed',
            'stripeShippingRateIds' => [],
            'stripePaymentIntentId' => 'pi_failed',
            'stripeChargeId' => 'ch_failed',
            'checkoutCompletedAt' => $data['createdAt'],
            'paymentFailedAt' => $data['createdAt'],
            'discountTotal' => '0',
            'shippingTotal' => '0',
            'taxTotal' => '0',
            'total' => '32.00',
            'customFields' => [],
            'discounts' => [],
            'payment' => $payment->toArray(),
        ]);
        $panel = $page->panel();
        $this->assertInstanceOf(OrderPagePanel::class, $panel);
        $values = $panel->versions()['latest'] ?? null;
        $this->assertIsArray($values);
        $this->assertSame(
            'The card was declined.',
            $values[strtolower(OrderPagePanel::handle('paymentFailureMessage'))] ?? null,
        );
        $this->assertSame(
            '2026-01-01 12:00:00',
            $values[strtolower(OrderPagePanel::handle('paymentFailedAt'))] ?? null,
        );
        $reports = $panel->financialStatusReports();
        $this->assertSame('Failed', $reports[0]['label']);
        $this->assertSame('negative', $reports[0]['theme']);
        $this->assertSame('Not applicable', $reports[1]['label']);
        $this->assertSame('Not applicable', $reports[2]['label']);

        $paymentData = $payment->toArray();
        $paymentData['failureMessage'] = null;
        $paymentWithoutMessage = Payment::fromArray($paymentData);
        $page = $store->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'payment' => $paymentWithoutMessage->toArray(),
        ]);
        $values = $page->panel()->versions()['latest'] ?? null;
        $this->assertIsArray($values);
        $this->assertSame(
            'Not provided',
            $values[strtolower(OrderPagePanel::handle('paymentFailureMessage'))] ?? null,
        );
    }

    public function testPanelExplainsPaymentIssuesThatNeedAttention(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-01T12:00:00Z');
        $page = $this->createOrder(createdAt: $createdAt);
        $paymentIntentId = 'pi_action';
        $chargeId = 'ch_action';
        $amount = Money::of('32.00', 'EUR');
        $payment = Payment::fromSnapshot(new PaymentSnapshot(
            amount: $amount,
            amountReceived: $amount,
            stripePaymentIntentId: $paymentIntentId,
            stripeChargeId: $chargeId,
            stripePaymentMethodId: 'pm_action',
            paymentIntentStatus: PaymentIntent::STATUS_SUCCEEDED,
            chargeStatus: Charge::STATUS_SUCCEEDED,
            methodType: 'card',
            createdAt: $createdAt->getTimestamp(),
            chargeCreatedAt: $createdAt->getTimestamp(),
            chargePaid: true,
            chargeCaptured: true,
            amountCaptured: $amount,
        ), PaymentStatus::Paid, 'EUR');
        $refund = RefundSnapshot::fromStripe([
            'id' => 're_action',
            'object' => Refund::OBJECT_NAME,
            'amount' => 1600,
            'currency' => 'eur',
            'payment_intent' => $paymentIntentId,
            'charge' => $chargeId,
            'created' => $createdAt->getTimestamp(),
            'status' => Refund::STATUS_REQUIRES_ACTION,
        ], $paymentIntentId);
        $failedRefund = RefundSnapshot::fromStripe([
            'id' => 're_failed',
            'object' => Refund::OBJECT_NAME,
            'amount' => 800,
            'currency' => 'eur',
            'payment_intent' => $paymentIntentId,
            'charge' => $chargeId,
            'created' => $createdAt->getTimestamp(),
            'status' => Refund::STATUS_FAILED,
            'failure_reason' => 'declined',
        ], $paymentIntentId);
        $refunds = RefundCollection::fromSnapshots([$refund, $failedRefund], $paymentIntentId, $amount)
            ->observed(null, $createdAt);
        $dispute = DisputeSnapshot::fromStripe([
            'id' => 'dp_response',
            'object' => Dispute::OBJECT_NAME,
            'amount' => 1600,
            'currency' => 'eur',
            'payment_intent' => $paymentIntentId,
            'charge' => $chargeId,
            'created' => $createdAt->getTimestamp(),
            'status' => Dispute::STATUS_NEEDS_RESPONSE,
            'reason' => 'fraudulent',
            'evidence_details' => [
                'due_by' => $createdAt->modify('+7 days')->getTimestamp(),
                'has_evidence' => false,
                'past_due' => false,
                'submission_count' => 0,
            ],
            'balance_transactions' => [],
        ], $paymentIntentId);
        $disputes = DisputeCollection::fromSnapshots([$dispute], $paymentIntentId, $amount)
            ->observed(null, $createdAt);
        $page = (new OrderPageStore($this->kirby))->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'complete',
            'paymentStatus' => 'paid',
            'stripeCheckoutSessionId' => 'cs_action',
            'stripeShippingRateIds' => [],
            'stripePaymentIntentId' => $paymentIntentId,
            'stripeChargeId' => $chargeId,
            'checkoutCompletedAt' => $data['createdAt'],
            'paidAt' => $data['createdAt'],
            'discountTotal' => '0',
            'shippingTotal' => '0',
            'taxTotal' => '0',
            'total' => '32.00',
            'customFields' => [],
            'discounts' => [],
            'payment' => $payment->toArray(),
            'refundStatus' => $refunds->refundStatus()->value,
            'refundHasActive' => $refunds->refundHasActive(),
            'refundRequiresAction' => $refunds->refundRequiresAction(),
            'refundHasFailed' => $refunds->refundHasFailed(),
            'refundedTotal' => '0',
            'refundUpdatedAt' => $data['createdAt'],
            'refunds' => $refunds->toArray(),
            'disputeStatus' => $disputes->disputeStatus()->value,
            'disputeRequiresResponse' => $disputes->disputeRequiresResponse(),
            'disputeHasLost' => $disputes->disputeHasLost(),
            'disputeUpdatedAt' => $data['createdAt'],
            'disputes' => $disputes->toArray(),
        ]);
        $panel = $page->panel();
        $this->assertInstanceOf(OrderPagePanel::class, $panel);
        $reports = $panel->statusReports();
        $financialReports = $panel->financialStatusReports();

        $this->assertCount(3, $financialReports);
        $this->assertSame('Paid', $financialReports[0]['label']);
        $this->assertSame('Refund requires action', $financialReports[1]['label']);
        $this->assertSame('Needs response', $financialReports[2]['label']);

        $values = $page->panel()->versions()['latest'] ?? null;
        $this->assertIsArray($values);
        $this->assertSame(
            '€32.00',
            $values[strtolower(OrderPagePanel::handle('formattedPaymentAmount'))] ?? null,
        );
        $this->assertSame(
            '€32.00',
            $values[strtolower(OrderPagePanel::handle('formattedPaymentAmountReceived'))] ?? null,
        );
        $this->assertSame(
            '€32.00',
            $values[strtolower(OrderPagePanel::handle('formattedPaymentAmountCaptured'))] ?? null,
        );
        $this->assertSame('Card', $values[strtolower(OrderPagePanel::handle('paymentMethod'))] ?? null);
        $this->assertSame(
            '2026-01-01 12:00:00',
            $values[strtolower(OrderPagePanel::handle('formattedPaymentCreatedAt'))] ?? null,
        );
        $this->assertSame(
            '2026-01-01 12:00:00',
            $values[strtolower(OrderPagePanel::handle('paymentPaidAt'))] ?? null,
        );

        $this->assertSame('Refund requires action', $reports[2]['label']);
        $this->assertSame('negative', $reports[2]['theme']);
        $this->assertSame('Needs response', $reports[3]['label']);
        $this->assertSame('negative', $reports[3]['theme']);
        $this->assertSame(
            'Refunds requiring action in Stripe: €16.00. Review the Payment tab',
            $panel->refundRequiresActionNotice(),
        );
        $this->assertSame(
            'Failed refunds: €8.00 (declined). Review the Payment tab',
            $panel->refundFailureNotice(),
        );
        $this->assertSame(
            'Disputes requiring a response: €16.00 (respond by 2026-01-08 12:00:00). Review the Payment tab',
            $panel->disputeResponseNotice(),
        );
        $this->assertSame(
            $panel->disputeResponseNotice(),
            $page->toSafeString('{{ page.panel.disputeResponseNotice }}'),
        );
    }

    public function testStaleOrderPageDoesNotOverwriteCanonicalUpdates(): void
    {
        $old = $this->createOrder();
        (new OrderPageStore($this->kirby))->update($old->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'open',
            'stripeCheckoutSessionId' => 'cs_test',
            'stripeShippingRateIds' => [],
            'checkoutOpenedAt' => $data['createdAt'],
        ]);
        $updated = $old->update(['note' => 'A later project edit']);
        $this->assertSame('open', $this->value($updated, 'checkoutStatus'));
        $this->assertSame('A later project edit', $this->value($updated, 'note'));
    }

    #[DataProvider('protectedActions')]
    public function testAdminCannotChangeStructureOrCanonicalFields(string $action, bool $container): void
    {
        $page = $container ? (new OrderPageStore($this->kirby))->initialize() : $this->createOrder();
        $this->expectException($action === 'sort' ? LogicException::class : PermissionException::class);

        match ($action) {
            'title' => $page->changeTitle('Other'),
            'slug' => $page->changeSlug('other'),
            'status' => $page->changeStatus('unlisted'),
            'delete' => $page->delete(),
            'copy' => $page->copy(),
            'duplicate' => $page->duplicate('duplicate'),
            'template' => $page->changeTemplate('default'),
            'move' => $page->move(new Page(['slug' => 'destination'])),
            'sort' => $page->changeNum(1),
            'child' => $page->createChild(['slug' => 'child']),
            'canonical' => $page->update(['paymentStatus' => 'paid']),
            'save' => $page->save(['paymentStatus' => 'paid']),
            default => $this->fail('Unknown action'),
        };
    }

    /** @return iterable<string, array{string, bool}> */
    public static function protectedActions(): iterable
    {
        $actions = ['title', 'slug', 'status', 'delete', 'copy', 'duplicate', 'template', 'move', 'sort', 'child', 'canonical', 'save'];

        foreach ($actions as $action) {
            yield 'order ' . $action => [$action, false];
            yield 'container ' . $action => [$action, true];
        }
    }

    public function testOrderCannotRender(): void
    {
        $this->expectException(NotFoundException::class);
        $this->createOrder()->render();
    }

    public function testCustomBlueprintAndTranslationsDoNotDuplicateCanonicalFields(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            languages: [
                [
                    'code' => 'en',
                    'default' => true,
                    'locale' => 'en_US',
                    'name' => 'English',
                ],
                [
                    'code' => 'pt',
                    'locale' => 'pt_PT',
                    'name' => 'Português',
                ],
            ],
            beforeApp: static function (TestWorkspace $workspace): void {
                $workspace->writePageBlueprint('stripe-checkout-order', [
                    'extends' => 'programmatordev/stripe-checkout/pages/order',
                    'tabs' => [
                        'project' => [
                            'label' => 'Project',
                            'fields' => ['note' => [
                                'type' => 'textarea',
                                'default' => 'New order',
                            ]],
                        ],
                        'technical' => [
                            'fields' => ['stripeCheckoutSessionId' => [
                                'type' => 'text',
                                'default' => 'cs_blueprint_default',
                            ]],
                        ],
                    ],
                ]);
            },
        );
        $this->kirby = $this->environment->app();
        $this->kirby->setCurrentLanguage('pt');
        $page = $this->createOrder();
        $this->assertSame('pt', $this->value($page, 'languageCode'));
        $this->assertSame('New order', $this->value($page, 'note'));
        $this->assertNull($this->value($page, 'stripeCheckoutSessionId'));
        $page = $page->update(['note' => 'Português'], 'pt');
        $page = $page->update(['note' => 'English'], 'en');
        $this->assertSame('Português', $page->version('latest')->read('pt')['note'] ?? null);
        $this->assertSame('English', $page->version('latest')->read('en')['note'] ?? null);
        $this->assertArrayNotHasKey('uuid', $page->version('latest')->read('pt'));
        $this->assertArrayNotHasKey('paymentstatus', $page->version('latest')->read('pt'));
        $this->assertArrayHasKey('note', $page->blueprint()->fields());

        $page = (new OrderPageStore($this->kirby))->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'creation_uncertain',
            'creationUncertainAt' => $data['createdAt'],
        ]);
        $this->assertSame('Português', $page->version('latest')->read('pt')['note'] ?? null);
        $this->assertSame('English', $this->value($page, 'note'));
        $this->assertSame('creation_uncertain', $this->value($page, 'checkoutStatus'));
        // Native writes already strip untranslatable fields.
        // Simulate corrupt imported content to verify queries cannot expose a translated state.
        F::write($page->root() . '/stripe-checkout-order.pt.txt', \Kirby\Data\Txt::encode([
            'note' => 'Português',
            'paymentstatus' => 'paid',
        ]));
        $this->assertNull((new StripeCheckout($this->kirby))->order($page->uuid()->toString()));
    }

    public function testFormatterAndCustomFieldsFilterRunBeforeCreationWithoutImpersonation(): void
    {
        $seen = [];
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            options: ['programmatordev.stripe-checkout.orders.numberFormatter' => static fn(string $uuid): string => 'WEB-' . (new Uri($uuid))->host()],
            hooks: ['programmatordev.stripe-checkout.order.fields' => function (array $fields, OrderCreationContext $context) use (&$seen): array {
                $seen[] = [
                    'user' => App::instance()->user()?->id(),
                    'orders' => (new OrderPageStore(App::instance()))->orders()->count(),
                ];

                return [...$fields, 'salesChannel' => 'website'];
            }],
            impersonate: null,
        );
        $this->kirby = $this->environment->app();
        $page = $this->createOrder();
        $this->assertSame([[
            'user' => null,
            'orders' => 0,
        ]], $seen);
        $this->assertNull($this->kirby->user());
        $this->assertSame('WEB-' . $page->slug(), $this->value($page, 'orderNumber'));
        $this->assertSame('website', $this->value($page, 'salesChannel'));
    }

    public function testFilterCannotOverrideCanonicalFieldsOrLeaveAnOrderBehind(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(hooks: [
            'programmatordev.stripe-checkout.order.fields' => fn(): array => ['total' => '1'],
        ]);
        $this->kirby = $this->environment->app();

        try {
            $this->createOrder();
            $this->fail('Expected invalid custom fields.');
        } catch (OrderDataException $error) {
            $this->assertSame('order.custom_fields_invalid', $error->errorCode());
            $this->assertCount(0, (new OrderPageStore($this->kirby))->orders());
        }
    }

    public function testUnownedContainerIsNotAdoptedOrOverwritten(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(beforeApp: static function (TestWorkspace $workspace): void {
            $workspace->writeDraftPage('stripe-checkout-orders', 'default', ['title' => 'Mine']);
        });
        $this->kirby = $this->environment->app();
        $file = $this->environment->workspace()->roots()['content'] . '/_drafts/stripe-checkout-orders/default.txt';
        $before = F::read($file);

        try {
            (new OrderPageStore($this->kirby))->initialize();
            $this->fail('Expected a collision.');
        } catch (OrderStorageException) {
            $this->assertSame($before, F::read($file));
        }

        $this->expectException(OrderQueryException::class);
        (new StripeCheckout($this->kirby))->orders();
    }

    public function testReadsDoNotRecreateMissingStorage(): void
    {
        $root = $this->environment->workspace()->roots()['content'] . '/_drafts/stripe-checkout-orders';
        Dir::remove($root);
        $api = new StripeCheckout($this->kirby);
        $this->assertCount(0, $api->orders());
        $this->assertNull($api->order('page://missing'));
        $this->assertDirectoryDoesNotExist($root);
        $this->createOrder();
        $this->assertCount(1, $api->orders());
    }

    public function testCorruptOrderIsExcludedWithoutLosingValidOrders(): void
    {
        $broken = $this->createOrder();
        $valid = $this->createOrder();
        $uuid = $broken->uuid()->toString();
        $broken->version('latest')->update(['subtotal' => 'incorrect'], 'default');
        $api = new StripeCheckout($this->kirby);
        $this->assertCount(1, $api->orders());
        $this->assertNull($api->order($uuid));
        $this->assertSame($valid->id(), $api->orders()->first()?->id());
    }

    public function testFailedNativeCreationIsNotReturnedAsAnOrder(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(hooks: [
            'page.create:before' => function (Page $page): void {
                if ($page instanceof OrderPage) {
                    throw new RuntimeException('Injected write failure');
                }
            },
        ]);
        $this->kirby = $this->environment->app();

        try {
            $this->createOrder();
            $this->fail('Expected failed creation.');
        } catch (OrderStorageException $error) {
            $this->assertSame('persistence.write_failed', $error->errorCode());
            $this->assertCount(0, (new OrderPageStore($this->kirby))->orders());
        }
    }

    public function testPermissionsDoNotRestrictTrustedQueriesButDoRestrictEdits(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            roles: [[
                'name' => 'reader',
                'permissions' => ['programmatordev.stripe-checkout' => [
                    'orders.read' => true,
                    'orders.update' => false,
                ]],
            ]],
            users: [[
                'id' => 'reader',
                'email' => 'reader@example.test',
                'role' => 'reader',
            ]],
            impersonate: 'reader',
        );
        $this->kirby = $this->environment->app();
        $page = $this->createOrder();
        $this->assertTrue($page->permissions()->can('read'));
        $this->assertFalse($page->permissions()->can('update'));
        $this->assertSame('reader', $this->kirby->user()?->id());
        $this->kirby->impersonate(null);
        $this->assertFalse($page->permissions()->can('read'));
        $this->assertCount(1, (new StripeCheckout($this->kirby))->orders());
        $this->expectException(PermissionException::class);
        $page->update(['note' => 'Denied']);
    }

    public function testConcurrentCanonicalWritesReloadAfterTheFirstCommit(): void
    {
        $page = $this->createOrder();
        $uuid = $page->uuid()->toString();
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/Order/concurrent-update.php'], [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w'],
        ], $pipes);
        $this->assertIsResource($process);

        try {
            fwrite($pipes[0], json_encode([
                'roots' => $this->environment->workspace()->roots(),
                'uuid' => $uuid,
            ], JSON_THROW_ON_ERROR) . "\n");
            stream_set_timeout($pipes[1], 5);
            $this->assertSame("ready\n", fgets($pipes[1]));
            (new OrderPageStore($this->kirby))->update($uuid, function (array $data) use ($pipes): array {
                fwrite($pipes[0], "go\n");
                $read = [$pipes[1]];
                $write = $except = [];
                $ready = stream_select($read, $write, $except, 0, 200000);
                $earlyResult = $ready === 1 ? fgets($pipes[1]) : '';
                $this->assertSame(0, $ready, 'The second writer must wait; early result: ' . $earlyResult);

                return [
                    ...$data,
                    'checkoutStatus' => 'open',
                    'stripeCheckoutSessionId' => 'cs_test',
                    'stripeShippingRateIds' => [],
                    'checkoutOpenedAt' => $data['createdAt'],
                ];
            });
            $this->assertSame("rejected\n", fgets($pipes[1]));
            $this->assertSame('open', $this->value((new OrderPageStore($this->kirby))->order($uuid), 'checkoutStatus'));
        } finally {
            proc_terminate($process);

            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            proc_close($process);
        }
    }

    public function testNativePanelChangesPreserveCompleteSnapshots(): void
    {
        $page = $this->createOrder();
        $store = new OrderPageStore($this->kirby);
        $before = $store->data($page);
        $form = Form::for($page);
        /** @var array<string, mixed> $input */
        $input = [...$form->toFormValues(), 'note' => 'Panel note'];
        $page->update($input);
        $updated = $store->requirePage($page->id());
        $this->assertSame($before, $store->data($updated));
        $this->assertSame('Panel note', $this->value($updated, 'note'));
        $changes = $updated->version('changes');
        $changes->save([...($updated->version('latest')->read('default') ?? []), 'note' => 'Published note'], 'default');
        $changes->publish('default');
        $published = $store->requirePage($page->id());
        $this->assertSame($before, $store->data($published));
        $this->assertSame('Published note', $this->value($published, 'note'));
    }

    #[DataProvider('editingContexts')]
    public function testPanelCustomEditsSurviveCanonicalUpdates(?string $languageCode, bool $reload): void
    {
        if ($languageCode !== null) {
            $this->environment->close();
            $this->environment = KirbyTestEnvironment::start(languages: [
                [
                    'code' => 'en',
                    'default' => true,
                    'locale' => 'en_US',
                    'name' => 'English',
                ],
                [
                    'code' => 'pt',
                    'locale' => 'pt_PT',
                    'name' => 'Português',
                ],
            ]);
            $this->kirby = $this->environment->app();
            $this->kirby->setCurrentLanguage($languageCode);
        }

        $page = $this->createOrder();
        $store = new OrderPageStore($this->kirby);
        $input = [...Form::for($page)->toFormValues(), 'note' => 'Pending note'];
        Changes::save($page, $input);
        $updated = $store->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'open',
            'stripeCheckoutSessionId' => 'cs_test',
            'stripeShippingRateIds' => [],
            'checkoutOpenedAt' => $data['createdAt'],
        ]);
        $expected = $store->data($updated);

        // Cover both a later Panel request and native publication through a retained Page whose latest-version cache predates the canonical write.
        $editingPage = $reload ? $store->requirePage($page->id()) : $page;
        Changes::publish($editingPage, $input);
        $saved = $store->requirePage($page->id());
        $this->assertSame($expected, $store->data($saved));
        $this->assertSame('Pending note', $saved->content($languageCode ?? 'default')->data()['note'] ?? null);
        $this->assertFalse($saved->version('changes')->exists($languageCode ?? 'default'));

        if ($languageCode === 'pt') {
            $translation = $saved->version('latest')->read('pt');
            $this->assertIsArray($translation);
            $this->assertArrayNotHasKey('checkoutstatus', $translation);
            $this->assertArrayNotHasKey('uuid', $translation);
        }
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function editingContexts(): iterable
    {
        yield 'fresh, single language' => [null, true];
        yield 'fresh, default language' => ['en', true];
        yield 'fresh, translated custom fields' => ['pt', true];
        yield 'retained, single language' => [null, false];
        yield 'retained, default language' => ['en', false];
        yield 'retained, translated custom fields' => ['pt', false];
    }

    public function testNativeVersionPublishIgnoresProtectedChangesButDirectUpdatesRejectThem(): void
    {
        $page = $this->createOrder();
        $store = new OrderPageStore($this->kirby);
        $expected = $store->data($page);
        $page->version('changes')->save([
            ...($page->version('latest')->read('default') ?? []),
            'paymentStatus' => 'paid',
            'note' => 'Custom note',
        ]);
        $page->version('changes')->publish();
        $saved = $store->requirePage($page->id());
        $this->assertSame($expected, $store->data($saved));
        $this->assertSame('Custom note', $this->value($saved, 'note'));

        $this->expectException(PermissionException::class);
        $saved->update(['paymentStatus' => 'paid']);
    }

    public function testCanonicalUpdatesInvalidatePageCacheOnlyWhenCommitted(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'cache' => ['pages' => ['active' => true]],
        ]);
        $this->kirby = $this->environment->app();
        $page = $this->createOrder(createdAt: new DateTimeImmutable('2025-01-01T00:00:00Z'));
        $uuid = $page->uuid()->toString();
        $store = new OrderPageStore($this->kirby);
        $cache = $this->kirby->cache('pages');
        $cache->set('order-summary', 'creating');
        $this->assertSame('creating', $cache->get('order-summary'));
        $store->update($uuid, static fn(array $data): array => $data);
        $this->assertSame('creating', $cache->get('order-summary'));
        $before = $store->data($store->requirePage($page->id()));
        $unchanged = $store->update($uuid, static fn(array $data): array => [
            ...$data,
            'stripePaymentIntentId' => null,
            'refundedTotal' => '0.00',
        ]);
        $this->assertSame($before, $store->data($unchanged));
        $this->assertSame('creating', $cache->get('order-summary'));

        try {
            $store->update($uuid, static fn(array $data): array => [...$data, 'uuid' => 'changed']);
            $this->fail('Expected immutable identity rejection.');
        } catch (OrderDataException) {
            $this->assertSame('creating', $cache->get('order-summary'));
        }

        $store->update($uuid, static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'creation_uncertain',
            'creationUncertainAt' => $data['createdAt'],
        ]);
        $this->assertNull($cache->get('order-summary'));
    }

    public function testOrderChangesRetainNativeEditorLocks(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(users: [
            [
                'id' => 'first-editor',
                'email' => 'first@example.test',
                'role' => 'admin',
            ],
            [
                'id' => 'second-editor',
                'email' => 'second@example.test',
                'role' => 'admin',
            ],
        ], impersonate: 'first-editor');
        $this->kirby = $this->environment->app();
        $page = $this->createOrder();
        Changes::save($page, ['note' => 'First editor']);
        $this->assertSame('first-editor', $page->version('changes')->read()['lock'] ?? null);
        $this->kirby->impersonate('second-editor');
        $this->assertTrue($page->version('changes')->isLocked());
        $this->expectException(LockedContentException::class);
        Changes::publish($page, ['note' => 'Second editor']);
    }

    #[DataProvider('translatedPermissionActions')]
    public function testOrderPermissionErrorsUseThePanelTranslation(string $action, string $suffix): void
    {
        $this->kirby->setCurrentTranslation('pt_PT');
        $page = $this->createOrder();
        $this->expectException(PermissionException::class);
        $this->expectExceptionMessage(I18n::template('programmatordev.stripe-checkout.orders.errors.' . $suffix));

        match ($action) {
            'update' => $page->update(['paymentStatus' => 'paid']),
            'createChild' => $page->createChild(['slug' => 'child']),
            'copy' => $page->copy(),
            'save' => $page->save(['note' => 'Bypass']),
            default => $this->fail('Unknown action'),
        };
    }

    /** @return iterable<string, array{string, string}> */
    public static function translatedPermissionActions(): iterable
    {
        yield 'protected facts' => ['update', 'protectedFields'];
        yield 'manual creation' => ['createChild', 'manualCreation'];
        yield 'structure' => ['copy', 'protectedStructure'];
        yield 'low-level save' => ['save', 'directSave'];
    }

    public function testNativeUuidConfigurationIsUsedForStoredOrders(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: ['content.uuid' => 'uuid-v4']);
        $this->kirby = $this->environment->app();
        $page = $this->createOrder();
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $page->uuid()->id());
        $this->assertSame($page->uuid()->id(), $page->slug());
    }

    public function testDisabledUuidsReportUnavailableUserQueries(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: ['content.uuid' => false]);
        $this->kirby = $this->environment->app();
        $this->expectException(OrderQueryException::class);
        $this->expectExceptionMessage('persistence.user_uuid_unavailable');
        (new StripeCheckout($this->kirby))->ordersFor(new User(['id' => 'buyer']));
    }

    public function testFailedReducerReleasesItsLockAndReentrantWritesFail(): void
    {
        $page = $this->createOrder();
        $store = new OrderPageStore($this->kirby);
        $uuid = $page->uuid()->toString();

        try {
            $store->update($uuid, static fn(array $data): array => $store->data($store->update($uuid, static fn(array $data): array => $data)));
            $this->fail('Expected reentrant write rejection.');
        } catch (OrderStorageException $error) {
            $this->assertSame('persistence.reentrant_write', $error->errorCode());
        }

        $updated = $page->update(['note' => 'Recovered']);
        $this->assertSame('Recovered', $this->value($updated, 'note'));
    }

    public function testCanonicalUpdateAdvancesTimeBeforeValidatingObservations(): void
    {
        $earlier = '2025-01-01T00:00:00Z';
        $page = $this->createOrder(createdAt: new DateTimeImmutable($earlier));
        $now = OrderData::timestamp(new DateTimeImmutable());
        $updated = (new OrderPageStore($this->kirby))->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'open',
            'stripeCheckoutSessionId' => 'cs_test',
            'stripeShippingRateIds' => [],
            'checkoutOpenedAt' => $now,
        ]);
        $this->assertSame($now, $this->value($updated, 'checkoutOpenedAt'));
        $this->assertGreaterThanOrEqual($now, $this->value($updated, 'updatedAt'));
    }

    public function testDiagnosticsExposeInvalidRecordsWithoutCustomerData(): void
    {
        $page = $this->createOrder();
        $page->version('latest')->update(['subtotal' => 'private-invalid-value'], 'default');
        $report = (new LocalDiagnostics($this->kirby))->report();
        $check = $report->check('orders');

        $this->assertNotNull($check);
        $this->assertSame('fail', $check->status()->value);
        $this->assertSame(['count' => '1'], $check->context());
        $this->assertStringNotContainsString('private-invalid-value', json_encode($report->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testCustomFieldHooksSeeTheirNativeBeforeAndAfterValuesOnly(): void
    {
        $seen = [];
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(hooks: [
            'page.update:before' => function (Page $page) use (&$seen): void {
                $seen[] = ['before', $page->content()->data()['note'] ?? null];
            },
            'page.update:after' => function (Page $newPage, Page $oldPage) use (&$seen): void {
                $seen[] = ['after', $oldPage->content()->data()['note'] ?? null, $newPage->content()->data()['note'] ?? null];
            },
        ]);
        $this->kirby = $this->environment->app();
        $page = $this->createOrder();
        $page->update(['note' => 'Updated']);
        (new OrderPageStore($this->kirby))->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'creation_uncertain',
            'creationUncertainAt' => $data['createdAt'],
        ]);
        $this->assertSame([['before', null], ['after', null, 'Updated']], $seen);
    }

    public function testMissingCanonicalContentCannotBeRecreatedByAnUpdate(): void
    {
        $page = $this->createOrder();
        $file = $page->root() . '/stripe-checkout-order.txt';
        F::remove($file);

        try {
            $page->update(['note' => 'Must not recreate']);
            $this->fail('Expected unavailable canonical content.');
        } catch (OrderStorageException) {
            $this->assertFileDoesNotExist($file);
            $this->assertNull((new StripeCheckout($this->kirby))->order($page->uuid()->toString()));
        }
    }

    private function value(?Page $page, string $field): mixed
    {
        return $page?->content('default')->data()[strtolower($field)] ?? null;
    }

    /** @param list<SelectedOption> $selectedOptions */
    private function createOrder(
        ?string $userUuid = null,
        ?DateTimeImmutable $createdAt = null,
        CredentialMode $credentialMode = CredentialMode::Test,
        array $selectedOptions = [],
    ): OrderPage {
        $price = Money::of('16', 'EUR');
        $requestedOptions = [];

        foreach ($selectedOptions as $selectedOption) {
            $requestedOptions[$selectedOption->optionId()] = $selectedOption->valueId();
        }

        $product = new Product(
            request: new ProductRequest('product', 2, $requestedOptions),
            name: 'Product',
            requiresShipping: false,
            price: new Price($price),
            selectedOptions: $selectedOptions,
            variantId: $selectedOptions === [] ? null : 'selected-variant',
        );
        $checkout = new CheckoutContext(
            items: [new CheckoutLineItem($product)],
            languageCode: $this->kirby->languageCode(),
            locale: 'en_US',
            userUuid: $userUuid,
            checkoutSource: CheckoutSource::Direct,
            uiMode: UiMode::Hosted,
        );
        $context = (new RuntimeFactory($this->kirby))->checkoutPreparationFactory()->create(
            uuid: Uuid::generate(),
            checkout: $checkout,
            shipping: new ShippingContext(null),
        )->order();
        $createdAt ??= new DateTimeImmutable();

        return (new OrderPageStore($this->kirby))->create(
            context: $context,
            checkoutAttempt: CheckoutAttemptFactory::create(
                order: $context,
                createdAt: $createdAt,
                guestReference: $userUuid === null ? 'guest' : null,
                credentialMode: $credentialMode,
            ),
            createdAt: $createdAt,
        );
    }
}
