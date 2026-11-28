<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Development;

use Brick\Money\Money;
use Closure;
use DateInterval;
use DateTimeImmutable;
use Generator;
use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartEntry;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\AttemptBinding;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\AttemptToken;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutAttempt;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Collection\CustomFieldType;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPage;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\Internal\DisputeCollection;
use ProgrammatorDev\StripeCheckout\Order\Internal\DisputeSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\PaymentSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\RefundCollection;
use ProgrammatorDev\StripeCheckout\Order\Internal\RefundSnapshot;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Order\Payment;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailureType;
use Stripe\Charge;
use Stripe\Checkout\Session;
use Stripe\Dispute;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\ShippingRate;

/** Development-only, deterministic Order catalogue for visual Panel review. */
final class OrderPanelFixtureSeeder
{
    private const CURRENCY = 'EUR';

    private const ORDER_NUMBERS = [
        'panelorder000001' => 'ORD-FIX-CREATING',
        'panelorder000002' => 'ORD-FIX-UNCERTAIN',
        'panelorder000003' => 'ORD-FIX-CREATION-FAILED',
        'panelorder000004' => 'ORD-FIX-OPEN',
        'panelorder000005' => 'ORD-FIX-EXPIRED',
        'panelorder000006' => 'ORD-FIX-PAID-DIGITAL',
        'panelorder000007' => 'ORD-FIX-PAID-PHYSICAL',
        'panelorder000008' => 'ORD-FIX-PAID-RICH',
        'panelorder000009' => 'ORD-FIX-PAYMENT-PENDING',
        'panelorder000010' => 'ORD-FIX-PAYMENT-FAILED',
        'panelorder000011' => 'ORD-FIX-NO-PAYMENT',
        'panelorder000012' => 'ORD-FIX-REFUND-PENDING',
        'panelorder000013' => 'ORD-FIX-REFUND-PARTIAL',
        'panelorder000014' => 'ORD-FIX-REFUND-FULL',
        'panelorder000015' => 'ORD-FIX-REFUND-FAILED',
        'panelorder000016' => 'ORD-FIX-DISPUTE-RESPONSE',
        'panelorder000017' => 'ORD-FIX-DISPUTE-REVIEW',
        'panelorder000018' => 'ORD-FIX-DISPUTE-LOST',
        'panelorder000019' => 'ORD-FIX-DISPUTE-WON',
        'panelorder000020' => 'ORD-FIX-FINANCIAL-MIXED',
        'panelorder000021' => 'ORD-FIX-REFUND-ACTION',
    ];

    private readonly OrderPageStore $store;

    public function __construct(App $kirby)
    {
        $this->store = new OrderPageStore($kirby);
    }

    /** @return Generator<string, bool> Order number to whether it was created. */
    public function seed(): Generator
    {
        foreach ($this->fixtures() as $uuid => $seed) {
            $pageUuid = OrderData::pageUuid($uuid);
            $existing = $this->store->order($pageUuid);

            if ($existing !== null) {
                $existingData = $this->store->data($existing);
                $orderNumber = OrderData::string($existingData['orderNumber']);
                $checkoutAttempt = OrderData::map($existingData['checkoutAttempt']);

                if (
                    $orderNumber !== self::ORDER_NUMBERS[$uuid]
                    || $checkoutAttempt['guestReference'] !== 'fixture-' . $uuid
                ) {
                    throw new \LogicException('The deterministic fixture UUID is already owned by another Order.');
                }
            }

            // Multi-step scenarios may have stopped after their initial Page creation.
            // Resume only a recognized fixture so reruns repair that partial state without replacing unrelated Orders.
            $page = $seed($existing);
            yield OrderData::string($this->store->data($page)['orderNumber']) => $existing === null;
        }
    }

