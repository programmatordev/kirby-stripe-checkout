<?php

/** @var ProgrammatorDev\StripeCheckout\Checkout\CheckoutBootstrap $checkoutBootstrap */

$checkoutError = $checkoutBootstrap->error();
?>
<form class="checkout-form" action="<?= esc($checkoutBootstrap->actionUrl(), 'attr') ?>" method="post" data-hosted-checkout>
    <p class="notice error" data-checkout-error role="alert" tabindex="-1" <?= $checkoutError === null ? 'hidden' : '' ?>><?= $checkoutError === null ? '' : esc($checkoutError->message()) ?></p>
    <fieldset>
        <?php snippet('stripe-checkout/submission-fields', ['checkoutBootstrap' => $checkoutBootstrap]) ?>
        <div class="checkout-actions">
            <button class="cart-checkout" type="submit" data-checkout-transport="json">Checkout with JavaScript</button>
            <button class="cart-checkout checkout-native" type="submit" data-checkout-transport="html">Checkout without JavaScript</button>
        </div>
    </fieldset>
</form>
<p class="checkout-note">Both options submit the same data. The native form lets the server redirect directly to Stripe Checkout.</p>
