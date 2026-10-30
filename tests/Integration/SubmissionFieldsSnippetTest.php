<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

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
}
