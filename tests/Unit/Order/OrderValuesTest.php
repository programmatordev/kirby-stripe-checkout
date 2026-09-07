<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Order;

use Brick\Money\Money;
use DateTimeImmutable;
use Kirby\Data\Txt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEvent;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEventType;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\DisputeStatus;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderCustomFieldsValidator;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderNumberFormatter;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSchema;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSerializer;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Order\RefundStatus;
use ProgrammatorDev\StripeCheckout\Product\InlinePrice;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use RuntimeException;
use stdClass;

final class OrderValuesTest extends TestCase
{
    public function testCreationContextContainsOnlyFrozenInitiatingFacts(): void
    {
        $context = $this->context();
        $this->assertSame('Abc123def456GHI7', $context->uuid());
        $this->assertSame('page://Abc123def456GHI7', $context->pageUuid());
        $this->assertSame('ORD-ABC123DEF456GHI7', $context->orderNumber());
        $this->assertSame(CheckoutSource::Cart, $context->sourceType());
        $this->assertSame('revision', $context->cartRevision());
        $this->assertNull($context->userUuid());
        $this->assertSame('en', $context->languageCode());
        $this->assertSame('hosted', $context->uiMode());
        $this->assertSame('EUR', $context->currency());
        $this->assertSame('32.00', (string) $context->subtotal()->getAmount());
        $lines = $context->lineItems();
        $this->assertIsArray($lines[0]['options']);
        $this->assertIsArray($lines[0]['options'][0]);
        $this->assertSame('Large', $lines[0]['options'][0]['valueName']);
        $this->assertSame('SHIRT-L', $lines[0]['sku']);
        $lines[0]['name'] = 'Changed';
        $this->assertSame('T-shirt', $context->lineItems()[0]['name']);
    }

    public function testCreationRoundTripsThroughNativeKirbyTextAndYaml(): void
    {
        $data = $this->data();
        $fields = OrderSerializer::encode($data);
        $decoded = OrderSerializer::decode(OrderData::map(Txt::decode(Txt::encode($fields))), OrderSchema::TEMPLATE, 'Abc123def456GHI7');
        $this->assertSame($data, $decoded);
        $this->assertSame('0', $fields['refundedTotal']);
        $this->assertSame('false', $fields['refundHasActive']);
        $this->assertArrayNotHasKey('userUuid', $data);
        $this->assertArrayNotHasKey('total', $data);
        $this->assertArrayNotHasKey('locale', $data);
        $this->assertSame('2026-09-05T10:20:30Z', $data['createdAt']);
        $this->assertSame('creating', $data['checkoutStatus']);
        $this->assertSame('unpaid', $data['paymentStatus']);
    }