    /** @return array<string, Closure(?OrderPage): OrderPage> */
    private function fixtures(): array
    {
        // Stable dates keep the catalogue ordering predictable; rerunning the command recreates fixtures removed by housekeeping.
        $createdAt = new DateTimeImmutable('2026-11-01T09:00:00Z');

        return [
            'panelorder000001' => fn(?OrderPage $page): OrderPage => $this->fixtureOrder(
                page: $page,
                uuid: 'panelorder000001',
                createdAt: $createdAt,
            ),
            'panelorder000002' => fn(?OrderPage $page): OrderPage => $this->creationUncertain(
                $this->fixtureOrder(
                    page: $page,
                    uuid: 'panelorder000002',
                    createdAt: $createdAt->modify('+1 day'),
                ),
            ),
            'panelorder000003' => fn(?OrderPage $page): OrderPage => $this->creationFailed(
                $this->fixtureOrder(
                    page: $page,
                    uuid: 'panelorder000003',
                    createdAt: $createdAt->modify('+2 days'),
                ),
            ),
            'panelorder000004' => fn(?OrderPage $page): OrderPage => $this->open(
                $this->fixtureOrder(
                    page: $page,
                    uuid: 'panelorder000004',
                    createdAt: $createdAt->modify('+3 days'),
                    lineItems: [$this->lineItem('Canvas tote', '24.00', requiresShipping: true)],
                ),
            ),
            'panelorder000005' => fn(?OrderPage $page): OrderPage => $this->expired(
                $this->fixtureOrder(
                    page: $page,
                    uuid: 'panelorder000005',
                    createdAt: $createdAt->modify('+4 days'),
                ),
            ),
            'panelorder000006' => fn(?OrderPage $page): OrderPage => $this->withEmailOnlyCustomer(
                $this->ensureComplete(
                    $this->fixtureOrder(
                        page: $page,
                        uuid: 'panelorder000006',
                        createdAt: $createdAt->modify('+5 days'),
                        checkoutSource: CheckoutSource::Direct,
                        lineItems: [$this->lineItem('Digital guide', '18.00')],
                    ),
                    PaymentStatus::Paid,
                ),
            ),
            'panelorder000007' => fn(?OrderPage $page): OrderPage => $this->withShipping(
                $this->ensureComplete(
                    $this->fixtureOrder(
                        page: $page,
                        uuid: 'panelorder000007',
                        createdAt: $createdAt->modify('+6 days'),
                        lineItems: [$this->lineItem(
                            name: 'Limited print',
                            price: '42.00',
                            requiresShipping: true,
                            priceSource: PriceSource::Stripe,
                        )],
                    ),
                    PaymentStatus::Paid,
                ),
                '7.50',
            ),
            'panelorder000008' => fn(?OrderPage $page): OrderPage => $this->withRichDetails(
                $this->ensureComplete(
                    $this->fixtureOrder(
                        page: $page,
                        uuid: 'panelorder000008',
                        createdAt: $createdAt->modify('+7 days'),
                        lineItems: [
                            $this->lineItem(
                                name: 'Studio shirt',
                                price: '24.00',
                                quantity: 2,
                                requiresShipping: true,
                                options: [[
                                    'optionId' => 'size',
                                    'optionName' => 'Size',
                                    'valueId' => 'large',
                                    'valueName' => 'Large',
                                ]],
                            ),
                            $this->lineItem('Ceramic mug', '20.00', requiresShipping: true),
                        ],
                    ),
                    PaymentStatus::Paid,
                ),
            ),
            'panelorder000009' => fn(?OrderPage $page): OrderPage => $this->ensureComplete(
                $this->fixtureOrder(
                    page: $page,
                    uuid: 'panelorder000009',
                    createdAt: $createdAt->modify('+8 days'),
                ),
                PaymentStatus::Pending,
            ),
            'panelorder000010' => fn(?OrderPage $page): OrderPage => $this->ensureComplete(
                $this->fixtureOrder(
                    page: $page,
                    uuid: 'panelorder000010',
                    createdAt: $createdAt->modify('+9 days'),
                ),
                PaymentStatus::Failed,
            ),
            'panelorder000011' => fn(?OrderPage $page): OrderPage => $this->ensureComplete(
                $this->fixtureOrder(
                    page: $page,
                    uuid: 'panelorder000011',
                    createdAt: $createdAt->modify('+10 days'),
                    lineItems: [$this->lineItem('Complimentary download', '0.00')],
                ),
                PaymentStatus::NoPaymentRequired,
            ),
            'panelorder000012' => fn(?OrderPage $page): OrderPage => $this->withRefund(
                $this->ensureComplete(
                    $this->fixtureOrder(
                        page: $page,
                        uuid: 'panelorder000012',
                        createdAt: $createdAt->modify('+11 days'),
                    ),
                    PaymentStatus::Paid,
                ),
                Refund::STATUS_PENDING,
                '16.00',
            ),
            'panelorder000013' => fn(?OrderPage $page): OrderPage => $this->withRefund(
                $this->ensureComplete(
                    $this->fixtureOrder(
                        page: $page,
                        uuid: 'panelorder000013',
                        createdAt: $createdAt->modify('+12 days'),
                    ),
                    PaymentStatus::Paid,
                ),
                Refund::STATUS_SUCCEEDED,
                '16.00',
            ),
            'panelorder000014' => fn(?OrderPage $page): OrderPage => $this->withFullRefund(
                $this->ensureComplete(
                    $this->fixtureOrder(
                        page: $page,
                        uuid: 'panelorder000014',
                        createdAt: $createdAt->modify('+13 days'),
                    ),
                    PaymentStatus::Paid,
                ),
            ),
            'panelorder000015' => fn(?OrderPage $page): OrderPage => $this->withRefund(
                $this->ensureComplete(
                    $this->fixtureOrder(
                        page: $page,
                        uuid: 'panelorder000015',
                        createdAt: $createdAt->modify('+14 days'),
                    ),
                    PaymentStatus::Paid,
                ),
                Refund::STATUS_FAILED,
                '16.00',
            ),
            'panelorder000016' => fn(?OrderPage $page): OrderPage => $this->withDispute(
                $this->ensureComplete(
                    $this->fixtureOrder(
                        page: $page,
                        uuid: 'panelorder000016',
                        createdAt: $createdAt->modify('+15 days'),
                    ),
                    PaymentStatus::Paid,
                ),
                Dispute::STATUS_NEEDS_RESPONSE,
            ),
            'panelorder000017' => fn(?OrderPage $page): OrderPage => $this->withDispute(
                $this->ensureComplete(
                    $this->fixtureOrder(
                        page: $page,
                        uuid: 'panelorder000017',
                        createdAt: $createdAt->modify('+16 days'),
                    ),
                    PaymentStatus::Paid,
                ),
                Dispute::STATUS_UNDER_REVIEW,
            ),
            'panelorder000018' => fn(?OrderPage $page): OrderPage => $this->withDispute(
                $this->ensureComplete(
                    $this->fixtureOrder(
                        page: $page,
                        uuid: 'panelorder000018',
                        createdAt: $createdAt->modify('+17 days'),
                    ),
                    PaymentStatus::Paid,
                ),
                Dispute::STATUS_LOST,
            ),
            'panelorder000019' => fn(?OrderPage $page): OrderPage => $this->withDispute(
                $this->ensureComplete(
                    $this->fixtureOrder(
                        page: $page,
                        uuid: 'panelorder000019',
                        createdAt: $createdAt->modify('+18 days'),
                    ),
                    PaymentStatus::Paid,
                ),
                Dispute::STATUS_WON,
            ),
            'panelorder000020' => fn(?OrderPage $page): OrderPage => $this->withMixedDisputes(
                $this->withRefund(
                    $this->ensureComplete(
                        $this->fixtureOrder(
                            page: $page,
                            uuid: 'panelorder000020',
                            createdAt: $createdAt->modify('+19 days'),
                        ),
                        PaymentStatus::Paid,
                    ),
                    Refund::STATUS_SUCCEEDED,
                    '16.00',
                ),
            ),
            'panelorder000021' => fn(?OrderPage $page): OrderPage => $this->withRefund(
                $this->ensureComplete(
                    $this->fixtureOrder(
                        page: $page,
                        uuid: 'panelorder000021',
                        createdAt: $createdAt->modify('+20 days'),
                    ),
                    PaymentStatus::Paid,
                ),
                Refund::STATUS_REQUIRES_ACTION,
                '16.00',
            ),
        ];
    }

