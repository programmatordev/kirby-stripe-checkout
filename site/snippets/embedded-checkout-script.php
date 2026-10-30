<script>
    (() => {
        const checkoutForm = document.querySelector('[data-embedded-checkout]');
        const checkoutMount = document.querySelector('[data-embedded-checkout-mount]');

        if (!checkoutForm || !checkoutMount) {
            return;
        }

        const checkoutError = checkoutForm.querySelector('[data-checkout-error]');
        const checkoutFieldset = checkoutForm.querySelector('fieldset');
        const checkoutButton = checkoutForm.querySelector('[data-embedded-checkout-start]');
        let initializing = false;

        const showError = message => {
            checkoutError.textContent = message;
            checkoutError.hidden = false;
            checkoutError.focus();
            checkoutMount.hidden = true;
            checkoutMount.removeAttribute('aria-busy');
            checkoutButton?.removeAttribute('disabled');
            checkoutForm.removeAttribute('aria-busy');
            initializing = false;
        };

        const fetchClientSecret = async () => {
            const formData = new FormData(checkoutForm);
            let responsePayload;

            try {
                const response = await fetch(checkoutForm.action, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                    },
                    body: new URLSearchParams(formData),
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

        const initialize = async event => {
            event?.preventDefault();

            if (initializing) {
                return;
            }

            initializing = true;
            checkoutError.hidden = true;
            checkoutError.textContent = '';
            checkoutButton?.setAttribute('disabled', '');
            checkoutForm.setAttribute('aria-busy', 'true');
            checkoutMount.setAttribute('aria-busy', 'true');

            try {
                // Resolve plugin validation before Stripe creates any embedded visual state.
                const clientSecret = await fetchClientSecret();
                const stripe = Stripe(checkoutForm.dataset.publishableKey);
                const checkout = await stripe.createEmbeddedCheckoutPage({
                    fetchClientSecret: async () => clientSecret,
                });

                checkoutFieldset.hidden = true;
                checkoutMount.hidden = false;
                checkout.mount('#embedded-checkout');
                checkoutMount.removeAttribute('aria-busy');
                checkoutForm.removeAttribute('aria-busy');
            } catch {
                if (checkoutError.hidden) {
                    showError('Embedded Checkout is unavailable. Refresh this page before trying again.');
                }
            }
        };

        if (checkoutForm.hasAttribute('data-embedded-checkout-auto')) {
            initialize();
        } else {
            checkoutForm.addEventListener('submit', initialize);
        }
    })();
</script>
