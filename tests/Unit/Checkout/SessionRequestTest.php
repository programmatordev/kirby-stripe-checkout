<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Checkout;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use stdClass;

final class SessionRequestTest extends TestCase
{
    public function testRequestNormalizesMapOrderAndRetainsListOrder(): void
    {
        $request = new SessionRequest([
            'mode' => 'payment',
            'metadata' => [
                'second' => 'two',
                'first' => 'one',
            ],
            'line_items' => [
                ['quantity' => 1, 'price' => 'price_first'],
                ['price' => 'price_second', 'quantity' => 2],
            ],
        ]);
        $same = new SessionRequest([
            'line_items' => [
                ['price' => 'price_first', 'quantity' => 1],
                ['quantity' => 2, 'price' => 'price_second'],
            ],
            'metadata' => [
                'first' => 'one',
                'second' => 'two',
            ],
            'mode' => 'payment',
        ]);
        $parameters = $request->parameters();
        $lineItems = $parameters['line_items'];

        $this->assertIsArray($lineItems);

        $reorderedItems = new SessionRequest([
            'line_items' => array_reverse($lineItems),
            'metadata' => $parameters['metadata'],
            'mode' => 'payment',
        ]);

        $this->assertSame($request->parameters(), $same->parameters());
        $this->assertSame($request->fingerprint(), $same->fingerprint());
        $this->assertNotSame($request->fingerprint(), $reorderedItems->fingerprint());
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $request->fingerprint());

        $parameters['mode'] = 'changed';
        $this->assertSame('payment', $request->parameters()['mode']);
    }

    /** @param array<mixed, mixed> $parameters */
    #[DataProvider('invalidRequests')]
    public function testRejectsValuesOutsideStripeRequestVocabulary(array $parameters): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SessionRequest($parameters);
    }

    /** @return iterable<string, array{array<mixed, mixed>}> */
    public static function invalidRequests(): iterable
    {
        yield 'empty root' => [[]];
        yield 'list root' => [[['mode' => 'payment']]];
        yield 'empty key' => [['' => 'value']];
        yield 'floating point' => [['amount' => 19.95]];
        yield 'object' => [['client' => new stdClass()]];

        $nested = 'value';

        for ($depth = 0; $depth < 18; $depth++) {
            $nested = ['nested' => $nested];
        }

        yield 'excessive nesting' => [['root' => $nested]];
    }
}