    /** @param list<OrderLineItemSnapshot>|null $lineItems */
    private function fixtureOrder(
        ?OrderPage $page,
        string $uuid,
        DateTimeImmutable $createdAt,
        CheckoutSource $checkoutSource = CheckoutSource::Cart,
        ?array $lineItems = null,
    ): OrderPage {
        return $page ?? $this->createOrder(
            uuid: $uuid,
            createdAt: $createdAt,
            checkoutSource: $checkoutSource,
            lineItems: $lineItems,
        );
    }

    /** @param list<OrderLineItemSnapshot>|null $lineItems */
    private function createOrder(
        string $uuid,
        DateTimeImmutable $createdAt,
        CheckoutSource $checkoutSource = CheckoutSource::Cart,
        ?array $lineItems = null,
    ): OrderPage {
        $lineItems ??= [$this->lineItem('Everyday shirt', '32.00', options: [[
            'optionId' => 'colour',
            'optionName' => 'Colour',
            'valueId' => 'navy',
            'valueName' => 'Navy',
        ]])];
        $cartRevision = $checkoutSource === CheckoutSource::Cart ? 'revision-' . $uuid : null;
        $context = new OrderCreationContext(
            uuid: $uuid,
            orderNumber: self::ORDER_NUMBERS[$uuid],
            checkoutSource: $checkoutSource,
            cartRevision: $cartRevision,
            userUuid: null,
            languageCode: 'en',
            uiMode: UiMode::Hosted,
            currency: self::CURRENCY,
            lineItems: $lineItems,
        );

        return $this->store->create(
            context: $context,
            checkoutAttempt: $this->checkoutAttempt($context, $createdAt),
            createdAt: $createdAt,
        );
    }

    /** @param list<array<string, string>> $options */
    private function lineItem(
        string $name,
        string $price,
        int $quantity = 1,
        bool $requiresShipping = false,
        PriceSource $priceSource = PriceSource::Kirby,
        array $options = [],
    ): OrderLineItemSnapshot {
        $slug = strtolower(str_replace(' ', '-', $name));
        $priceMoney = Money::of($price, self::CURRENCY);
        $subtotal = $priceMoney->multipliedBy($quantity);
        $registry = new StripeCurrencyRegistry();

        return OrderLineItemSnapshot::fromArray([
            'reference' => 'page://' . str_replace('-', '', $slug),
            'quantity' => $quantity,
            'variantId' => $options === [] ? null : $slug . '-variant',
            'name' => $name,
            'description' => 'Development fixture product for Order Panel review.',
            'images' => ['https://example.test/' . $slug . '.jpg'],
            'sku' => strtoupper(substr(str_replace('-', '', $slug), 0, 10)),
            'requiresShipping' => $requiresShipping,
            'options' => $options,
            'metadata' => [
                'fixture' => true,
            ],
            'priceSource' => $priceSource->value,
            'stripePriceId' => $priceSource === PriceSource::Stripe ? 'price_fixture_' . $slug : null,
            'stripeProductId' => $priceSource === PriceSource::Stripe ? 'prod_fixture_' . $slug : null,
            'taxCode' => $priceSource === PriceSource::Kirby ? 'txcd_fixture' : null,
            'currency' => self::CURRENCY,
            'price' => (string) $priceMoney->getAmount(),
            'subtotal' => (string) $subtotal->getAmount(),
            'providerAmounts' => [
                'price' => $registry->fromMoney($priceMoney)->minorAmount(),
                'subtotal' => $registry->fromMoney($subtotal)->minorAmount(),
            ],
        ]);
    }