    #[DataProvider('currencies')]
    public function testExactPriceAndProviderUnits(string $currency, string $amount, int $providerPrice): void
    {
        $price = Money::of($amount, $currency);
        $line = OrderLineSnapshot::fromProduct(new ResolvedProduct(new ProductRequest('product', 2), 'Product', false, new InlinePrice($price)), $price);
        $data = $line->toArray();
        $this->assertSame([
            'price' => $providerPrice,
            'subtotal' => $providerPrice * 2,
        ], $data['providerAmounts']);
        $this->assertSame((string) $price->getAmount(), $data['price']);
        $this->assertSame($data, OrderLineSnapshot::fromArray($data)->toArray());
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function currencies(): iterable
    {
        yield 'zero price' => ['EUR', '0', 0];
        yield 'two decimals' => ['EUR', '16.00', 1600];
        yield 'zero decimals' => ['JPY', '16', 16];
        yield 'three ISO decimals' => ['BHD', '16.12', 1612];
        yield 'ISK whole two decimals' => ['ISK', '16', 1600];
        yield 'UGX whole two decimals' => ['UGX', '16', 1600];
        yield 'MGA zero provider decimals' => ['MGA', '16', 16];
    }

    public function testStripeLinesRetainResolvedMoneyAndPriceProductReferences(): void
    {
        $line = OrderLineSnapshot::fromProduct(new ResolvedProduct(new ProductRequest('product'), 'Stripe product', false, new StripePriceReference('price_test')), Money::of('25', 'EUR'), 'prod_test');
        $data = $line->toArray();
        $this->assertSame('stripe', $data['priceSource']);
        $this->assertSame('price_test', $data['stripePriceId']);
        $this->assertSame('prod_test', $data['stripeProductId']);
        $this->assertSame('25.00', $data['price']);
    }

    public function testUnicodeProductTextSurvivesOrderSerialization(): void
    {
        $price = Money::of('16', 'EUR');
        $product = new ResolvedProduct(
            new ProductRequest('product', 1, ['size' => 'large']),
            'T-shirt — Edição 日本語 👕',
            false,
            new InlinePrice($price),
            [new SelectedOption('size', 'Tamanho', 'large', 'Grande — 大')],
            variantId: 'large-variant',
        );
        $data = OrderSerializer::creation($this->context(lines: [OrderLineSnapshot::fromProduct($product, $price)]), hash('sha256', 'token'), hash('sha256', 'request'), 'guest', new DateTimeImmutable());
        $fields = OrderData::map(Txt::decode(Txt::encode(OrderSerializer::encode($data))));
        $this->assertSame($data, OrderSerializer::decode($fields, OrderSchema::TEMPLATE, 'Abc123def456GHI7'));
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

    public function testDefinitiveCreationFailureRetainsEarlierUncertainty(): void
    {
        $data = $this->dataWithCheckoutStatus(CheckoutStatus::CreationFailed);
        $data['creationUncertainAt'] = $data['createdAt'];
        $this->assertSame(OrderData::map($data), OrderSerializer::normalize($data));
    }

    #[DataProvider('invalidLines')]
    public function testRejectsCorruptOrUnknownInitiatingLineFacts(string $field, mixed $value): void
    {
        $data = $this->line()->toArray();
        $data[$field] = $value;
        $this->expectException(OrderDataException::class);
        OrderLineSnapshot::fromArray($data);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidLines(): iterable
    {
        yield 'float' => ['price', 16.0];
        yield 'negative' => ['price', '-16'];
        yield 'inexact' => ['price', '16.001'];
        yield 'overflow' => ['price', '99999999999999999999999999999'];
        yield 'wrong total' => ['subtotal', '33.00'];
        yield 'provider units' => ['providerAmounts', [
            'price' => 16,
            'subtotal' => 32,
        ]];
        yield 'string provider units' => ['providerAmounts', [
            'price' => '1600',
            'subtotal' => 3200,
        ]];
        yield 'quantity' => ['quantity', 0];
        yield 'fraction quantity' => ['quantity', 1.5];
        yield 'source mix' => ['stripePriceId', 'price_test'];
        yield 'missing options' => ['options', []];
        yield 'unknown fact' => ['cardNumber', 'private'];
        yield 'unknown option' => ['options', [[
            'optionId' => 'size',
            'optionName' => 'Size',
            'valueId' => 'large',
            'valueName' => 'Large',
            'extra' => true,
        ]]];
        yield 'SDK object' => ['metadata', new stdClass()];
    }

    public function testContextRejectsMixedSources(): void
    {
        $stripe = OrderLineSnapshot::fromProduct(new ResolvedProduct(new ProductRequest('other'), 'Other', false, new StripePriceReference('price_other')), Money::of('16', 'EUR'));
        $this->expectException(OrderDataException::class);
        $this->context(lines: [$this->line(), $stripe]);
    }

    public function testContextRejectsMixedCurrencies(): void
    {
        $price = Money::of('16', 'USD');
        $line = OrderLineSnapshot::fromProduct(new ResolvedProduct(new ProductRequest('other'), 'Other', false, new InlinePrice($price)), $price);
        $this->expectException(OrderDataException::class);
        $this->context(lines: [$this->line(), $line]);
    }

    public function testDirectAndSingleLanguageFactsRemainExplicit(): void
    {
        $context = $this->context(source: CheckoutSource::Direct, revision: null, language: null, user: 'user://customer');
        $data = OrderSerializer::creation($context, hash('sha256', 'token'), hash('sha256', 'request'), null, new DateTimeImmutable());
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

    #[DataProvider('invalidSnapshotKeys')]
    public function testRequiredSnapshotKeysCannotBeOmittedOrReplaced(string $scope, string $key, bool $replace): void
    {
        $data = $this->data();
        $attempt = OrderData::map($data['checkoutAttempt']);
        $line = $this->line()->toArray();
        $options = OrderData::list($line['options']);
        $option = OrderData::map($options[0]);
        $snapshot = match ($scope) {
            'attempt' => $attempt,
            'line' => $line,
            'option' => $option,
            default => $this->fail('Unknown snapshot fixture.'),
        };
        unset($snapshot[$key]);

        if ($replace) {
            $snapshot['unexpected'] = null;
        }

        $this->expectException(OrderDataException::class);

        if ($scope === 'attempt') {
            $data['checkoutAttempt'] = $snapshot;
            OrderSerializer::normalize($data);
        } else {
            if ($scope === 'option') {
                $line['options'] = [$snapshot];
            } else {
                $line = $snapshot;
            }

            OrderLineSnapshot::fromArray($line);
        }
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function invalidSnapshotKeys(): iterable
    {
        $fields = [
            'attempt' => ['tokenHash', 'requestFingerprint', 'source', 'cartRevision', 'guestReference', 'uiMode'],
            'line' => ['description', 'sku', 'variantId', 'stripePriceId', 'stripeProductId'],
            'option' => ['optionId', 'optionName', 'valueId', 'valueName'],
        ];

        foreach ($fields as $scope => $keys) {
            foreach ($keys as $key) {
                yield $scope . '.' . $key . ' missing' => [$scope, $key, false];
                yield $scope . '.' . $key . ' replaced' => [$scope, $key, true];
            }
        }
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
        OrderSerializer::decode($fields, OrderSchema::TEMPLATE, 'Abc123def456GHI7');
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
        yield [OrderSchema::TEMPLATE, 'different'];
    }

    #[DataProvider('checkoutStates')]
    public function testCheckoutStateRequiresItsOwnTimestamp(CheckoutStatus $state, string $timestamp, bool $session): void
    {
        $data = $this->data();
        $data['checkoutStatus'] = $state->value;
        $data[$timestamp] = $data['createdAt'];

        if ($session) {
            $data['stripeCheckoutSessionId'] = 'cs_test';
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

        $this->assertSame('0', OrderSerializer::normalize($data)['total']);
        unset($data['taxTotal']);
        $this->expectException(OrderDataException::class);
        OrderSerializer::normalize($data);
    }

    /** @return iterable<array{PaymentStatus}> */
    public static function paymentStates(): iterable
    {
        $states = [PaymentStatus::Pending, PaymentStatus::Paid, PaymentStatus::NoPaymentRequired, PaymentStatus::Failed];

        foreach ($states as $state) {
            yield [$state];
        }
    }

    #[DataProvider('validUuids')]
    public function testUuidValidationPreservesTheCompleteReference(string $uuid, string $type): void
    {
        $this->assertSame($uuid, OrderData::uuid($uuid, $type));
    }

    /** @return iterable<array{string, string}> */
    public static function validUuids(): iterable
    {
        yield ['page://Abc123def456GHI7', 'page'];
        yield ['page://4c2c8a52-284b-4d3b-9126-5216ff3428ba', 'page'];
        yield ['user://customer', 'user'];
    }

    #[DataProvider('invalidUuids')]
    public function testUuidValidationRejectsMalformedOrWrongTypeReferences(string $uuid): void
    {
        $this->expectException(OrderDataException::class);
        OrderData::uuid($uuid);
    }

    /** @return iterable<array{string}> */
    public static function invalidUuids(): iterable
    {
        yield [''];
        yield ['Abc123'];
        yield ['page://'];
        yield ['user://customer'];
        yield ['page://Abc123/path'];
        yield ['page://Abc123?query=value'];
        yield ['page://Abc123#fragment'];
        yield [' page://Abc123'];
        yield ["page://Abc123\n"];
    }

    public function testNumberFormatterAcceptsNativeShortAndV4Ids(): void
    {
        $formatter = new OrderNumberFormatter();
        $this->assertSame('ORD-ABC123', $formatter->format('Abc123'));
        $this->assertSame('ORD-4C2C8A52-284B-4D3B-9126-5216FF3428BA', $formatter->format('4c2c8a52-284b-4d3b-9126-5216ff3428ba'));
        $custom = new OrderNumberFormatter(function (string $uuid): string {
            $this->assertSame('page://Abc123', $uuid);
            return '  WEB-123  ';
        });
        $this->assertSame('WEB-123', $custom->format('Abc123'));
        $this->assertSame(str_repeat('É', 80), (new OrderNumberFormatter(static fn(): string => str_repeat('É', 80)))->format('id'));
    }

    #[DataProvider('invalidNumbers')]
    public function testNumberValidationDoesNotLeakCallbackData(string $number): void
    {
        $this->expectException(OrderDataException::class);
        $this->expectExceptionMessage('order.number_invalid');
        (new OrderNumberFormatter(static fn(): string => $number))->format('id');
    }

    /** @return iterable<array{string}> */
    public static function invalidNumbers(): iterable
    {
        yield [''];
        yield ['  '];
        yield ["ORDER\n"];
        yield ["ORDER\u{2028}NEXT"];
        yield ["\xff"];
        yield [str_repeat('É', 81)];
    }

    public function testFormatterFailureIsSanitized(): void
    {
        try {
            (new OrderNumberFormatter(static fn(): never => throw new RuntimeException('private credential')))->format('id');
            $this->fail('Expected invalid formatter.');
        } catch (OrderDataException $error) {
            $this->assertSame('order.number_invalid', $error->errorCode());
            $this->assertStringNotContainsString('private', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    #[DataProvider('invalidContexts')]
    public function testContextRejectsBrokenIdentityOrSourceFacts(string $kind): void
    {
        $this->expectException(OrderDataException::class);

        match ($kind) {
            'cart revision' => $this->context(revision: null),
            'direct revision' => $this->context(source: CheckoutSource::Direct),
            'user id' => $this->context(user: 'customer@example.com'),
            'user UUID path' => $this->context(user: 'user://customer/path'),
            'empty language' => $this->context(language: ''),
            'empty lines' => $this->context(lines: []),
            'too many lines' => $this->context(lines: array_fill(0, 101, $this->line())),
            'non list' => $this->context(lines: ['item' => $this->line()]),
            default => $this->fail('Unknown fixture.'),
        };
    }

    /** @return iterable<array{string}> */
    public static function invalidContexts(): iterable
    {
        $kinds = ['cart revision', 'direct revision', 'user id', 'user UUID path', 'empty language', 'empty lines', 'too many lines', 'non list'];

        foreach ($kinds as $kind) {
            yield [$kind];
        }
    }

    public function testInlineSnapshotCannotSubstituteADifferentPrice(): void
    {
        $product = new ResolvedProduct(new ProductRequest('product'), 'Product', false, new InlinePrice(Money::of('16', 'EUR')));
        $this->expectException(OrderDataException::class);
        OrderLineSnapshot::fromProduct($product, Money::of('17', 'EUR'));
    }

    public function testBothOrNeitherActorAreRejected(): void
    {
        $contexts = [$this->context(), $this->context(user: 'user://customer')];

        foreach ($contexts as $context) {
            try {
                OrderSerializer::creation($context, hash('sha256', 'token'), hash('sha256', 'request'), $context->userUuid() === null ? null : 'guest', new DateTimeImmutable());
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
        $this->assertSame($data, OrderSerializer::decode($fields, OrderSchema::TEMPLATE, 'Abc123def456GHI7'));
        $fields['UUID'] = 'other';
        $this->expectException(OrderDataException::class);
        OrderSerializer::decode($fields, OrderSchema::TEMPLATE, 'Abc123def456GHI7');
    }

    public function testResourcesAreNotCustomFieldData(): void
    {
        $resource = fopen('php://memory', 'r+');

        try {
            $this->expectException(OrderDataException::class);
            OrderCustomFieldsValidator::validate(['data' => $resource]);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    #[DataProvider('invalidLifecycleFacts')]
    public function testLifecycleFactsMustMatchTheirSnapshot(string $kind): void
    {
        $snapshot = $this->data();

        if ($kind === 'snapshot') {
            $snapshot['paymentStatus'] = 'paid';
        }

        $this->expectException(OrderDataException::class);
        new LifecycleEvent('delivery', LifecycleEventType::OrderCreated, 'page://Abc123def456GHI7', new DateTimeImmutable(), $kind === 'revision' ? 0 : 1, $kind === 'language' ? 'pt' : 'en', CheckoutStatus::Creating, PaymentStatus::Unpaid, RefundStatus::None, DisputeStatus::None, $kind === 'trigger' ? 'event.type' : null, null, $snapshot);
    }

    /** @return iterable<array{string}> */
    public static function invalidLifecycleFacts(): iterable
    {
        $kinds = ['snapshot', 'revision', 'language', 'trigger'];

        foreach ($kinds as $kind) {
            yield [$kind];
        }
    }

    public function testCustomFieldsNormalizeNativeHandlesAndPreserveAllowedValues(): void
    {
        $fields = OrderCustomFieldsValidator::validate([
            'salesChannel' => 'website',
            'internal_note' => "First\nSecond",
            'data' => [
                'b' => [true, null, 2],
                'a' => 'text',
            ],
        ]);
        $this->assertSame(['data', 'internal_note', 'saleschannel'], array_keys($fields));
        $this->assertIsArray($fields['data']);
        $this->assertSame(['a', 'b'], array_keys($fields['data']));
        $this->assertSame([true, null, 2], $fields['data']['b']);

        $reservedFields = [...OrderSchema::fields(), 'slug', 'template'];

        foreach ($reservedFields as $field) {
            $this->assertTrue(OrderSchema::isReserved(strtoupper($field)));

            try {
                OrderCustomFieldsValidator::validate([strtoupper($field) => null]);
                $this->fail('Reserved field accepted: ' . $field);
            } catch (OrderDataException $error) {
                $this->assertSame('order.custom_fields_invalid', $error->errorCode());
            }
        }
    }

    #[DataProvider('invalidCustomFields')]
    public function testRejectsUnstableOrReservedCustomFields(mixed $fields): void
    {
        $this->expectException(OrderDataException::class);
        $this->expectExceptionMessage('order.custom_fields_invalid');
        OrderCustomFieldsValidator::validate($fields);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidCustomFields(): iterable
    {
        yield 'float' => [['data' => 1.1]];
        yield 'object' => [['data' => new stdClass()]];
        yield 'callable' => [['data' => static fn(): string => 'value']];
        yield 'invalid UTF8' => [['data' => "\xff"]];
        yield 'numeric handle' => [[0 => 'value']];
        yield 'slugged alias' => [['sales channel' => 'value']];
        yield 'case collision' => [[
            'field' => null,
            'FIELD' => 'value',
        ]];
        yield 'list' => [['one']];
        yield 'not map' => [false];
    }

    public function testCyclicArraysAreRejectedAndReferencesAreDetached(): void
    {
        $name = 'Original';
        $fields = OrderCustomFieldsValidator::validate(['name' => &$name]);
        $name = 'Changed';
        $this->assertSame('Original', $fields['name']);
        $recursive = [];
        $recursive['self'] = &$recursive;
        $this->expectException(OrderDataException::class);
        OrderCustomFieldsValidator::validate(['data' => $recursive]);
    }

    public function testLifecyclePayloadIsImmutablePortableAndDoesNotInventAnEvent(): void
    {
        $data = $this->data();
        $data['saleschannel'] = 'website';
        $event = new LifecycleEvent('delivery-1', LifecycleEventType::OrderCreated, 'page://Abc123def456GHI7', new DateTimeImmutable('2026-09-05T11:20:30.123+01:00'), 1, 'en', CheckoutStatus::Creating, PaymentStatus::Unpaid, RefundStatus::None, DisputeStatus::None, null, null, $data);
        $data['saleschannel'] = 'changed';
        $this->assertSame('website', $event->orderSnapshot()['saleschannel']);
        $this->assertSame('delivery-1', $event->deliveryId());
        $this->assertSame(LifecycleEventType::OrderCreated, $event->type());
        $this->assertSame('page://Abc123def456GHI7', $event->pageUuid());
        $this->assertSame('2026-09-05T10:20:30Z', OrderData::timestamp($event->occurredAt()));
        $this->assertSame(1, $event->revision());
        $this->assertSame('en', $event->languageCode());
        $this->assertSame(CheckoutStatus::Creating, $event->checkoutStatus());
        $this->assertSame(PaymentStatus::Unpaid, $event->paymentStatus());
        $this->assertSame(RefundStatus::None, $event->refundStatus());
        $this->assertSame(DisputeStatus::None, $event->disputeStatus());
        $this->assertNull($event->triggerType());
        $this->assertNull($event->triggerId());
        $this->assertSame($event->toArray(), json_decode(json_encode($event->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame('order.created', $event->toArray()['type']);
    }

    /** @param array<array-key, OrderLineSnapshot>|null $lines */
    private function context(?array $lines = null, CheckoutSource $source = CheckoutSource::Cart, ?string $revision = 'revision', ?string $language = 'en', ?string $user = null): OrderCreationContext
    {
        return new OrderCreationContext('Abc123def456GHI7', 'ORD-ABC123DEF456GHI7', $source, $revision, $user, $language, 'hosted', 'EUR', $lines ?? [$this->line()]);
    }

    private function line(): OrderLineSnapshot
    {
        $price = Money::of('16', 'EUR');
        $product = new ResolvedProduct(new ProductRequest('page://shirt', 2, ['size' => 'large']), 'T-shirt', true, new InlinePrice($price), [new SelectedOption('size', 'Size', 'large', 'Large')], imageUrls: ['https://example.com/shirt.jpg'], sku: 'SHIRT-L', variantId: 'large-variant');

        return OrderLineSnapshot::fromProduct($product, $price);
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return OrderSerializer::creation($this->context(), hash('sha256', 'token'), hash('sha256', 'request'), 'guest', new DateTimeImmutable('2026-09-05T11:20:30+01:00'));
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
        }

        if ($state === CheckoutStatus::Complete) {
            $data['paymentStatus'] = 'pending';
            $data['discountTotal'] = '0';
            $data['shippingTotal'] = '0';
            $data['taxTotal'] = '0';
            $data['total'] = '32.00';
        }

        return $data;
    }
}
