<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Order;

use Brick\Money\Money;
use DateTimeImmutable;
use Kirby\Data\Txt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSchema;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSerializer;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use ProgrammatorDev\StripeCheckout\Test\Support\CheckoutAttemptFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\OrderFixture;
use Stripe\ShippingRate;

final class OrderSerializationTest extends TestCase
{
    public function testCreationRoundTripsThroughNativeKirbyTextAndYaml(): void
    {
        $data = $this->data();
        $fields = OrderSerializer::encode($data);
        $decoded = OrderSerializer::decode(OrderData::map(Txt::decode(Txt::encode($fields))), OrderSchema::ORDER_PAGE_TEMPLATE, 'Abc123def456GHI7');
        $this->assertSame($data, $decoded);
        $this->assertSame('0', $fields['refundedTotal']);
        $this->assertSame('false', $fields['refundHasActive']);
        $this->assertArrayNotHasKey('userUuid', $data);
        $this->assertArrayNotHasKey('initiatingShipping', $data);
        $this->assertArrayNotHasKey('stripeShippingRateIds', $data);
        $this->assertArrayNotHasKey('total', $data);
        $this->assertArrayNotHasKey('locale', $data);
        $this->assertSame('2026-09-05T10:20:30Z', $data['createdAt']);
        $this->assertSame('creating', $data['checkoutStatus']);
        $this->assertSame('unpaid', $data['paymentStatus']);
    }

    public function testUnicodeProductTextSurvivesOrderSerialization(): void
    {
        $price = Money::of('16', 'EUR');
        $product = new Product(
            new ProductRequest('product', 1, ['size' => 'large']),
            'T-shirt — Edição 日本語 👕',
            false,
            new Price($price),
            [new SelectedOption('size', 'Tamanho', 'large', 'Grande — 大')],
            variantId: 'large-variant',
        );
        $context = OrderFixture::context(lineItems: [OrderLineItemSnapshot::fromCheckoutLineItem(new CheckoutLineItem($product))]);
        $createdAt = new DateTimeImmutable();
        $data = OrderSerializer::creation(
            context: $context,
            checkoutAttempt: CheckoutAttemptFactory::create(
                order: $context,
                createdAt: $createdAt,
                guestReference: 'guest',
            ),
            createdAt: $createdAt,
        );
        $fields = OrderData::map(Txt::decode(Txt::encode(OrderSerializer::encode($data))));
        $this->assertSame($data, OrderSerializer::decode($fields, OrderSchema::ORDER_PAGE_TEMPLATE, 'Abc123def456GHI7'));
    }

    #[DataProvider('inconsistentTimestamps')]
    public function testRejectsInconsistentTimestampFacts(CheckoutStatus $state, string $field, string $timestamp): void
    {
        $data = $this->dataWithCheckoutStatus($state);
        $data[$field] = $timestamp;
        $this->expectException(OrderDataException::class);
        OrderSerializer::normalize($data);
    }

    /** @return iterable<string, array{CheckoutStatus, string, string}> */
    public static function inconsistentTimestamps(): iterable
    {
        $now = '2026-09-05T10:20:30Z';
        yield 'premature completion' => [CheckoutStatus::Creating, 'checkoutCompletedAt', $now];
        yield 'premature expiry' => [CheckoutStatus::Creating, 'checkoutExpiredAt', $now];
        yield 'premature opening' => [CheckoutStatus::Creating, 'checkoutOpenedAt', $now];
        yield 'premature uncertainty' => [CheckoutStatus::Creating, 'creationUncertainAt', $now];
        yield 'failure on open Session' => [CheckoutStatus::Open, 'creationFailedAt', $now];
        yield 'opening after definitive failure' => [CheckoutStatus::CreationFailed, 'checkoutOpenedAt', $now];
        yield 'completion while uncertain' => [CheckoutStatus::CreationUncertain, 'checkoutCompletedAt', $now];
        yield 'completed and expired' => [CheckoutStatus::Complete, 'checkoutExpiredAt', $now];
        yield 'expired and completed' => [CheckoutStatus::Expired, 'checkoutCompletedAt', $now];
        yield 'expiry before creation' => [CheckoutStatus::Expired, 'checkoutExpiredAt', '2020-01-01T00:00:00Z'];
        yield 'fact after update' => [CheckoutStatus::Open, 'checkoutOpenedAt', '2026-09-06T10:20:30Z'];
        yield 'deadline before creation' => [CheckoutStatus::Open, 'checkoutExpiresAt', '2020-01-01T00:00:00Z'];
    }

