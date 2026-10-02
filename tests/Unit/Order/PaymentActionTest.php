<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Order;

use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\PaymentAction;
use Stripe\StripeObject;

final class PaymentActionTest extends TestCase
{
    public function testRoundTripsUnknownProviderDataAndReturnsIndependentSdkObjects(): void
    {
        $details = [
            'future_number' => 1.25,
            'nullable_url' => null,
            'nested' => ['codes' => ['first', 'second']],
        ];
        $action = PaymentAction::fromArray([
            'type' => 'future_action',
            'future_action' => &$details,
            'inactive_action' => ['secret' => 'DO_NOT_CAPTURE'],
        ]) ?? $this->fail('An active branch must be captured.');
        $details['future_number'] = 99;
        $restored = PaymentAction::fromJson($action->toJson());
        $this->assertSame('future_action', $restored->type());
        $this->assertSame(1.25, $restored->details()['future_number']);
        $this->assertNull($restored->details()['nullable_url']);
        $this->assertSame(['codes' => ['first', 'second']], $restored->details()->toArray()['nested']);
        $this->assertStringNotContainsString('DO_NOT_CAPTURE', $action->toJson());

        $projection = $restored->toPaymentIntent();
        $this->assertInstanceOf(StripeObject::class, $projection->next_action);
        $branch = $projection->next_action['future_action'];
        $this->assertInstanceOf(StripeObject::class, $branch);
        $nested = $branch['nested'];
        $this->assertInstanceOf(StripeObject::class, $nested);
        $nested['codes'] = ['MUTATED'];
        $this->assertSame(['codes' => ['first', 'second']], $restored->details()->toArray()['nested']);
        $this->assertNull($projection->client_secret ?? null);
    }

    public function testEmptyActiveBranchIsDifferentFromNoAction(): void
    {
        $this->assertNull(PaymentAction::fromArray(null));
        $action = PaymentAction::fromArray([
            'type' => 'parameterless_action',
            'parameterless_action' => [],
        ]) ?? $this->fail('An empty active branch is still an action.');
        $this->assertSame($action->toArray(), PaymentAction::fromJson($action->toJson())->toArray());
    }

    public function testDeepestAcceptedActionRoundTripsThroughJson(): void
    {
        $details = ['value' => 1];

        for ($depth = 0; $depth < 29; $depth++) {
            $details = ['nested' => $details];
        }

        $action = PaymentAction::fromArray([
            'type' => 'future_action',
            'future_action' => $details,
        ]) ?? $this->fail('An action within the JSON depth limit must be accepted.');
        $this->assertSame($action->toArray(), PaymentAction::fromJson($action->toJson())->toArray());

        $this->expectException(OrderDataException::class);
        PaymentAction::fromArray([
            'type' => 'future_action',
            'future_action' => ['nested' => $details],
        ]);
    }

    public function testNumericMapsStayDistinctFromListsAndHaveStableKeyOrdering(): void
    {
        $first = PaymentAction::fromArray([
            'type' => 'future_action',
            'future_action' => [
                'values' => [
                    1 => 'b',
                    2 => 'c',
                    0 => 'a',
                ],
            ],
        ]) ?? $this->fail('Numeric provider maps must be accepted.');
        $reordered = PaymentAction::fromArray([
            'type' => 'future_action',
            'future_action' => [
                'values' => [
                    2 => 'c',
                    0 => 'a',
                    1 => 'b',
                ],
            ],
        ]) ?? $this->fail('Reordered numeric provider maps must be accepted.');
        $list = PaymentAction::fromArray([
            'type' => 'future_action',
            'future_action' => ['values' => ['a', 'b', 'c']],
        ]) ?? $this->fail('Nested provider lists must be accepted.');

        $this->assertSame('{"type":"future_action","future_action":{"values":{"2":"c","1":"b","0":"a"}}}', $first->toJson());
        $this->assertSame($first->toJson(), $reordered->toJson());
        $this->assertNotSame(hash('sha256', $first->toJson()), hash('sha256', $list->toJson()));
        $this->assertSame($first->toArray(), PaymentAction::fromJson($first->toJson())->toArray());
        $values = $first->details()->toArray()['values'];
        $this->assertIsArray($values);
        $this->assertFalse(array_is_list($values));
    }

    #[DataProvider('invalidStoredActions')]
    public function testRejectsInvalidStoredActionEnvelopes(string $json): void
    {
        $this->expectException(OrderDataException::class);
        PaymentAction::fromJson($json);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidStoredActions(): iterable
    {
        yield 'malformed JSON' => ['{'];
        yield 'missing type' => ['{}'];
        yield 'missing active branch' => ['{"type":"future_action"}'];
        yield 'non-object branch' => ['{"type":"future_action","future_action":["unexpected"]}'];
        yield 'no envelope' => ['null'];
    }

    public function testRejectsPhpObjectsWithoutInvokingTheirSerialization(): void
    {
        $object = new class implements JsonSerializable {
            public bool $serialized = false;

            public function jsonSerialize(): mixed
            {
                $this->serialized = true;

                return 'UNSAFE';
            }
        };

        try {
            PaymentAction::fromArray([
                'type' => 'future_action',
                'future_action' => ['payload' => $object],
            ]);
            $this->fail('PHP objects are not provider JSON.');
        } catch (OrderDataException) {
            $this->assertFalse($object->serialized);
        }
    }
}