    private function checkoutAttempt(OrderCreationContext $context, DateTimeImmutable $createdAt): CheckoutAttempt
    {
        $expiresAt = $createdAt->add(new DateInterval(CheckoutAttempt::SESSION_LIFETIME));
        $requestParameters = [
            'client_reference_id' => $context->pageUuid(),
            'currency' => strtolower($context->currency()),
            'expires_at' => $expiresAt->getTimestamp(),
            'mode' => Session::MODE_PAYMENT,
        ];

        if ($context->requiresShipping()) {
            $requestParameters['shipping_options'] = [[
                'shipping_rate' => $this->shippingRateId($context->uuid()),
            ]];
        }

        $request = new SessionRequest($requestParameters);
        $requestContext = new SessionRequestContext(
            order: $context,
            locale: 'en',
            expiresAt: $expiresAt,
            initiatingUrl: 'https://kirby-stripe-checkout.ddev.site/products',
            successDestination: 'https://kirby-stripe-checkout.ddev.site/checkout-success',
            cancelDestination: 'https://kirby-stripe-checkout.ddev.site/cart',
            returnDestination: 'https://kirby-stripe-checkout.ddev.site/stripe-checkout/return',
        );
        $productRequests = array_map(
            static fn(OrderLineItemSnapshot $lineItem): ProductRequest => new ProductRequest(
                $lineItem->productReference(),
                $lineItem->quantity(),
            ),
            $context->lineItems(),
        );
        $guestReference = 'fixture-' . $context->uuid();
        $binding = $context->checkoutSource() === CheckoutSource::Cart
            ? AttemptBinding::cart(
                cart: new CartSnapshot(
                    id: 'cart-' . $context->uuid(),
                    revision: $context->cartRevision() ?? throw new \LogicException('Fixture cart revision missing.'),
                    entries: array_map(
                        static fn(int $index, ProductRequest $productRequest): CartEntry => new CartEntry(
                            'item-' . $index,
                            $productRequest,
                        ),
                        array_keys($productRequests),
                        $productRequests,
                    ),
                    createdAt: $createdAt->getTimestamp(),
                    updatedAt: $createdAt->getTimestamp(),
                ),
                contextFingerprint: hash('sha256', 'fixture-context-' . $context->uuid()),
                guestReference: $guestReference,
            )
            : AttemptBinding::direct(
                items: $productRequests,
                contextFingerprint: hash('sha256', 'fixture-context-' . $context->uuid()),
                guestReference: $guestReference,
            );

        return new CheckoutAttempt(
            order: $context,
            context: $requestContext,
            request: $request,
            binding: $binding,
            token: AttemptToken::forOrder(
                $context->uuid(),
                static fn(int $length): string => str_repeat('f', $length),
            ),
            guestReference: $guestReference,
            stripeApiVersion: '2026-09-30.endive',
            credentialMode: CredentialMode::Test,
            credentialFingerprint: hash('sha256', 'fixture-credential'),
            createdAt: $createdAt,
        );
    }

    private function creationUncertain(OrderPage $page): OrderPage
    {
        return $this->store->update($page->uuid()->toString(), static function (array $data): array {
            $observedAt = OrderData::date($data['createdAt'])->modify('+2 minutes');
            $checkoutAttempt = OrderData::map($data['checkoutAttempt']);
            $checkoutAttempt['providerFailure'] = [
                'type' => CheckoutSessionFailureType::Uncertain->value,
                'requestId' => 'req_fixture_uncertain',
                'providerCode' => null,
                'providerType' => 'api_connection_error',
                'retryable' => true,
                'occurredAt' => OrderData::timestamp($observedAt),
            ];

            return [
                ...$data,
                'checkoutAttempt' => $checkoutAttempt,
                'checkoutStatus' => CheckoutStatus::CreationUncertain->value,
                'creationUncertainAt' => OrderData::timestamp($observedAt),
            ];
        });
    }

    private function creationFailed(OrderPage $page): OrderPage
    {
        return $this->store->update($page->uuid()->toString(), static function (array $data): array {
            $observedAt = OrderData::date($data['createdAt'])->modify('+2 minutes');
            $checkoutAttempt = OrderData::map($data['checkoutAttempt']);
            $checkoutAttempt['providerFailure'] = [
                'type' => CheckoutSessionFailureType::Rejected->value,
                'requestId' => 'req_fixture_failed',
                'providerCode' => 'parameter_invalid',
                'providerType' => 'invalid_request_error',
                'retryable' => false,
                'occurredAt' => OrderData::timestamp($observedAt),
            ];

            return [
                ...$data,
                'checkoutAttempt' => $checkoutAttempt,
                'checkoutStatus' => CheckoutStatus::CreationFailed->value,
                'creationFailedAt' => OrderData::timestamp($observedAt),
            ];
        });
    }

    private function open(OrderPage $page): OrderPage
    {
        return $this->store->update($page->uuid()->toString(), fn(array $data): array => [
            ...$data,
            'checkoutStatus' => CheckoutStatus::Open->value,
            'stripeCheckoutSessionId' => $this->sessionId($page),
            'stripeShippingRateIds' => $this->shippingRateIds($data),
            'checkoutOpenedAt' => OrderData::timestamp(OrderData::date($data['createdAt'])->modify('+2 minutes')),
        ]);
    }