    public function testCompletedOrderRetainsEarlierObservationsAndAFutureDeadline(): void
    {
        $data = $this->dataWithCheckoutStatus(CheckoutStatus::Complete);
        $data['creationUncertainAt'] = $data['createdAt'];
        $data['checkoutOpenedAt'] = $data['createdAt'];
        $data['paymentFailedAt'] = $data['createdAt'];
        $data['paymentStatus'] = 'paid';
        $data['paidAt'] = $data['createdAt'];
        $data['checkoutExpiresAt'] = '2026-09-06T10:20:30Z';
        $this->assertSame(OrderData::map($data), OrderSerializer::normalize($data));
    }

    public function testCompletedOrderRevalidatesTheAuthoritativeShippingSnapshot(): void
    {
        $data = $this->dataWithCheckoutStatus(CheckoutStatus::Complete);
        $data['stripeShippingRateId'] = 'shr_standard';
        $data['shippingTotal'] = '6.15';
        $data['shipping'] = $this->shippingSnapshot();

        $normalized = OrderSerializer::normalize($data);

        $this->assertSame('shr_standard', $normalized['stripeShippingRateId']);
        $this->assertSame('6.15', $normalized['shippingTotal']);
        $this->assertSame($this->shippingSnapshot(), $normalized['shipping']);
    }

    public function testRejectsShippingSnapshotThatDisagreesWithTheOrderTotal(): void
    {
        $data = $this->dataWithCheckoutStatus(CheckoutStatus::Complete);
        $data['shippingTotal'] = '6.14';
        $data['shipping'] = $this->shippingSnapshot();

        $this->expectException(OrderDataException::class);
        OrderSerializer::normalize($data);
    }

