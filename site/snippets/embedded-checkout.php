<?php

/** @var ProgrammatorDev\StripeCheckout\Checkout\CheckoutBootstrap $checkoutBootstrap */
?>
<form
    class="checkout-form"
    action="<?= esc($checkoutBootstrap->actionUrl(), 'attr') ?>"
    method="post"
    data-embedded-checkout
    data-embedded-checkout-auto
    data-publishable-key="<?= esc($checkoutBootstrap->publishableKey(), 'attr') ?>"
>
    <p class="notice error" data-checkout-error role="alert" tabindex="-1" hidden></p>
    <fieldset hidden>
        <?php snippet('stripe-checkout/submission-fields', ['checkoutBootstrap' => $checkoutBootstrap]) ?>
    </fieldset>
</form>
<div id="embedded-checkout" data-embedded-checkout-mount aria-busy="true" hidden></div>
<?php
// Stripe's current Embedded Checkout browser integration:
// https://docs.stripe.com/payments/accept-a-payment?payment-ui=checkout&ui=embedded-page#mount-checkout
?>
<script src="https://js.stripe.com/endive/stripe.js"></script>
<?php snippet('embedded-checkout-script') ?>