    private function expired(OrderPage $page): OrderPage
    {
        return $this->store->update($page->uuid()->toString(), fn(array $data): array => [
            ...$data,
            'checkoutStatus' => CheckoutStatus::Expired->value,
            'stripeCheckoutSessionId' => $this->sessionId($page),
            'stripeShippingRateIds' => $this->shippingRateIds($data),
            'checkoutExpiredAt' => $data['checkoutExpiresAt'],
        ]);
    }

    private function complete(
        OrderPage $page,
        PaymentStatus $paymentStatus,
    ): OrderPage {
        return $this->store->update($page->uuid()->toString(), function (array $data) use ($page, $paymentStatus): array {
            $completedAt = OrderData::date($data['createdAt'])->modify('+5 minutes');
            $subtotal = Money::of(OrderData::string($data['subtotal']), self::CURRENCY);
            $totalValue = $paymentStatus === PaymentStatus::NoPaymentRequired
                ? '0'
                : (string) $subtotal->getAmount();
            $facts = [
                ...$data,
                'checkoutStatus' => CheckoutStatus::Complete->value,
                'paymentStatus' => $paymentStatus->value,
                'stripeCheckoutSessionId' => $this->sessionId($page),
                'stripeShippingRateIds' => $this->shippingRateIds($data),
                'checkoutCompletedAt' => OrderData::timestamp($completedAt),
                'discountTotal' => '0',
                'shippingTotal' => '0',
                'taxTotal' => '0',
                'total' => $totalValue,
                'customFields' => [],
                'discounts' => [],
                'lineItems' => $this->checkoutLineItems(
                    OrderData::list($data['initiatingLineItems']),
                    $paymentStatus === PaymentStatus::NoPaymentRequired,
                ),
                'customer' => $this->customer($page->slug()),
                'billingAddress' => $this->address('Billing recipient'),
                ...$this->paymentFacts($page->slug(), $paymentStatus, $totalValue, $completedAt),
            ];

            return $facts;
        });
    }

    private function ensureComplete(OrderPage $page, PaymentStatus $paymentStatus): OrderPage
    {
        $data = $this->store->data($page);

        // Refresh the base payment projection without rebuilding the completed order or discarding later refund and dispute snapshots.
        if (
            $data['checkoutStatus'] === CheckoutStatus::Complete->value
            && $data['paymentStatus'] === $paymentStatus->value
        ) {
            return $this->store->update($page->uuid()->toString(), fn(array $data): array => [
                ...$data,
                ...$this->paymentFacts(
                    uuid: $page->slug(),
                    paymentStatus: $paymentStatus,
                    total: OrderData::string($data['total']),
                    createdAt: OrderData::date($data['checkoutCompletedAt']),
                ),
            ]);
        }

        return $this->complete($page, $paymentStatus);
    }

    private function withEmailOnlyCustomer(OrderPage $page): OrderPage
    {
        return $this->store->update($page->uuid()->toString(), static function (array $data) use ($page): array {
            $data['customer'] = [
                'email' => 'fixture+' . $page->slug() . '@example.test',
                'individualName' => null,
                'businessName' => null,
                'phone' => null,
                'taxIds' => [],
            ];
            unset($data['billingAddress']);

            return $data;
        });
    }

    private function withShipping(OrderPage $page, string $shippingTotal): OrderPage
    {
        return $this->store->update($page->uuid()->toString(), function (array $data) use ($page, $shippingTotal): array {
            $subtotal = Money::of(OrderData::string($data['subtotal']), self::CURRENCY);
            $total = $subtotal->plus(Money::of($shippingTotal, self::CURRENCY));
            $totalValue = (string) $total->getAmount();
            $payment = $this->payment(
                uuid: $page->slug(),
                paymentStatus: PaymentStatus::Paid,
                total: $totalValue,
                createdAt: OrderData::date($data['checkoutCompletedAt']),
            );

            return [
                ...$data,
                'shippingTotal' => $shippingTotal,
                'total' => $totalValue,
                'stripeShippingRateId' => $this->shippingRateId($page->slug()),
                'shippingAddress' => $this->address('Shipping recipient'),
                'shipping' => $this->shipping($shippingTotal, $page->slug()),
                'payment' => $payment->toArray(),
            ];
        });
    }

