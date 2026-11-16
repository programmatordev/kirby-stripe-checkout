<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Kirby\Http\Environment;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Http\ResponseNegotiator;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ReflectionProperty;

final class ResponseNegotiatorTest extends KirbyTestCase
{
    /** @param non-empty-list<string> $types */
    #[DataProvider('acceptHeaders')]
    public function testSelectsThePreferredSupportedRepresentation(string $accept, array $types, ?string $expected): void
    {
        $environmentInfo = new ReflectionProperty(Environment::class, 'info');
        $originalInfo = $this->kirby->environment()->info();
        $environmentInfo->setValue($this->kirby->environment(), [...$originalInfo, 'HTTP_ACCEPT' => $accept]);

        try {
            $this->assertSame($expected, ResponseNegotiator::preferred($this->kirby, $types));
        } finally {
            $environmentInfo->setValue($this->kirby->environment(), $originalInfo);
        }
    }

    /** @return iterable<string, array{string, non-empty-list<string>, string|null}> */
    public static function acceptHeaders(): iterable
    {
        $types = ['application/json', 'text/html'];

        yield 'blank uses the first supported type' => ['', $types, 'application/json'];
        yield 'exact match' => ['application/json', $types, 'application/json'];
        yield 'type wildcard' => ['application/*', $types, 'application/json'];
        yield 'global wildcard uses preference order' => ['*/*', $types, 'application/json'];
        yield 'quality selects the better match' => ['application/json;q=0.5,text/html;q=0.9', $types, 'text/html'];
        yield 'specific exclusion beats global wildcard' => ['application/json;q=0,*/*;q=1', $types, 'text/html'];
        yield 'all exact matches excluded' => ['*/*;q=1,application/json;q=0,text/html;q=0', $types, null];
        yield 'all type matches excluded' => ['application/*;q=0,text/*;q=0,*/*;q=1', $types, null];
        yield 'unsupported type' => ['image/png', $types, null];
    }
}
