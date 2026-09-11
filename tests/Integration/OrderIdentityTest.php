<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use DateTimeImmutable;
use Kirby\Uuid\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderNumberFormatter;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSerializer;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;

final class OrderIdentityTest extends TestCase
{
    #[DataProvider('uuidFormats')]
    public function testNativeIdentityFlowsIntoOrderValuesWithoutExtractingPrefixes(string $format): void
    {
        $environment = KirbyTestEnvironment::start(options: ['content.uuid' => $format === 'v4' ? 'uuid-v4' : true]);
        $previousGenerator = Uuid::$generator;

        try {
            if ($format === 'custom') {
                Uuid::$generator = static fn(int $length): string => 'custom-order-identity';
            }

            // A regular disposable Page proves the native boundary without adding
            // plugin order storage or relying on its future Page model.
            $page = $environment->app()->site()->createChild([
                'slug' => 'identity-fixture',
                'template' => 'default',
                'content' => [
                    'title' => 'Identity fixture',
                ],
            ]);
            $nativeUuid = $page->uuid();
            $id = $nativeUuid->id();
            $reference = $nativeUuid->toString();

            if ($format === 'default') {
                $this->assertMatchesRegularExpression('/\A[a-z0-9]{16}\z/', $id);
            } elseif ($format === 'v4') {
                $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $id);
            } else {
                $this->assertSame('custom-order-identity', $id);
            }

            $number = (new OrderNumberFormatter())->format($id);
            $price = Money::of('16', 'EUR');
            $product = new Product(new ProductRequest('product'), 'Product', false, new Price($price));
            $context = new OrderCreationContext($id, $number, CheckoutSource::Direct, null, null, null, UiMode::Hosted, 'EUR', [OrderLineItemSnapshot::fromProduct($product, $price)]);
            $content = OrderSerializer::creation($context, hash('sha256', 'token'), hash('sha256', 'request'), 'guest', new DateTimeImmutable());

            $this->assertSame($id, $content['uuid']);
            $this->assertSame($id, $context->uuid());
            $this->assertSame($reference, $context->pageUuid());
            $this->assertSame('ORD-' . strtoupper($id), $context->orderNumber());
            $customNumber = new OrderNumberFormatter(function (string $pageUuid) use ($reference): string {
                $this->assertSame($reference, $pageUuid);

                return 'CUSTOM-ORDER';
            });
            $this->assertSame('CUSTOM-ORDER', $customNumber->format($id));

            // A core UUID object is mutable. No such handle escapes into the
            // immutable context, even when the source Page supplied its identity.
            $nativeUuid->uri->host('changed');
            $this->assertSame($id, $context->uuid());
            $this->assertSame($reference, $context->pageUuid());
        } finally {
            Uuid::$generator = $previousGenerator;
            $environment->close();
        }
    }

    /** @return iterable<array{string}> */
    public static function uuidFormats(): iterable
    {
        yield ['default'];
        yield ['v4'];
        yield ['custom'];
    }
}