    private function withRichDetails(OrderPage $page): OrderPage
    {
        return $this->store->update($page->uuid()->toString(), function (array $data) use ($page): array {
            $completedAt = OrderData::date($data['createdAt'])->modify('+5 minutes');
            $discount = $this->discount();
            $total = '80.76';

            return [
                ...$data,
                'checkoutStatus' => CheckoutStatus::Complete->value,
                'paymentStatus' => PaymentStatus::Paid->value,
                'stripeCheckoutSessionId' => $this->sessionId($page),
                'stripeCustomerId' => 'cus_fixture_rich',
                'stripePaymentIntentId' => 'pi_fixture_' . $page->slug(),
                'stripeChargeId' => 'ch_fixture_' . $page->slug(),
                'stripeShippingRateIds' => $this->shippingRateIds($data),
                'stripeShippingRateId' => $this->shippingRateId($page->slug()),
                'checkoutCompletedAt' => OrderData::timestamp($completedAt),
                'paidAt' => OrderData::timestamp($completedAt),
                'discountTotal' => '5.00',
                'shippingTotal' => '7.50',
                'taxTotal' => '10.26',
                'total' => $total,
                'customFields' => [
                    [
                        'key' => 'giftmessage',
                        'type' => CustomFieldType::Text->value,
                        'label' => 'Gift message',
                        'required' => false,
                        'configured' => true,
                        'answered' => true,
                        'value' => 'Happy birthday!',
                    ],
                    [
                        'key' => 'deliveryslot',
                        'type' => CustomFieldType::Dropdown->value,
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
                'discounts' => [$discount],
                'lineItems' => $this->richCheckoutLineItems(OrderData::list($data['initiatingLineItems']), $discount),
                'customer' => [
                    'email' => 'purchasing@example.test',
                    'individualName' => 'Alex Morgan',
                    'businessName' => 'Fixture Studio Ltd',
                    'phone' => '+351 210 000 008',
                    'taxIds' => [[
                        'type' => 'eu_vat',
                        'value' => 'PT500000008',
                    ]],
                ],
                'billingAddress' => $this->address('Fixture Studio Ltd'),
                'shippingAddress' => $this->address('Alex Morgan'),
                'payment' => $this->payment(
                    uuid: $page->slug(),
                    paymentStatus: PaymentStatus::Paid,
                    total: $total,
                    createdAt: $completedAt,
                )->toArray(),
                'shipping' => $this->shipping('7.50', $page->slug()),
                'tax' => [
                    'automaticTaxEnabled' => true,
                    'calculationStatus' => 'complete',
                    'provider' => 'stripe',
                    'currency' => self::CURRENCY,
                    'amount' => '10.26',
                    'providerAmount' => 1026,
                    'breakdown' => null,
                ],
            ];
        });
    }

    private function withRefund(OrderPage $page, string $status, string $amount): OrderPage
    {
        return $this->withRefunds($page, [
            $this->refund($page, 'primary', $status, $amount),
        ]);
    }

    private function withFullRefund(OrderPage $page): OrderPage
    {
        return $this->withRefunds($page, [
            $this->refund($page, 'first', Refund::STATUS_SUCCEEDED, '12.00'),
            $this->refund($page, 'second', Refund::STATUS_SUCCEEDED, '20.00'),
        ]);
    }

    /** @param list<RefundSnapshot> $refunds */
    private function withRefunds(OrderPage $page, array $refunds): OrderPage
    {
        return $this->store->update($page->uuid()->toString(), static function (array $data) use ($refunds): array {
            $observedAt = OrderData::date($data['checkoutCompletedAt'])->modify('+1 hour');
            $payment = Payment::fromArray(OrderData::map($data['payment']));
            $paymentIntentId = $payment->stripePaymentIntentId() ?? throw new \LogicException('Fixture payment intent missing.');
            $paymentAmount = $payment->amount() ?? throw new \LogicException('Fixture payment amount missing.');
            $collection = RefundCollection::fromSnapshots($refunds, $paymentIntentId, $paymentAmount)->observed(null, $observedAt);
            $refundedTotal = $collection->refundedTotal();

            return [
                ...$data,
                'refundStatus' => $collection->refundStatus()->value,
                'refundHasActive' => $collection->refundHasActive(),
                'refundRequiresAction' => $collection->refundRequiresAction(),
                'refundHasFailed' => $collection->refundHasFailed(),
                'refundedTotal' => $refundedTotal->isZero() ? '0' : (string) $refundedTotal->getAmount(),
                'refundUpdatedAt' => OrderData::timestamp($observedAt),
                'refunds' => $collection->toArray(),
            ];
        });
    }

    private function refund(OrderPage $page, string $suffix, string $status, string $amount): RefundSnapshot
    {
        $data = $this->store->data($page);
        $createdAt = OrderData::date($data['checkoutCompletedAt']);
        $paymentIntentId = OrderData::string($data['stripePaymentIntentId']);

        return RefundSnapshot::fromStripe([
            'id' => 're_fixture_' . $suffix . '_' . $page->slug(),
            'object' => Refund::OBJECT_NAME,
            'amount' => Money::of($amount, self::CURRENCY)->getMinorAmount()->toInt(),
            'currency' => strtolower(self::CURRENCY),
            'payment_intent' => $paymentIntentId,
            'charge' => $data['stripeChargeId'],
            'created' => $createdAt->modify('+30 minutes')->getTimestamp(),
            'status' => $status,
            'reason' => 'requested_by_customer',
            'failure_reason' => $status === Refund::STATUS_FAILED ? 'declined' : null,
            'pending_reason' => $status === Refund::STATUS_PENDING ? 'processing' : null,
        ], $paymentIntentId);
    }

    private function withDispute(OrderPage $page, string $status): OrderPage
    {
        return $this->withDisputes($page, [
            $this->dispute($page, 'primary', $status),
        ]);
    }

    private function withMixedDisputes(OrderPage $page): OrderPage
    {
        return $this->withDisputes($page, [
            $this->dispute($page, 'lost', Dispute::STATUS_LOST),
            $this->dispute($page, 'won', Dispute::STATUS_WON),
        ]);
    }

    /** @param list<DisputeSnapshot> $disputes */
    private function withDisputes(OrderPage $page, array $disputes): OrderPage
    {
        return $this->store->update($page->uuid()->toString(), static function (array $data) use ($disputes): array {
            $observedAt = OrderData::date($data['checkoutCompletedAt'])->modify('+2 hours');
            $payment = Payment::fromArray(OrderData::map($data['payment']));
            $paymentIntentId = $payment->stripePaymentIntentId() ?? throw new \LogicException('Fixture payment intent missing.');
            $paymentAmount = $payment->amount() ?? throw new \LogicException('Fixture payment amount missing.');
            $collection = DisputeCollection::fromSnapshots($disputes, $paymentIntentId, $paymentAmount)->observed(null, $observedAt);

            return [
                ...$data,
                'disputeStatus' => $collection->disputeStatus()->value,
                'disputeRequiresResponse' => $collection->disputeRequiresResponse(),
                'disputeHasLost' => $collection->disputeHasLost(),
                'disputeUpdatedAt' => OrderData::timestamp($observedAt),
                'disputes' => $collection->toArray(),
            ];
        });
    }

    private function dispute(OrderPage $page, string $suffix, string $status): DisputeSnapshot
    {
        $data = $this->store->data($page);
        $observedAt = OrderData::date($data['checkoutCompletedAt'])->modify('+2 hours');
        $paymentIntentId = OrderData::string($data['stripePaymentIntentId']);

        return DisputeSnapshot::fromStripe([
            'id' => 'dp_fixture_' . $suffix . '_' . $page->slug(),
            'object' => Dispute::OBJECT_NAME,
            'amount' => 1600,
            'currency' => strtolower(self::CURRENCY),
            'payment_intent' => $paymentIntentId,
            'charge' => $data['stripeChargeId'],
            'created' => $observedAt->getTimestamp(),
            'status' => $status,
            'reason' => 'fraudulent',
            'evidence_details' => [
                'due_by' => $status === Dispute::STATUS_NEEDS_RESPONSE
                    ? $observedAt->modify('+7 days')->getTimestamp()
                    : null,
                'has_evidence' => $status !== Dispute::STATUS_NEEDS_RESPONSE,
                'past_due' => false,
                'submission_count' => $status === Dispute::STATUS_NEEDS_RESPONSE ? 0 : 1,
            ],
            'balance_transactions' => [],
        ], $paymentIntentId);
    }

    /**
     * @param list<mixed> $initiatingLineItems
     * @return list<array<string, mixed>>
     */
    private function checkoutLineItems(array $initiatingLineItems, bool $isFree): array
    {
        $lineItems = [];

        foreach ($initiatingLineItems as $index => $value) {
            $lineItem = OrderData::map($value);
            $lineItems[] = [
                'stripeLineItemId' => 'li_fixture_' . $index,
                'initiatingIndex' => $index,
                'stripePriceId' => $lineItem['stripePriceId'] ?? 'price_fixture_' . $index,
                'stripeProductId' => $lineItem['stripeProductId'] ?? 'prod_fixture_' . $index,
                'quantity' => $lineItem['quantity'],
                'description' => $lineItem['description'],
                'currency' => self::CURRENCY,
                'price' => $lineItem['price'],
                'subtotal' => $lineItem['subtotal'],
                'discount' => '0',
                'tax' => '0',
                'total' => $isFree ? '0' : $lineItem['subtotal'],
                'discounts' => [],
            ];
        }

        return $lineItems;
    }

    /**
     * @param list<mixed> $initiatingLineItems
     * @param array<string, mixed> $discount
     * @return list<array<string, mixed>>
     */
    private function richCheckoutLineItems(array $initiatingLineItems, array $discount): array
    {
        $lineItems = [];
        // Explicit provider allocations keep the fixture's line totals, discount, tax, shipping and 80.76 order total correlated.
        $totals = [
            [
                'discount' => '5.00',
                'tax' => '7.74',
                'total' => '50.74',
                'discounts' => [$discount],
            ],
            [
                'discount' => '0',
                'tax' => '2.52',
                'total' => '22.52',
                'discounts' => [],
            ],
        ];

        foreach ($initiatingLineItems as $index => $value) {
            $lineItem = OrderData::map($value);
            $lineTotal = $totals[$index];
            $lineItems[] = [
                'stripeLineItemId' => 'li_fixture_rich_' . $index,
                'initiatingIndex' => $index,
                'stripePriceId' => 'price_fixture_rich_' . $index,
                'stripeProductId' => 'prod_fixture_rich_' . $index,
                'quantity' => $lineItem['quantity'],
                'description' => $lineItem['description'],
                'currency' => self::CURRENCY,
                'price' => $lineItem['price'],
                'subtotal' => $lineItem['subtotal'],
                ...$lineTotal,
            ];
        }

        return $lineItems;
    }

    /** @return array<string, mixed> */
    private function paymentFacts(
        string $uuid,
        PaymentStatus $paymentStatus,
        string $total,
        DateTimeImmutable $createdAt,
    ): array {
        $facts = [];

        if ($paymentStatus !== PaymentStatus::NoPaymentRequired) {
            $payment = $this->payment($uuid, $paymentStatus, $total, $createdAt);
            $facts = [
                'stripePaymentIntentId' => $payment->stripePaymentIntentId(),
                'stripeChargeId' => $payment->stripeChargeId(),
                'payment' => $payment->toArray(),
            ];
        }

        if (in_array($paymentStatus, [PaymentStatus::Paid, PaymentStatus::NoPaymentRequired], true)) {
            $facts['paidAt'] = OrderData::timestamp($createdAt);
        }

        if ($paymentStatus === PaymentStatus::Failed) {
            $facts['paymentFailedAt'] = OrderData::timestamp($createdAt);
        }

        return $facts;
    }

    private function payment(
        string $uuid,
        PaymentStatus $paymentStatus,
        string $total,
        DateTimeImmutable $createdAt,
    ): Payment {
        $paid = $paymentStatus === PaymentStatus::Paid;
        $failed = $paymentStatus === PaymentStatus::Failed;
        $amount = Money::of($total, self::CURRENCY);

        return Payment::fromSnapshot(new PaymentSnapshot(
            amount: $amount,
            amountReceived: $paid ? $amount : Money::zero(self::CURRENCY),
            stripePaymentIntentId: 'pi_fixture_' . $uuid,
            stripeChargeId: $paid || $failed ? 'ch_fixture_' . $uuid : null,
            stripePaymentMethodId: 'pm_fixture_' . $uuid,
            paymentIntentStatus: match ($paymentStatus) {
                PaymentStatus::Paid => PaymentIntent::STATUS_SUCCEEDED,
                PaymentStatus::Pending => PaymentIntent::STATUS_PROCESSING,
                PaymentStatus::Failed => PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD,
                default => null,
            },
            chargeStatus: $paid ? Charge::STATUS_SUCCEEDED : ($failed ? Charge::STATUS_FAILED : null),
            methodType: $paymentStatus === PaymentStatus::Pending ? 'sepa_debit' : 'card',
            createdAt: $createdAt->getTimestamp(),
            chargeCreatedAt: $paid || $failed ? $createdAt->getTimestamp() : null,
            chargePaid: $paid || $failed ? $paid : null,
            chargeCaptured: $paid || $failed ? $paid : null,
            amountCaptured: $paid ? $amount : ($failed ? Money::zero(self::CURRENCY) : null),
            failureCode: $failed ? 'card_declined' : null,
            failureMessage: $failed ? 'The card was declined.' : null,
        ), $paymentStatus, self::CURRENCY);
    }

    /** @return array<string, mixed> */
    private function customer(string $uuid): array
    {
        return [
            'email' => 'fixture+' . $uuid . '@example.test',
            'individualName' => 'Fixture Customer',
            'businessName' => null,
            'phone' => null,
            'taxIds' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function address(string $name): array
    {
        return [
            'name' => $name,
            'line1' => '8 Fixture Avenue',
            'line2' => 'Floor 2',
            'postalCode' => '1000-008',
            'city' => 'Lisbon',
            'state' => 'Lisbon',
            'country' => 'PT',
        ];
    }

    /** @return array<string, mixed> */
    private function shipping(string $total, string $uuid): array
    {
        $providerTotal = Money::of($total, self::CURRENCY)->getMinorAmount()->toInt();

        return [
            'optionKey' => 'standard',
            'quoteFingerprint' => hash('sha256', 'fixture-shipping-' . $uuid),
            'label' => 'Standard delivery',
            'currency' => self::CURRENCY,
            'subtotal' => $total,
            'providerSubtotal' => $providerTotal,
            'tax' => '0.00',
            'providerTax' => 0,
            'total' => $total,
            'providerTotal' => $providerTotal,
            'deliveryEstimate' => [
                'minimum' => 2,
                'maximum' => 4,
                'unit' => 'business_day',
            ],
            'taxBehavior' => ShippingRate::TAX_BEHAVIOR_EXCLUSIVE,
            'taxCode' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function discount(): array
    {
        return [
            'discountId' => 'di_fixture_welcome',
            'couponId' => 'coupon_fixture_welcome',
            'promotionCodeId' => 'promo_fixture_welcome',
            'couponName' => 'Welcome offer',
            'promotionCode' => 'WELCOME5',
            'amount' => '5.00',
            'currency' => self::CURRENCY,
            'providerAmount' => 500,
            'percentOff' => null,
            'appliesToProducts' => [],
            'firstTimeTransaction' => true,
            'minimumAmount' => null,
            'minimumAmountCurrency' => null,
            'providerMinimumAmount' => null,
        ];
    }

    private function sessionId(OrderPage $page): string
    {
        return 'cs_test_fixture_' . $page->slug();
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function shippingRateIds(array $data): array
    {
        foreach (OrderData::list($data['initiatingLineItems']) as $lineItem) {
            if (OrderData::map($lineItem)['requiresShipping'] === true) {
                return [$this->shippingRateId(OrderData::string($data['uuid']))];
            }
        }

        return [];
    }

    private function shippingRateId(string $uuid): string
    {
        return 'shr_fixture_' . $uuid;
    }
}
