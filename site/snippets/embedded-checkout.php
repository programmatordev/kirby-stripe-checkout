<?php

/** @var ProgrammatorDev\StripeCheckout\Checkout\CheckoutBootstrap $checkoutBootstrap */
?>
<form
    class="checkout-form"
    action="<?= esc($checkoutBootstrap->actionUrl(), 'attr') ?>"
    method="post"
    data-embedded-checkout
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
<script>
    (() => {
        const checkoutForm = document.querySelector('[data-embedded-checkout]');
        const checkoutMount = document.querySelector('[data-embedded-checkout-mount]');

        if (!checkoutForm || !checkoutMount) {
            return;
        }

        const checkoutError = checkoutForm.querySelector('[data-checkout-error]');
        const showError = message => {
            checkoutError.textContent = message;
            checkoutError.hidden = false;
            checkoutError.focus();
            checkoutMount.hidden = true;
            checkoutMount.removeAttribute('aria-busy');
        };

        const fetchClientSecret = async () => {
            const formData = new FormData(checkoutForm);
            const csrf = formData.get('csrf');
            let responsePayload;

            formData.delete('csrf');

            try {
                const response = await fetch(checkoutForm.action, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF': String(csrf ?? ''),
                    },
                    body: JSON.stringify(Object.fromEntries(formData)),
                });
                responsePayload = await response.json();
            } catch (error) {
                // A network failure may follow an accepted mutation, so never repeat the submission automatically.
                showError('Could not confirm whether Checkout started. Refresh this page before trying again.');

                throw error;
            }

            if (
                responsePayload.ok === true
                && responsePayload.data?.uiMode === 'embedded'
                && typeof responsePayload.data.clientSecret === 'string'
            ) {
                return responsePayload.data.clientSecret;
            }

            const errorMessage = typeof responsePayload.error?.message === 'string'
                ? responsePayload.error.message
                : 'Checkout is unavailable. Refresh this page before trying again.';

            showError(errorMessage);
            throw new Error('Embedded Checkout could not be initialized.');
        };

        const initialize = async () => {
            try {
                // Resolve plugin validation before Stripe creates any embedded visual state.
                const clientSecret = await fetchClientSecret();
                const stripe = Stripe(checkoutForm.dataset.publishableKey);
                const checkout = await stripe.createEmbeddedCheckoutPage({
                    fetchClientSecret: async () => clientSecret,
                });

                checkoutMount.hidden = false;
                checkout.mount('#embedded-checkout');
                checkoutMount.removeAttribute('aria-busy');
            } catch {
                if (checkoutError.hidden) {
                    showError('Embedded Checkout is unavailable. Refresh this page before trying again.');
                }
            }
        };

        initialize();
    })();
</script>
