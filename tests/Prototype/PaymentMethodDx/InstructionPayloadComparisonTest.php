<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Prototype\PaymentMethodDx;

use Brick\Money\Money;
use Kirby\Data\Yaml;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use Stripe\PaymentIntent;
use Stripe\StripeObject;

/** Provider-shape persistence and SDK ergonomics comparison; not a production instruction policy. */
final class InstructionPayloadComparisonTest extends KirbyTestCase
{
    #[DataProvider('instructionPayments')]
    public function testProviderActionSurvivesKirbyPersistenceAndSdkRestoration(
        PaymentIntent $paymentIntent,
        string $actionType,
        string $field,
        string $expectedValue,
    ): void {
        $action = $paymentIntent->next_action;
        $this->assertInstanceOf(StripeObject::class, $action);
        $snapshot = $action->toArray();
        $page = $this->kirby->site()->createChild([
            'slug' => 'instruction-comparison',
            'template' => 'default',
            'content' => [
                'title' => 'Instruction comparison',
                'providerAction' => Yaml::encode($snapshot),
            ],
        ]);
        $storedContent = $page->version()->read();
        $this->assertIsArray($storedContent);
        $this->assertArrayHasKey('provideraction', $storedContent);
        $stored = Yaml::decode($storedContent['provideraction']);
        $this->assertSame($snapshot, $stored);

        // Reconstruct an intentionally partial SDK observation for read access only.
        // The SDK has no standalone NextAction class with this generated PHPDoc;
        // a generic StripeObject preserves data but loses that contextual typing.
        $restored = PaymentIntent::constructFrom(['next_action' => $stored]);
        $restoredAction = $restored->next_action;
        $this->assertInstanceOf(StripeObject::class, $restoredAction);
        $this->assertSame($actionType, $restoredAction->type);
        $details = $restoredAction[$actionType];
        $this->assertInstanceOf(StripeObject::class, $details);
        $this->assertSame($expectedValue, $details[$field]);

        // A captured action remains replayable even when current provider data no
        // longer has next_action. This is not a production lifecycle retry test.
        // Replay means restoring hook inputs, not retrying a payment or asserting
        // that the original voucher/QR instructions remain usable.
        $paymentIntent->next_action = null;
        $replayed = PaymentIntent::constructFrom(['next_action' => $stored]);
        $this->assertSame($snapshot, $replayed->next_action?->toArray());
    }

    /** @return iterable<string, array{PaymentIntent, string, string, string}> */
    public static function instructionPayments(): iterable
    {
        yield 'Multibanco voucher' => [PaymentFixtures::multibanco(), 'multibanco_display_details', 'reference', '123456789'];
        yield 'OXXO voucher' => [PaymentFixtures::oxxo(), 'oxxo_display_details', 'number', '1234567890'];
        yield 'PayNow QR' => [PaymentFixtures::paynow(), 'paynow_display_qr_code', 'image_url_png', 'https://payments.example.test/qr.png'];
        yield 'Konbini stores' => [PaymentFixtures::konbini(), 'konbini_display_details', 'hosted_voucher_url', 'https://payments.example.test/konbini'];
    }

    public function testSdkAccessKeepsNestedCodesThatTheSmallProjectionCannotRepresent(): void
    {
        $paymentIntent = PaymentFixtures::konbini();
        $restored = PaymentIntent::constructFrom([
            'next_action' => $paymentIntent->next_action?->toArray(),
        ]);
        $action = $restored->next_action;
        $this->assertInstanceOf(StripeObject::class, $action);
        $details = $action->konbini_display_details ?? null;
        $this->assertNotNull($details);
        $this->assertSame('123456789', $details->stores->familymart?->payment_code);
        $this->assertSame('11111111110', $details->stores->familymart->confirmation_number ?? null);
        $this->assertSame('987654321', $details->stores->lawson?->payment_code);
        $this->assertNull($details->stores->ministop);

        $projection = (new PaymentNormalizer())->normalize(
            status: PaymentStatus::Pending,
            amount: Money::of('2500', 'JPY'),
            paymentIntent: $paymentIntent,
        );
        $this->assertNull($projection->instructions());
    }

    public function testSerializabilityDoesNotMakeAnActionSafeForInstructionStorage(): void
    {
        $paymentIntent = PaymentIntent::constructFrom([
            'next_action' => [
                'type' => 'redirect_to_url',
                'redirect_to_url' => [
                    'url' => 'https://authentication.example.test/EXCLUDED_AUTHENTICATION_TOKEN',
                    'return_url' => 'https://shop.example.test/EXCLUDED_RETURN_TOKEN',
                ],
            ],
        ]);
        $snapshot = $paymentIntent->next_action?->toArray();

        // Native serialization deliberately preserves everything supplied. The
        // safety boundary must select appropriate data before writing content.
        $this->assertSame($snapshot, Yaml::decode(Yaml::encode($snapshot)));
        $this->assertStringContainsString('EXCLUDED_AUTHENTICATION_TOKEN', Yaml::encode($snapshot));
    }
}
