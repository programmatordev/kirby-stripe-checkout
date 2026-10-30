<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutBootstrap;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\AttemptToken;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSubmissionInput;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;

final class SubmissionFieldsSnippetTest extends KirbyTestCase
{
    public function testRendersCompleteNestedSubmissionDataWithoutOwningTheForm(): void
    {
        $attemptToken = AttemptToken::generate();
        $checkoutBootstrap = new CheckoutBootstrap(
            uiMode: UiMode::Hosted,
            checkoutSource: CheckoutSource::Direct,
            actionUrl: 'https://kirby-stripe-checkout.test/stripe-checkout/checkout',
            csrf: 'csrf-token',
            attemptToken: $attemptToken,
            cartRevision: null,
            publishableKey: null,
            submissionInput: new CheckoutSubmissionInput(
                checkoutSource: CheckoutSource::Direct,
                attemptToken: $attemptToken,
                cartRevision: null,
                items: [new ProductRequest('shirt', 2, ['size' => 'large'])],
                shippingCountry: 'PT',
                json: false,
            ),
        );
        $html = $this->kirby->snippet(
            'stripe-checkout/submission-fields',
            ['checkoutBootstrap' => $checkoutBootstrap],
            true,
        );

        $this->assertIsString($html);
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('name="csrf" value="csrf-token"', $html);
        $this->assertStringContainsString('name="source" value="direct"', $html);
        $this->assertStringContainsString('name="attemptToken" value="' . $attemptToken->value() . '"', $html);
        $this->assertStringContainsString('name="items[0][reference]" value="shirt"', $html);
        $this->assertStringContainsString('name="items[0][quantity]" value="2"', $html);
        $this->assertStringContainsString('name="items[0][options][size]" value="large"', $html);
        $this->assertStringContainsString('name="shippingCountry" value="PT"', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('<button', $html);
    }

    public function testDevelopmentEmbeddedCheckoutUsesTheCompleteCartBootstrap(): void
    {
        $snippet = dirname(__DIR__, 2) . '/site/snippets/embedded-checkout.php';
        $root = $this->kirby->root('snippets');
        $this->assertIsString($root);
        Dir::make($root);
        F::copy($snippet, $root . '/embedded-checkout.php');
        $this->copyDevelopmentSnippet('embedded-checkout-script.php');
        $attemptToken = AttemptToken::generate();
        $checkoutBootstrap = new CheckoutBootstrap(
            uiMode: UiMode::Embedded,
            checkoutSource: CheckoutSource::Cart,
            actionUrl: 'https://kirby-stripe-checkout.test/stripe-checkout/checkout',
            csrf: 'csrf-token',
            attemptToken: $attemptToken,
            cartRevision: 'cart-revision',
            publishableKey: 'pk_test_browser',
        );
        $html = $this->kirby->snippet(
            'embedded-checkout',
            ['checkoutBootstrap' => $checkoutBootstrap],
            true,
        );

        $this->assertIsString($html);
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('action="https://kirby-stripe-checkout.test/stripe-checkout/checkout"', $html);
        $this->assertStringContainsString('data-publishable-key="pk_test_browser"', $html);
        $this->assertStringContainsString('name="csrf" value="csrf-token"', $html);
        $this->assertStringContainsString('name="source" value="cart"', $html);
        $this->assertStringContainsString('name="attemptToken" value="' . $attemptToken->value() . '"', $html);
        $this->assertStringContainsString('name="revision" value="cart-revision"', $html);
        $this->assertStringContainsString('https://js.stripe.com/endive/stripe.js', $html);
        $this->assertStringContainsString('data-embedded-checkout-mount aria-busy="true" hidden', $html);
    }

    private function copyDevelopmentSnippet(string $name): void
    {
        $root = $this->kirby->root('snippets');
        $this->assertIsString($root);
        Dir::make($root);
        F::copy(dirname(__DIR__, 2) . '/site/snippets/' . $name, $root . '/' . $name);
    }
}