    #[DataProvider('incompleteSelectedShippingSnapshots')]
    public function testSelectedShippingRateAndSnapshotMustBeStoredTogether(
        bool $withShippingRateId,
        bool $withShippingSnapshot,
    ): void {
        $data = $this->dataWithCheckoutStatus(CheckoutStatus::Complete);

        if ($withShippingRateId) {
            $data['stripeShippingRateId'] = 'shr_standard';
        }

        if ($withShippingSnapshot) {
            $data['shipping'] = $this->shippingSnapshot();
        }

        $this->expectException(OrderDataException::class);
        OrderSerializer::normalize($data);
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function incompleteSelectedShippingSnapshots(): iterable
    {
        yield 'Rate ID without snapshot' => [true, false];
        yield 'snapshot without Rate ID' => [false, true];
    }

    public function testRejectsPersistedShippingSnapshotWithUnknownTaxBehavior(): void
    {
        $data = $this->dataWithCheckoutStatus(CheckoutStatus::Complete);
        $data['stripeShippingRateId'] = 'shr_standard';
        $data['shippingTotal'] = '6.15';
        $data['shipping'] = [
            ...$this->shippingSnapshot(),
            'taxBehavior' => 'automatic',
        ];

        $this->expectException(OrderDataException::class);
        OrderSerializer::normalize($data);
    }

    public function testDefinitiveCreationFailureRetainsEarlierUncertainty(): void
    {
        $data = $this->dataWithCheckoutStatus(CheckoutStatus::CreationFailed);
        $data['creationUncertainAt'] = $data['createdAt'];
        $this->assertSame(OrderData::map($data), OrderSerializer::normalize($data));
    }

    public function testDirectAndSingleLanguageFactsRemainExplicit(): void
    {
        $context = OrderFixture::context(checkoutSource: CheckoutSource::Direct, revision: null, language: null, user: 'user://customer');
        $createdAt = new DateTimeImmutable();
        $data = OrderSerializer::creation(
            context: $context,
            checkoutAttempt: CheckoutAttemptFactory::create(
                order: $context,
                createdAt: $createdAt,
                guestReference: null,
            ),
            createdAt: $createdAt,
        );
        $this->assertSame('user://customer', $data['userUuid']);
        $this->assertIsArray($data['checkoutAttempt']);
        $this->assertNull($data['checkoutAttempt']['guestReference']);
        $this->assertNull($data['checkoutAttempt']['cartRevision']);
        $this->assertArrayNotHasKey('languageCode', $data);
    }

    public function testMapsHaveStableHashesWhileListOrderIsMeaningful(): void
    {
        $data = $this->data();
        $this->assertIsArray($data['checkoutAttempt']);
        $this->assertIsArray($data['stripeCheckout']);
        $reordered = array_reverse($data, true);
        $reordered['checkoutAttempt'] = array_reverse($data['checkoutAttempt'], true);
        $this->assertSame(OrderSerializer::hash($data), OrderSerializer::hash($reordered));
        $this->assertSame(OrderSerializer::encode($data), OrderSerializer::encode($reordered));
        $this->assertNotSame(OrderData::json(['a', 'b']), OrderData::json(['b', 'a']));
        $this->assertArrayNotHasKey('hash', $data['stripeCheckout']);
        $this->assertArrayNotHasKey('revision', $data['stripeCheckout']);
    }

    #[DataProvider('invalidOrders')]
    public function testRejectsMalformedCanonicalOrderData(string $field, mixed $value): void
    {
        $data = $this->data();
        $data[$field] = $value;
        $this->expectException(OrderDataException::class);
        $this->expectExceptionMessage('order.data_invalid');
        OrderSerializer::encode($data);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidOrders(): iterable
    {
        yield 'owner' => ['stripeCheckout', [
            'owner' => 'other',
            'schemaVersion' => 1,
        ]];
        yield 'schema' => ['stripeCheckout', [
            'owner' => OrderSchema::OWNER,
            'schemaVersion' => 2,
        ]];
        yield 'metadata key' => ['stripeCheckout', [
            'owner' => OrderSchema::OWNER,
            'schemaVersion' => 1,
            'secret' => 'private',
        ]];
        yield 'UUID URI in native field' => ['uuid', 'page://test'];
        yield 'title mismatch' => ['title', 'Other'];
        yield 'unknown state' => ['paymentStatus', 'succeeded'];
        yield 'required subtotal' => ['subtotal', null];
        yield 'null required snapshot' => ['initiatingLineItems', null];
        yield 'invalid subtotal' => ['subtotal', '33'];
        yield 'float subtotal' => ['subtotal', 32.0];
        yield 'currency case' => ['currency', 'eur'];
        yield 'premature final total' => ['total', '32'];
        yield 'invalid timestamp' => ['createdAt', '2026-02-30T10:20:30Z'];
        yield 'timestamp precision' => ['createdAt', '2026-09-05T10:20:30.123Z'];
        yield 'timestamp order' => ['updatedAt', '2025-09-05T10:20:30Z'];
        yield 'non bool' => ['refundHasActive', 'false'];
        yield 'unbacked summary' => ['refundedTotal', '16'];
        yield 'invented locale' => ['locale', 'en_US'];
        yield 'unvalidated provider facts' => ['payment', ['card' => ['number' => 'private']]];
    }

    public function testPhysicalIdentityAndUnknownYamlAreNotSilentlyAdopted(): void
    {
        $fields = OrderSerializer::encode($this->data());
        $fields['stripeCheckout'] = 'owner: [';
        $this->expectException(OrderDataException::class);
        OrderSerializer::decode($fields, OrderSchema::ORDER_PAGE_TEMPLATE, 'Abc123def456GHI7');
    }

    #[DataProvider('identities')]
    public function testRejectsWrongTemplateOrSlug(string $template, string $slug): void
    {
        $this->expectException(OrderDataException::class);
        OrderSerializer::decode(OrderSerializer::encode($this->data()), $template, $slug);
    }

    /** @return iterable<array{string, string}> */
    public static function identities(): iterable
    {
        yield ['product', 'Abc123def456GHI7'];
        yield [OrderSchema::ORDER_PAGE_TEMPLATE, 'different'];
    }

    #[DataProvider('invalidSessionAssociationPresence')]
    public function testShippingRateIdsArePartOfTheSessionAssociation(
        CheckoutStatus $status,
        bool $present,
    ): void {
        $data = $this->dataWithCheckoutStatus($status);

        if ($present) {
            $data['stripeShippingRateIds'] = [];
        } else {
            unset($data['stripeShippingRateIds']);
        }

        $this->expectException(OrderDataException::class);
        OrderSerializer::normalize($data);
    }

    /** @return iterable<string, array{CheckoutStatus, bool}> */
    public static function invalidSessionAssociationPresence(): iterable
    {
        yield 'present before Session association' => [CheckoutStatus::Creating, true];
        yield 'missing after Session association' => [CheckoutStatus::Open, false];
    }

    #[DataProvider('checkoutStates')]
    public function testCheckoutStateRequiresItsOwnTimestamp(CheckoutStatus $state, string $timestamp, bool $session): void
    {
        $data = $this->data();
        $data['checkoutStatus'] = $state->value;
        $data[$timestamp] = $data['createdAt'];

        if ($session) {
            $data['stripeCheckoutSessionId'] = 'cs_test';
            $data['stripeShippingRateIds'] = [];
        }

        $this->assertSame($state->value, OrderSerializer::normalize($data)['checkoutStatus']);
        unset($data[$timestamp]);
        $this->expectException(OrderDataException::class);
        OrderSerializer::normalize($data);
    }

    /** @return iterable<array{CheckoutStatus, string, bool}> */
    public static function checkoutStates(): iterable
    {
        yield [CheckoutStatus::Creating, 'createdAt', false];
        yield [CheckoutStatus::CreationUncertain, 'creationUncertainAt', false];
        yield [CheckoutStatus::CreationFailed, 'creationFailedAt', false];
        yield [CheckoutStatus::Open, 'checkoutOpenedAt', true];
        yield [CheckoutStatus::Expired, 'checkoutExpiredAt', true];
    }

    #[DataProvider('paymentStates')]
    public function testCompletedTotalsPreserveZeroWithoutInferringPayment(PaymentStatus $state): void
    {
        $data = $this->data();
        $data['checkoutStatus'] = 'complete';
        $data['checkoutCompletedAt'] = $data['createdAt'];
        $data['stripeCheckoutSessionId'] = 'cs_test';
        $data['stripeShippingRateIds'] = [];
        $data['paymentStatus'] = $state->value;

        if (in_array($state, [PaymentStatus::Paid, PaymentStatus::NoPaymentRequired], true)) {
            $data['paidAt'] = $data['createdAt'];
        }

        if ($state === PaymentStatus::Failed) {
            $data['paymentFailedAt'] = $data['createdAt'];
        }

        $amountFields = ['discountTotal', 'taxTotal', 'shippingTotal', 'total'];

        foreach ($amountFields as $field) {
            $data[$field] = '0.00';
        }

        $data['discounts'] = [];
        $data['customFields'] = [];

        $this->assertSame('0', OrderSerializer::normalize($data)['total']);
        unset($data['taxTotal']);
        $this->expectException(OrderDataException::class);
        OrderSerializer::normalize($data);
    }

    #[DataProvider('requiredCompletedSnapshots')]
    public function testCompletedOrdersRequireExplicitCollectionSnapshots(string $field): void
    {
        $data = $this->dataWithCheckoutStatus(CheckoutStatus::Complete);
        unset($data[$field]);
        $this->expectException(OrderDataException::class);
        OrderSerializer::normalize($data);
    }

    /** @return iterable<array{string}> */
    public static function requiredCompletedSnapshots(): iterable
    {
        yield ['customFields'];
        yield ['discounts'];
    }

    /** @return iterable<array{PaymentStatus}> */
    public static function paymentStates(): iterable
    {
        $states = [PaymentStatus::Pending, PaymentStatus::Paid, PaymentStatus::NoPaymentRequired, PaymentStatus::Failed];

        foreach ($states as $state) {
            yield [$state];
        }
    }

    public function testInitiatingAttemptRejectsBothOrNeitherActor(): void
    {
        $contexts = [OrderFixture::context(), OrderFixture::context(user: 'user://customer')];

        foreach ($contexts as $context) {
            try {
                $createdAt = new DateTimeImmutable();
                $guestReference = $context->userUuid() === null ? null : 'guest';
                CheckoutAttemptFactory::create(
                    order: $context,
                    createdAt: $createdAt,
                    guestReference: $guestReference,
                );
                $this->fail('Expected actor conflict.');
            } catch (OrderDataException $error) {
                $this->assertSame('order.data_invalid', $error->errorCode());
            }
        }
    }

    public function testDecoderIgnoresCustomFieldsButRejectsCanonicalAliases(): void
    {
        $data = $this->data();
        $fields = OrderSerializer::encode($data);
        $fields['customNote'] = 'Keep me';
        $this->assertSame($data, OrderSerializer::decode($fields, OrderSchema::ORDER_PAGE_TEMPLATE, 'Abc123def456GHI7'));
        $fields['UUID'] = 'other';
        $this->expectException(OrderDataException::class);
        OrderSerializer::decode($fields, OrderSchema::ORDER_PAGE_TEMPLATE, 'Abc123def456GHI7');
    }

    /** @return array<string, mixed> */
    private function dataWithCheckoutStatus(CheckoutStatus $state): array
    {
        $data = $this->data();
        $data['checkoutStatus'] = $state->value;
        $timestamp = match ($state) {
            CheckoutStatus::Creating => 'createdAt',
            CheckoutStatus::CreationUncertain => 'creationUncertainAt',
            CheckoutStatus::CreationFailed => 'creationFailedAt',
            CheckoutStatus::Open => 'checkoutOpenedAt',
            CheckoutStatus::Complete => 'checkoutCompletedAt',
            CheckoutStatus::Expired => 'checkoutExpiredAt',
        };
        $data[$timestamp] = $data['createdAt'];

        if (in_array($state, [CheckoutStatus::Open, CheckoutStatus::Complete, CheckoutStatus::Expired], true)) {
            $data['stripeCheckoutSessionId'] = 'cs_test';
            $data['stripeShippingRateIds'] = [];
        }

        if ($state === CheckoutStatus::Complete) {
            $data['paymentStatus'] = 'pending';
            $data['discountTotal'] = '0';
            $data['customFields'] = [];
            $data['discounts'] = [];
            $data['shippingTotal'] = '0';
            $data['taxTotal'] = '0';
            $data['total'] = '32.00';
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $context = OrderFixture::context();
        $createdAt = new DateTimeImmutable('2026-09-05T11:20:30+01:00');

        return OrderSerializer::creation(
            context: $context,
            checkoutAttempt: CheckoutAttemptFactory::create(
                order: $context,
                createdAt: $createdAt,
                guestReference: 'guest',
            ),
            createdAt: $createdAt,
        );
    }

    /** @return array<string, mixed> */
    private function shippingSnapshot(): array
    {
        return [
            'optionKey' => 'standard',
            'quoteFingerprint' => str_repeat('a', 64),
            'label' => 'Standard delivery',
            'currency' => 'EUR',
            'subtotal' => '5.00',
            'providerSubtotal' => 500,
            'tax' => '1.15',
            'providerTax' => 115,
            'total' => '6.15',
            'providerTotal' => 615,
            'deliveryEstimate' => [
                'minimum' => 2,
                'maximum' => 4,
                'unit' => 'business_day',
            ],
            'taxBehavior' => ShippingRate::TAX_BEHAVIOR_EXCLUSIVE,
            'taxCode' => 'txcd_92010001',
        ];
    }

    #[DataProvider('requiredAttemptKeys')]
    public function testAttemptEvidenceRequiresExplicitKeys(string $key, bool $replace): void
    {
        $data = $this->data();
        $attempt = OrderData::map($data['checkoutAttempt']);
        unset($attempt[$key]);

        if ($replace) {
            $attempt['unexpected'] = null;
        }

        $data['checkoutAttempt'] = $attempt;
        $this->expectException(OrderDataException::class);
        OrderSerializer::normalize($data);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function requiredAttemptKeys(): iterable
    {
        $keys = [
            'tokenHash',
            'bindingFingerprint',
            'requestFingerprint',
            'sessionRequest',
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
            'providerFailure',
        ];

        foreach ($keys as $key) {
            yield $key . ' missing' => [$key, false];
        }

        // A same-size replacement must fail too, not just a change in key count.
        yield 'unknown replacement' => ['tokenHash', true];
    }
}
