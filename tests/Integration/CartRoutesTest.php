<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Http\Environment;
use Kirby\Http\Request;
use Kirby\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Cart\Cart;
use ProgrammatorDev\StripeCheckout\Cart\CartOperation;
use ProgrammatorDev\StripeCheckout\Cart\CartRenderContext;
use ProgrammatorDev\StripeCheckout\Cart\Exception\CartException;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartResponseMapper;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ReflectionProperty;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

final class CartRoutesTest extends KirbyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->restart();
    }

    public function testGuestRoutesUseThePhpCartAndExactPrivateProjection(): void
    {
        $product = $this->product();
        $this->kirby->impersonate(null);
        $read = $this->send('GET');
        $this->assertSame(200, $read->code());
        $this->assertSame('no-store, private', $read->headers()['Cache-Control']);
        $this->assertSame('Accept', $read->headers()['Vary']);
        $this->assertSame('0.00', $this->data($read, 'data.cart.subtotal.amount'));
        $this->assertNull($this->data($read, 'data.cart.shippingQuote'));
        $added = $this->send('POST', '/items', ['reference' => $product->id()]);
        $this->assertSame(200, $added->code());
        $itemId = $this->data($added, 'data.cart.items.0.id');
        $this->assertIsString($itemId);
        $this->assertSame(['amount' => '16.00', 'currency' => 'EUR'], $this->data($added, 'data.cart.items.0.price'));
        $this->assertSame([], $this->data($added, 'data.cart.items.0.product.options'));
        $this->assertStringContainsString('"options":{}', $added->body());
        $this->assertNull($this->data($added, 'data.cart.items.0.product.metadata'));
        $this->assertNull($this->data($added, 'data.cart.id'));

        $cart = $this->cart()->add($product->uuid()->toString(), 2);
        $read = $this->send('GET');
        $this->assertSame(json_decode(json_encode(CartResponseMapper::cart($cart), JSON_THROW_ON_ERROR), true), $this->data($read, 'data.cart'));
        $updated = $this->send('PATCH', '/items/' . $itemId, ['quantity' => 4, 'revision' => $cart->revision()]);
        $this->assertSame(200, $updated->code());
        $this->assertSame(4, $this->cart()->totalQuantity());
        $this->assertSame('64.00', $this->data($updated, 'data.cart.subtotal.amount'));
        $removed = $this->send('DELETE', '/items/' . $itemId, ['revision' => $this->cart()->revision()]);
        $this->assertTrue($this->data($removed, 'data.cart.empty'));
        $this->cart()->add($product->id());
        $cleared = $this->send('DELETE', '', ['revision' => $this->cart()->revision()]);
        $this->assertSame(200, $cleared->code());
        $this->assertTrue($this->cart()->isEmpty());
    }

    public function testJsonAndHtmlResponsesExposeTheResolvedShippingQuote(): void
    {
        $this->restart([
            'settings' => [
                'defaultRequiresShipping' => true,
                'shippingZones' => [[
                    'name' => 'Portugal',
                    'scope' => 'selected_countries',
                    'countries' => ['PT'],
                    'options' => [[
                        'key' => 'standard',
                        'label' => 'Standard delivery',
                        'amount' => '4.90',
                    ]],
                ]],
            ],
            'cart' => ['renderer' => static function (?Cart $cart): string {
                $quote = $cart?->shippingQuote();

                return '<div>' . $quote?->status()->value . ':' . $quote?->options()[0]->key() . '</div>';
            }],
        ]);
        $cart = $this->cart()->add($this->product()->id());
        $required = $this->send('GET');

        $this->assertSame('country_required', $this->data($required, 'data.cart.shippingQuote.status'));
        $this->assertSame('shipping.country_required', $this->data($required, 'data.cart.shippingQuote.reasonCode'));
        $this->assertSame([], $this->data($required, 'data.cart.shippingQuote.options'));
        $this->assertFalse($this->data($required, 'data.cart.hasErrors'));

        $cart->updateShippingCountry('PT');
        $json = $this->send('GET');

        $this->assertSame('available', $this->data($json, 'data.cart.shippingQuote.status'));
        $this->assertSame('standard', $this->data($json, 'data.cart.shippingQuote.options.0.key'));
        $this->assertSame(
            ['amount' => '4.90', 'currency' => 'EUR'],
            $this->data($json, 'data.cart.shippingQuote.options.0.amount'),
        );
        $this->assertNull($this->data($json, 'data.cart.shippingQuote.reasonCode'));

        $html = $this->send('GET', headers: ['Accept' => 'text/html']);
        $this->assertSame(200, $html->code());
        $this->assertSame('<div>available:standard</div>', $html->body());

        $cart->updateShippingCountry('ES');
        $unavailable = $this->send('GET');
        $this->assertSame('unavailable', $this->data($unavailable, 'data.cart.shippingQuote.status'));
        $this->assertSame('shipping.unavailable', $this->data($unavailable, 'data.cart.shippingQuote.reasonCode'));
        $this->assertSame([], $this->data($unavailable, 'data.cart.shippingQuote.options'));
        $this->assertTrue($this->data($unavailable, 'data.cart.hasErrors'));
        $this->assertSame('shipping.unavailable', $this->data($unavailable, 'data.cart.errors.0.code'));
        $this->assertSame('16.00', $this->data($unavailable, 'data.cart.subtotal.amount'));
    }

    public function testShippingCountryPatchUpdatesClearsAndReturnsTheCurrentQuote(): void
    {
        $operations = [];
        $this->restart([
            'settings' => [
                'defaultRequiresShipping' => true,
                'shippingZones' => [[
                    'name' => 'Portugal',
                    'scope' => 'selected_countries',
                    'countries' => ['PT'],
                    'options' => [[
                        'key' => 'standard',
                        'label' => 'Standard delivery',
                        'amount' => '4.90',
                    ]],
                ]],
            ],
            'cart' => ['renderer' => static function (?Cart $cart, CartRenderContext $context) use (&$operations): string {
                $operations[] = $context->operation();

                return '<div>' . $cart?->shippingCountry() . ':' . $cart?->shippingQuote()?->status()->value . '</div>';
            }],
        ]);
        $cart = $this->cart()->add($this->product()->id());
        $originalRevision = $cart->revision();
        $updated = $this->send('PATCH', body: [
            'revision' => $originalRevision,
            'shippingCountry' => 'PT',
        ]);

        $this->assertSame(200, $updated->code());
        $this->assertSame('PT', $this->data($updated, 'data.cart.shippingCountry'));
        $this->assertSame('Portugal', $this->data($updated, 'data.cart.shippingCountryOptions.PT'));
        $this->assertSame('available', $this->data($updated, 'data.cart.shippingQuote.status'));
        $this->assertSame('standard', $this->data($updated, 'data.cart.shippingQuote.options.0.key'));
        $updatedRevision = $this->data($updated, 'data.cart.revision');
        $this->assertIsString($updatedRevision);
        $this->assertNotSame($originalRevision, $updatedRevision);

        $unchanged = $this->send('PATCH', body: [
            'revision' => $updatedRevision,
            'shippingCountry' => 'PT',
        ]);
        $this->assertSame($updatedRevision, $this->data($unchanged, 'data.cart.revision'));

        $jsonCleared = $this->send('PATCH', body: [
            'revision' => $updatedRevision,
            'shippingCountry' => null,
        ]);
        $this->assertSame(200, $jsonCleared->code());
        $this->assertNull($this->data($jsonCleared, 'data.cart.shippingCountry'));
        $this->assertSame('country_required', $this->data($jsonCleared, 'data.cart.shippingQuote.status'));

        $formUpdated = $this->send('PATCH', body: http_build_query([
            'revision' => $this->data($jsonCleared, 'data.cart.revision'),
            'shippingCountry' => 'PT',
        ]), headers: ['Content-Type' => 'application/x-www-form-urlencoded']);
        $this->assertSame(200, $formUpdated->code());
        $this->assertSame('PT', $this->data($formUpdated, 'data.cart.shippingCountry'));

        $form = http_build_query([
            'revision' => $this->data($formUpdated, 'data.cart.revision'),
            'shippingCountry' => '',
        ]);
        $cleared = $this->send('PATCH', body: $form, headers: ['Content-Type' => 'application/x-www-form-urlencoded']);
        $this->assertSame(200, $cleared->code());
        $this->assertNull($this->data($cleared, 'data.cart.shippingCountry'));
        $this->assertSame('country_required', $this->data($cleared, 'data.cart.shippingQuote.status'));

        $html = $this->send('PATCH', body: [
            'revision' => $this->data($cleared, 'data.cart.revision'),
            'shippingCountry' => 'PT',
        ], headers: ['Accept' => 'text/html']);
        $this->assertSame(200, $html->code());
        $this->assertSame('<div>PT:available</div>', $html->body());
        $this->assertSame([CartOperation::UpdateShippingCountry], $operations);
    }

    public function testShippingCountryPatchRejectsUntrustedInputWithoutChangingTheCart(): void
    {
        $cart = $this->cart()->add($this->product()->id());
        $revision = $cart->revision();

        foreach ([
            [],
            ['shippingCountry' => []],
            ['shippingCountry' => 1],
            ['shippingCountry' => false],
            ['shippingCountry' => 'pt'],
            ['shippingCountry' => 'CU'],
        ] as $body) {
            $response = $this->send('PATCH', body: [...$body, 'revision' => $revision]);
            $this->assertSame(422, $response->code());
            $this->assertSame('shipping.country_invalid', $this->data($response, 'error.code'));
            $this->assertSame($revision, $this->cart()->revision());
            $this->assertNull($this->cart()->shippingCountry());
        }

        $this->assertSame(422, $this->send('PATCH', body: ['shippingCountry' => 'PT'])->code());
        $this->assertSame(422, $this->send('PATCH', body: [
            'revision' => $revision,
            'shippingCountry' => 'PT',
            'shippingOption' => 'standard',
        ])->code());
        $this->assertSame(403, $this->send('PATCH', body: [
            'revision' => $revision,
            'shippingCountry' => 'PT',
        ], csrf: false)->code());

        $this->cart()->updateShippingCountry('PT');
        $stale = $this->send('PATCH', body: [
            'revision' => $revision,
            'shippingCountry' => null,
        ]);
        $this->assertSame(409, $stale->code());
        $this->assertSame('PT', $this->cart()->shippingCountry());
    }

    public function testStaleWritesReturnCurrentCartAndDoNotMutate(): void
    {
        $cart = $this->cart()->add($this->product()->id());
        $itemId = $cart->items()[0]->id();
        $stale = $cart->revision();
        $cart->update($itemId, 3);

        foreach ([['PATCH', '/items/' . $itemId, ['quantity' => 5]], ['DELETE', '/items/' . $itemId, []], ['DELETE', '', []]] as [$method, $path, $body]) {
            $response = $this->send($method, $path, [...$body, 'revision' => $stale]);
            $this->assertSame(409, $response->code());
            $this->assertSame('cart.revision_conflict', $this->data($response, 'error.code'));
            $this->assertSame('revision', $this->data($response, 'error.field'));
            $this->assertSame($cart->revision(), $this->data($response, 'data.cart.revision'));
        }

        $this->assertSame(3, $this->cart()->totalQuantity());
        $response = $this->send('DELETE', '/items/foreign-id', ['revision' => $cart->revision()]);
        $this->assertSame(404, $response->code());
    }

    public function testWritesResolveOnlyTheMutationAndRemainingProducts(): void
    {
        $state = new class {
            /** @var list<string> */
            public array $calls = [];
        };
        $this->restart(['products' => ['resolver' => static function (ProductRequest $request) use ($state): Product {
            $state->calls[] = $request->reference() . ':' . $request->quantity();

            return new Product($request, 'Product', false, new Price(Money::of('10', 'EUR')));
        }]]);
        $response = $this->send('POST', '/items', ['reference' => 'a']);
        $this->assertSame(200, $response->code());
        $this->assertSame(['a:1', 'a:1'], $state->calls);

        $state->calls = [];
        $response = $this->send('POST', '/items', ['reference' => 'a']);
        // Incoming and merged quantities still pass the resolver before the
        // resulting line is resolved for presentation; no old view is built.
        $this->assertSame(['a:1', 'a:2', 'a:2'], $state->calls);
        $this->assertSame(2, $this->data($response, 'data.cart.totalQuantity'));
        $itemId = $this->data($response, 'data.cart.items.0.id');
        $this->assertIsString($itemId);

        $state->calls = [];
        $response = $this->send('POST', '/items', ['reference' => 'b']);
        $this->assertSame(['b:1', 'a:2', 'b:1'], $state->calls);
        $revision = $this->data($response, 'data.cart.revision');
        $state->calls = [];
        $response = $this->send('DELETE', '/items/' . $itemId, ['revision' => $revision]);
        $this->assertSame(200, $response->code());
        $this->assertSame(['b:1'], $state->calls);

        $revision = $this->data($response, 'data.cart.revision');
        $state->calls = [];
        $response = $this->send('DELETE', body: ['revision' => $revision]);
        $this->assertSame(200, $response->code());
        $this->assertTrue($this->data($response, 'data.cart.empty'));
        $this->assertSame([], $state->calls);
    }

    #[DataProvider('invalidAddRequests')]
    public function testPhpAndHttpExposeEquivalentValidationFailures(
        string $reference,
        int $quantity,
        string $phpCode,
        string $httpCode,
    ): void {
        $this->product();
        $cart = $this->cart();
        $revision = $cart->revision();

        try {
            $cart->add($reference, $quantity);
            $this->fail('Expected invalid PHP input to be rejected.');
        } catch (CartException $error) {
            $this->assertSame($phpCode, $error->errorCode());
        }

        $response = $this->send('POST', '/items', [
            'reference' => $reference,
            'quantity' => $quantity,
        ]);

        $this->assertSame(422, $response->code());
        $this->assertSame($httpCode, $this->data($response, 'error.code'));
        $this->assertSame($revision, $this->cart()->revision());
        $this->assertTrue($this->cart()->isEmpty());
    }

    /** @return iterable<string, array{string, int, string, string}> */
    public static function invalidAddRequests(): iterable
    {
        // Parser edge cases live in ProductRequestDataTest; this boundary owns
        // error translation and the guarantee that rejected writes change nothing.
        yield 'quantity error' => ['shirt', 0, 'cart.quantity_invalid', 'selection.quantity_invalid'];
        yield 'reference error' => ['', 1, 'cart.selection_invalid', 'selection.invalid'];
    }

    public function testRejectedMutationsKeepTheCartInHtmlAndJsonResponses(): void
    {
        $this->restart(['cart' => ['renderer' => static function (?Cart $cart, CartRenderContext $context): string {
            return '<div>' . $cart?->count() . ':' . $cart?->items()[0]->product()?->name()
                . ':' . $cart?->revision() . ':' . $context->error()?->code() . '</div>';
        }]]);
        $cart = $this->cart()->add($this->product()->id());

        foreach (['text/html', 'application/json'] as $type) {
            $response = $this->send('POST', '/items', ['reference' => 'missing'], ['Accept' => $type]);
            $this->assertSame(422, $response->code());

            if ($type === 'text/html') {
                $this->assertSame('<div>1:Shirt:' . $cart->revision() . ':product.unavailable</div>', $response->body());
            } else {
                $this->assertSame(1, $this->data($response, 'data.cart.count'));
                $this->assertSame($cart->revision(), $this->data($response, 'data.cart.revision'));
            }
        }
    }

    public function testCsrfCannotComeFromQueryOrAnAmbiguousTransport(): void
    {
        $reference = $this->product()->id();
        $body = ['reference' => $reference];
        $this->assertSame(403, $this->send('POST', '/items', $body, csrf: false)->code());
        $this->assertSame(403, $this->send('POST', '/items', $body, headers: ['X-CSRF' => 'wrong'])->code());
        $token = $this->kirby->csrf();
        $this->assertIsString($token);
        $form = http_build_query([...$body, 'csrf' => $token]);
        $this->assertSame(403, $this->send('POST', '/items', $form, headers: ['Content-Type' => 'application/x-www-form-urlencoded', 'X-CSRF' => 'wrong'])->code());
        $this->assertSame(403, $this->send('POST', '/items', $body, csrf: false, query: ['csrf' => $token])->code());
        $this->assertTrue($this->cart()->isEmpty());
        $response = $this->send('POST', '/items', $form, headers: ['Content-Type' => 'application/x-www-form-urlencoded'], csrf: false);
        $this->assertSame(200, $response->code());
        $response = $this->send('POST', '/items', $form, headers: ['Content-Type' => 'application/x-www-form-urlencoded']);
        $this->assertSame(200, $response->code());
        $this->assertSame(2, $this->cart()->totalQuantity());
    }

    #[DataProvider('malformedJsonBodies')]
    public function testRejectsMalformedJsonWithoutChangingTheCart(string $body): void
    {
        $cart = $this->cart()->add($this->product()->id());
        $revision = $cart->revision();

        $this->assertSame(400, $this->send('POST', '/items', $body)->code());
        $this->assertSame($revision, $this->cart()->revision());
        $this->assertSame(1, $this->cart()->totalQuantity());
    }

    /** @return iterable<string, array{string}> */
    public static function malformedJsonBodies(): iterable
    {
        yield 'empty body' => [''];
        yield 'incomplete object' => ['{'];
        yield 'array instead of object' => ['[]'];
        yield 'null instead of object' => ['null'];
        yield 'string instead of object' => ['"test"'];
        yield 'boolean instead of object' => ['true'];
        yield 'form data declared as JSON' => ['reference=shirt'];
    }

    #[DataProvider('invalidBodyEncodings')]
    public function testRejectsUnsupportedOrMismatchedBodyEncodings(
        string $body,
        string $contentType,
        int $httpStatus,
    ): void {
        $this->product();

        $response = $this->send('POST', '/items', $body, ['Content-Type' => $contentType]);

        $this->assertSame($httpStatus, $response->code());
        $this->assertTrue($this->cart()->isEmpty());
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function invalidBodyEncodings(): iterable
    {
        yield 'unsupported content type' => ['{}', 'text/plain', 415];
        yield 'JSON declared as form data' => ['{"reference":"shirt"}', 'application/x-www-form-urlencoded', 422];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidItemBodies')]
    public function testRejectsInvalidItemBodiesWithoutChangingTheCart(array $body): void
    {
        $cart = $this->cart()->add($this->product()->id());
        $revision = $cart->revision();

        $this->assertSame(422, $this->send('POST', '/items', $body)->code());
        $this->assertSame($revision, $this->cart()->revision());
        $this->assertSame(1, $this->cart()->totalQuantity());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidItemBodies(): iterable
    {
        $invalidBodies = [
            'nested instead of flat request' => ['request' => ['reference' => 'shirt']],
            'null options instead of object' => [
                'reference' => 'shirt',
                'options' => null,
            ],
            'array options instead of object' => [
                'reference' => 'shirt',
                'options' => [],
            ],
            'forged price' => [
                'reference' => 'shirt',
                'price' => '0.01',
            ],
            'forged actor' => [
                'reference' => 'shirt',
                'userUuid' => 'foreign',
            ],
            'list options' => [
                'reference' => 'shirt',
                'options' => ['bad'],
            ],
            'JSON quantity is not coerced from text' => [
                'reference' => 'shirt',
                'quantity' => '2',
            ],
            'null quantity is not treated as omitted' => [
                'reference' => 'shirt',
                'quantity' => null,
            ],
        ];

        foreach ($invalidBodies as $case => $body) {
            yield $case => [$body];
        }
    }

    public function testClearRequiresARevisionAndLeavesExistingItemsUntouched(): void
    {
        $cart = $this->cart()->add($this->product()->id());
        $revision = $cart->revision();

        $this->assertSame(422, $this->send('DELETE', '', [])->code());
        $this->assertSame($revision, $this->cart()->revision());
        $this->assertSame(1, $this->cart()->totalQuantity());
    }

    public function testFormInputCannotNestTheProductRequest(): void
    {
        $body = http_build_query(['request' => ['reference' => $this->product()->id()]]);
        $response = $this->send('POST', '/items', $body, ['Content-Type' => 'application/x-www-form-urlencoded']);

        $this->assertSame(422, $response->code());
        $this->assertTrue($this->cart()->isEmpty());
    }

    public function testMissingAndDraftProductsAreUnavailable(): void
    {
        $draft = $this->kirby->site()->createChild([
            'slug' => 'draft',
            'template' => 'default',
            'content' => [
                'title' => 'Hidden',
                'price' => '10',
            ],
        ]);
        $references = [
            'missing page' => 'unknown',
            'draft page' => $draft->id(),
        ];

        foreach ($references as $case => $reference) {
            $response = $this->send('POST', '/items', ['reference' => $reference]);
            $this->assertSame(422, $response->code(), $case);
            $this->assertSame('product.unavailable', $this->data($response, 'error.code'), $case);
        }

        $this->assertTrue($this->cart()->isEmpty());
    }

    public function testFormQuantityNormalizationRejectsInvalidNumericStrings(): void
    {
        $reference = $this->product()->id();
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8'];
        $response = $this->send('POST', '/items', http_build_query(['reference' => $reference, 'quantity' => '3']), $headers);
        $this->assertSame(200, $response->code());
        $itemId = $this->cart()->items()[0]->id();
        $quantities = [
            'zero' => '0',
            'negative' => '-1',
            'fraction' => '1.2',
            'scientific notation' => '2e1',
            'integer overflow' => str_repeat('9', 30),
        ];

        foreach ($quantities as $case => $quantity) {
            $body = http_build_query(['revision' => $this->cart()->revision(), 'quantity' => $quantity]);
            $this->assertSame(422, $this->send('PATCH', '/items/' . $itemId, $body, $headers)->code(), $case);
        }

        $this->assertSame(3, $this->cart()->totalQuantity());
    }

    public function testQuantityUpdateRequiresARevision(): void
    {
        $cart = $this->cart()->add($this->product()->id(), 3);
        $revision = $cart->revision();
        $itemId = $cart->items()[0]->id();

        $response = $this->send('PATCH', '/items/' . $itemId, ['quantity' => 4]);

        $this->assertSame(422, $response->code());
        $this->assertSame($revision, $this->cart()->revision());
        $this->assertSame(3, $this->cart()->totalQuantity());
    }

    public function testOptionsUseTheCartVocabularyInJsonAndForms(): void
    {
        $this->restart(['products' => ['resolver' => static fn(ProductRequest $request): Product => new Product(
            $request,
            'Shirt',
            false,
            new Price(Money::of('16', 'EUR')),
            selectedOptions: [new SelectedOption('size', 'Size', 'large', 'Large')],
            variantId: 'large-variant',
        )]]);
        $selection = ['reference' => 'external', 'options' => ['size' => 'large']];
        $response = $this->send('POST', '/items', $selection);
        $this->assertSame(200, $response->code());
        $this->assertSame(['size' => 'large'], $this->data($response, 'data.cart.items.0.request.options'));
        $this->assertSame('Large', $this->data($response, 'data.cart.items.0.product.options.0.valueName'));
        $this->assertStringNotContainsString('selectedOptions', $response->body());
        $response = $this->send('POST', '/items', http_build_query($selection), ['Content-Type' => 'application/x-www-form-urlencoded']);
        $this->assertSame(200, $response->code());
        $this->assertSame(2, $this->data($response, 'data.cart.totalQuantity'));
        $response = $this->send('POST', '/items', ['reference' => 'external', 'selectedOptions' => ['size' => 'large']]);
        $this->assertSame(422, $response->code());
        $this->assertSame(2, $this->cart()->totalQuantity());
    }

    public function testHtmlNegotiationAndContextUseTheSameResult(): void
    {
        $calls = [];
        $this->restart(['cart' => ['renderer' => static function (?Cart $cart, CartRenderContext $context) use (&$calls): string {
            $calls[] = $context;
            return '<div data-revision="' . $cart?->revision() . '">' . ($cart?->totalQuantity() ?? 'unavailable') . '</div>';
        }]]);
        $product = $this->product();

        foreach ([null, '*/*', 'application/json', 'application/json;q=1,text/html;q=0.5'] as $accept) {
            $this->assertSame('application/json', $this->send('GET', headers: $accept === null ? [] : ['Accept' => $accept])->type());
        }

        $this->assertCount(0, $calls);
        $response = $this->send('POST', '/items', ['reference' => $product->id()], ['Accept' => 'text/html']);
        $this->assertSame(200, $response->code());
        $this->assertStringContainsString('>1</div>', $response->body());
        /** @var list<CartRenderContext> $calls */
        $this->assertSame(CartOperation::AddItem, $calls[0]->operation());
        $this->assertSame(200, $calls[0]->httpStatus());
        $this->assertNull($calls[0]->error());
        $response = $this->send('DELETE', '', ['revision' => 'stale'], ['Accept' => 'text/html']);
        $this->assertSame(409, $response->code());
        $this->assertSame('cart.revision_conflict', $calls[1]->error()?->code());
        $this->assertStringContainsString('>1</div>', $response->body());
        $this->assertSame('text/html', $this->send('GET', headers: ['Accept' => 'application/json;q=0,*/*;q=1'])->type());
        $this->assertSame(406, $this->send('GET', headers: ['Accept' => 'application/json;q=0,text/html;q=0'])->code());
    }

    public function testMissingRendererRejectsBeforeMutation(): void
    {
        $product = $this->product();
        $this->assertSame(406, $this->send('POST', '/items', ['reference' => $product->id()], ['Accept' => 'text/html'])->code());
        $this->assertSame(406, $this->send('GET', headers: ['Accept' => 'image/png'])->code());
        $this->assertTrue($this->cart()->isEmpty());
    }

    public function testRendererFailureAfterAWriteDoesNotInviteRetry(): void
    {
        $this->restart(['cart' => ['renderer' => static function (): never {
            throw new RuntimeException('SECRET');
        }]]);
        $product = $this->product();
        $response = $this->send('POST', '/items', ['reference' => $product->id()], ['Accept' => 'text/html']);
        $this->assertSame(204, $response->code());
        $this->assertSame('', $response->body());
        $this->assertSame(1, $this->cart()->totalQuantity());
        $this->assertSame(500, $this->send('GET', headers: ['Accept' => 'text/html'])->code());
        $this->assertSame(409, $this->send('DELETE', '', ['revision' => 'stale'], ['Accept' => 'text/html'])->code());
        $this->assertSame(200, $this->send('GET')->code());
    }

    public function testUnsupportedMethodsReportAllowedMethods(): void
    {
        $requests = [['POST', ''], ['PUT', ''], ['GET', '/items'], ['POST', '/items/id'], ['OPTIONS', '']];

        foreach ($requests as [$method, $path]) {
            $response = $this->send($method, $path);
            $this->assertSame(405, $response->code(), $method . ' ' . $path);
            $this->assertArrayHasKey('Allow', $response->headers(), $method . ' ' . $path);
        }
    }

    public function testDisabledCartDoesNotRegisterRoutesOrStartASession(): void
    {
        $this->restart(['cart' => ['enabled' => false]]);
        $this->assertSame([], array_values(array_filter($this->kirby->extensions('routes'), static fn(mixed $route): bool => is_array($route) && is_string($route['pattern'] ?? null) && str_starts_with($route['pattern'], 'stripe-checkout/cart'))));
        $this->assertNull($this->kirby->session()->token());
    }

    public function testRendererConfigurationIsPhpOnlyAndStrict(): void
    {
        $resolver = new ConfigurationResolver();
        $renderer = static fn(): string => '';
        $this->assertSame($renderer, $resolver->cartRenderer(['programmatordev.stripe-checkout.cart.renderer' => $renderer]));
        $this->assertFalse($resolver->resolve(['programmatordev.stripe-checkout' => ['cart' => ['renderer' => 'snippet']]])->isValid());
        $this->restart(['cart' => ['renderer' => 'not-callable']]);
        $this->assertSame(422, $this->send('GET')->code());
    }

    public function testMultilingualRoutesResolveCurrentLanguageWithoutChangingRevision(): void
    {
        $this->restart(['products' => ['resolver' => static fn(ProductRequest $request, ProductResolutionContext $context): Product => new Product(
            $request,
            $context->languageCode() === 'pt' ? 'Camisola' : 'Shirt',
            false,
            new Price(Money::of('16', 'EUR')),
        )]], languages: [
            ['code' => 'en', 'default' => true, 'locale' => 'en_US', 'name' => 'English', 'url' => '/'],
            ['code' => 'pt', 'locale' => 'pt_PT', 'name' => 'Português', 'url' => '/pt'],
        ]);
        $added = $this->send('POST', '/items', ['reference' => 'external']);
        $this->assertSame(200, $added->code());
        $this->assertSame('Shirt', $this->data($added, 'data.cart.items.0.product.name'));
        $read = $this->send('GET', languagePrefix: 'pt/');
        $this->assertSame('Camisola', $this->data($read, 'data.cart.items.0.product.name'));
        $this->assertSame($this->data($added, 'data.cart.revision'), $this->data($read, 'data.cart.revision'));
        $error = $this->send('DELETE', body: ['revision' => 'stale'], languagePrefix: 'pt/');
        $this->assertSame(409, $error->code());
        $this->assertSame('O carrinho foi alterado. Reveja-o antes de tentar novamente.', $this->data($error, 'error.message'));
    }

    public function testHttpCartSurvivesLoginLogoutButIsNotSharedWithAnotherSession(): void
    {
        $product = $this->product();
        $user = $this->kirby->users()->create(['email' => 'buyer@example.test', 'role' => 'admin', 'password' => 'test-password-123']);
        $this->kirby->impersonate(null);
        $added = $this->send('POST', '/items', ['reference' => $product->id()]);
        $revision = $this->data($added, 'data.cart.revision');
        $user->loginPasswordless();
        $this->assertSame($revision, $this->data($this->send('GET'), 'data.cart.revision'));
        $user->logout();
        $this->assertSame($revision, $this->data($this->send('GET'), 'data.cart.revision'));
        $originalApp = $this->kirby;
        $other = KirbyTestEnvironment::start();

        try {
            $this->kirby = $other->app();
            $this->assertTrue($this->data($this->send('GET'), 'data.cart.empty'));
        } finally {
            $other->close();
            $this->kirby = $originalApp;
            App::instance($originalApp);
        }
    }

    public function testLineLimitAndUnavailableProductsStillAllowRemoval(): void
    {
        $state = new class {
            public bool $available = true;
        };
        $this->restart(['products' => ['resolver' => static function (ProductRequest $request) use ($state): Product {
            if ($state->available === false) {
                throw new InvalidProductException('PRIVATE CUSTOMER DATA');
            }

            return new Product($request, $request->reference(), false, new Price(Money::of('1', 'EUR')));
        }]]);
        $cart = $this->cart();

        for ($i = 0; $i < 100; $i++) {
            $cart->add('product-' . $i);
        }

        $response = $this->send('POST', '/items', ['reference' => 'product-101']);
        $this->assertSame(422, $response->code());
        $this->assertSame('selection.line_limit_exceeded', $this->data($response, 'error.code'));
        $state->available = false;
        $response = $this->send('GET');
        $this->assertSame(200, $response->code());
        $this->assertTrue($this->data($response, 'data.cart.hasErrors'));
        $this->assertNull($this->data($response, 'data.cart.subtotal'));
        $this->assertNull($this->data($response, 'data.cart.items.0.product'));
        $this->assertStringNotContainsString('PRIVATE', $response->body());
        $this->assertSame(200, $this->send('DELETE', body: ['revision' => $cart->revision()])->code());
    }

    public function testStripeSourceProjectionAndProviderFailureAreSanitized(): void
    {
        $this->restart([
            'settings' => ['priceSource' => 'stripe'],
            'stripe' => ['secretKey' => 'sk_test_fixture'],
            'products' => ['resolver' => static fn(ProductRequest $request): Product => new Product(
                $request,
                'Shirt',
                false,
                new StripePriceReference('price_fixture'),
            )],
        ]);
        $state = new class {
            public bool $fail = false;
            public int $requests = 0;
        };
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturnCallback(static function () use ($state): array {
            $state->requests++;

            if ($state->fail) {
                throw new RuntimeException('PRIVATE STRIPE KEY');
            }

            return [json_encode([
                'id' => 'price_fixture', 'object' => 'price', 'active' => true, 'type' => 'one_time',
                'billing_scheme' => 'per_unit', 'currency' => 'eur', 'unit_amount' => 1600,
                'unit_amount_decimal' => '1600', 'custom_unit_amount' => null, 'recurring' => null,
                'nickname' => null, 'tax_behavior' => 'unspecified', 'tiers_mode' => null,
                'transform_quantity' => null, 'product' => [
                    'id' => 'prod_fixture', 'object' => 'product', 'active' => true, 'name' => 'Stripe Shirt',
                    'description' => null, 'images' => [], 'tax_code' => null,
                ],
            ], JSON_THROW_ON_ERROR), 200, []];
        });
        ApiRequestor::setHttpClient($client);
        $response = $this->send('POST', '/items', ['reference' => 'external', 'quantity' => 2]);
        $this->assertSame(200, $response->code());
        $this->assertSame('32.00', $this->data($response, 'data.cart.subtotal.amount'));
        $this->assertStringNotContainsString('price_fixture', $response->body());
        $revision = $this->data($response, 'data.cart.revision');
        $state->fail = true;
        $response = $this->send('POST', '/items', ['reference' => 'external']);
        $this->assertSame(503, $response->code());
        $this->assertSame('product.resolution_unavailable', $this->data($response, 'error.code'));
        $this->assertStringNotContainsString('PRIVATE', $response->body());
        $this->assertSame(1, $this->data($response, 'data.cart.count'));
        $this->assertNull($this->data($response, 'data.cart.subtotal'));
        $requests = $state->requests;
        $this->assertSame(200, $this->send('DELETE', body: ['revision' => $revision])->code());
        $this->assertSame($requests, $state->requests, 'Clearing must not contact Stripe, even during an outage.');
    }

    public function testDevelopmentSnippetCanRenderTheTypedContext(): void
    {
        $snippet = dirname(__DIR__, 2) . '/site/snippets/cart.php';
        $this->restart([
            'settings' => [
                'defaultRequiresShipping' => true,
                'shippingZones' => [[
                    'name' => 'Portugal',
                    'scope' => 'selected_countries',
                    'countries' => ['PT'],
                    'options' => [[
                        'key' => 'standard',
                        'label' => 'Standard delivery',
                        'amount' => '4.90',
                    ]],
                ]],
            ],
            'cart' => ['renderer' => function (?Cart $cart, CartRenderContext $context): string {
                $html = $this->kirby->snippet('cart', ['cart' => $cart, 'context' => $context, 'site' => $this->kirby->site()], true);
                $this->assertIsString($html);

                return $html;
            }],
        ]);
        $root = $this->kirby->root('snippets');
        $this->assertIsString($root);
        Dir::make($root);
        F::copy($snippet, $root . '/cart.php');
        $response = $this->send('GET', headers: ['Accept' => 'text/html']);
        $this->assertSame(200, $response->code());
        $this->assertStringContainsString('Your cart is empty.', $response->body());

        $cart = $this->cart()->add($this->product()->id());
        $response = $this->send('GET', headers: ['Accept' => 'text/html']);
        $this->assertStringContainsString('name="shippingCountry"', $response->body());
        $this->assertStringContainsString('Choose a shipping country to preview', $response->body());
        $response = $this->send('PATCH', body: [
            'revision' => $cart->revision(),
            'shippingCountry' => 'PT',
        ], headers: ['Accept' => 'text/html']);
        $this->assertStringContainsString('Standard delivery', $response->body());
        $this->assertStringContainsString('€4.90', $response->body());
    }

    private function product(): Page
    {
        return $this->kirby->site()->createChild(['slug' => 'shirt', 'template' => 'default', 'content' => ['title' => 'Shirt', 'price' => '16.00']])->changeStatus('unlisted');
    }

    private function cart(): Cart
    {
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        return $cart;
    }

    /**
     * @param array<string, mixed> $options
     * @param list<array<string, mixed>>|null $languages
     */
    private function restart(array $options = [], ?array $languages = null): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: ['programmatordev.stripe-checkout' => array_replace_recursive([
            'settings' => ['currency' => 'EUR', 'defaultRequiresShipping' => false],
        ], $options)], languages: $languages);
        $this->kirby = $this->environment->app();
    }

    /**
     * @param array<string, mixed>|string $body
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    private function send(string $method, string $path = '', array|string $body = [], array $headers = [], bool $csrf = true, array $query = [], string $languagePrefix = ''): Response
    {
        $server = $_SERVER;
        $environmentInfo = new ReflectionProperty(Environment::class, 'info');
        $originalInfo = $environmentInfo->getValue($this->kirby->environment());

        try {
            foreach (['HTTP_ACCEPT', 'HTTP_CONTENT_TYPE', 'HTTP_X_CSRF', 'CONTENT_TYPE'] as $key) {
                unset($_SERVER[$key]);
            }

            if ($csrf) {
                $token = $this->kirby->csrf();
                $this->assertIsString($token);
                $_SERVER['HTTP_X_CSRF'] = $token;
            }

            $_SERVER['CONTENT_TYPE'] = 'application/json';

            foreach ($headers as $key => $value) {
                $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
            }

            // Kirby caches environment headers; refresh that snapshot as well as
            // the Request when simulating several HTTP requests in one PHP process.
            $environmentInfo->setValue($this->kirby->environment(), $_SERVER);
            // Dispatch through Kirby's real router, retaining one browser session.
            (new ReflectionProperty(App::class, 'request'))->setValue($this->kirby, new Request([
                'method' => $method, 'body' => is_array($body) ? json_encode((object) $body, JSON_THROW_ON_ERROR) : $body, 'query' => $query,
            ]));
            $response = $this->kirby->call($languagePrefix . 'stripe-checkout/cart' . $path, $method);
            $this->assertInstanceOf(Response::class, $response);
            return $response;
        } finally {
            $_SERVER = $server;
            $environmentInfo->setValue($this->kirby->environment(), $originalInfo);
        }
    }

    private function data(Response $response, string $path): mixed
    {
        $value = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        foreach (explode('.', $path) as $key) {
            $this->assertIsArray($value);
            $value = $value[$key] ?? null;
        }

        return $value;
    }
}
