<?php

use Kirby\Cms\Page;
use Kirby\Data\Json;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutBootstrap;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Kirby\ShippingCountryOptions;
use ProgrammatorDev\StripeCheckout\Product\ProductOption;

/** @var CheckoutBootstrap $checkoutBootstrap */
/** @var Page $product */
/** @var list<ProductOption> $options */
/** @var list<array{options: array<string, string>, enabled: bool, price: ?string, requiresShipping: bool, sku: ?string}> $variants */
/** @var bool $requiresShipping */
/** @var bool $mayRequireShipping */

$checkoutError = $checkoutBootstrap->error();
$submissionData = $checkoutBootstrap->submissionData();
$retainsAcceptedSubmission = isset($submissionData['items']);
$embeddedCheckout = $checkoutBootstrap->uiMode() === UiMode::Embedded;
$shippingCountryOptions = $mayRequireShipping ? (new ShippingCountryOptions())->all() : [];
?>
<section class="direct-checkout" aria-labelledby="direct-checkout-title">
    <h2 id="direct-checkout-title">Direct Checkout</h2>
    <p class="checkout-note">Checkout this product without adding it to the cart.</p>
    <form
        class="checkout-form direct-checkout-form"
        action="<?= esc($checkoutBootstrap->actionUrl(), 'attr') ?>"
        method="post"
        <?php if ($embeddedCheckout): ?>
            data-embedded-checkout
            data-publishable-key="<?= esc($checkoutBootstrap->publishableKey(), 'attr') ?>"
        <?php else: ?>
            data-hosted-checkout
        <?php endif ?>
        <?php if (!$retainsAcceptedSubmission): ?>
            data-product-selection
            data-variants="<?= esc(Json::encode($variants), 'attr') ?>"
        <?php endif ?>
    >
        <p class="notice error" data-checkout-error role="alert" tabindex="-1" <?= $checkoutError === null ? 'hidden' : '' ?>><?= $checkoutError === null ? '' : esc($checkoutError->message()) ?></p>
        <fieldset>
            <?php snippet('stripe-checkout/submission-fields', ['checkoutBootstrap' => $checkoutBootstrap]) ?>
            <?php if ($retainsAcceptedSubmission): ?>
                <p class="checkout-note">This action keeps the product selection accepted by the previous Checkout attempt.</p>
            <?php else: ?>
                <input type="hidden" name="items[0][reference]" value="<?= esc($product->uuid()->toString(), 'attr') ?>">
                <?php if ($options !== []): ?>
                    <div class="option-fields">
                        <?php foreach ($options as $option): ?>
                            <label>
                                <?= esc($option->name()) ?>
                                <select name="items[0][options][<?= esc($option->id(), 'attr') ?>]" data-option-id="<?= esc($option->id(), 'attr') ?>">
                                    <?php foreach ($option->values() as $value): ?>
                                        <option value="<?= esc($value->id(), 'attr') ?>"><?= esc($value->name()) ?></option>
                                    <?php endforeach ?>
                                </select>
                            </label>
                        <?php endforeach ?>
                    </div>
                    <p class="variant-status" data-variant-status role="status" aria-live="polite"></p>
                <?php endif ?>
                <div class="direct-checkout-inputs">
                    <label>Quantity <input type="number" name="items[0][quantity]" min="1" step="1" value="1" required></label>
                    <?php if ($shippingCountryOptions !== []): ?>
                        <label data-direct-shipping <?= $requiresShipping ? '' : 'hidden' ?>>
                            Shipping country
                            <select name="shippingCountry" <?= $requiresShipping ? 'required' : 'disabled' ?>>
                                <option value="">Choose a country</option>
                                <?php foreach ($shippingCountryOptions as $country => $name): ?>
                                    <option value="<?= esc($country, 'attr') ?>"><?= esc($name) ?></option>
                                <?php endforeach ?>
                            </select>
                        </label>
                    <?php endif ?>
                </div>
            <?php endif ?>
            <?php if ($embeddedCheckout): ?>
                <button type="submit" data-embedded-checkout-start data-product-submit>Open Embedded Checkout</button>
            <?php else: ?>
                <div class="checkout-actions">
                    <button type="submit" data-checkout-transport="json" data-product-submit>Checkout with JavaScript</button>
                    <button class="checkout-native" type="submit" data-checkout-transport="html" data-product-submit>Checkout without JavaScript</button>
                </div>
            <?php endif ?>
        </fieldset>
    </form>
    <?php if ($embeddedCheckout): ?>
        <div id="embedded-checkout" data-embedded-checkout-mount hidden></div>
        <?php // https://docs.stripe.com/payments/accept-a-payment?payment-ui=checkout&ui=embedded-page#mount-checkout ?>
        <script src="https://js.stripe.com/endive/stripe.js"></script>
        <?php snippet('embedded-checkout-script') ?>
    <?php else: ?>
        <p class="checkout-note">Both options submit the same Direct purchase. The native form lets the server redirect directly to Stripe Checkout.</p>
    <?php endif ?>
</section>
