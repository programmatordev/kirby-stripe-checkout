<script>
    document.addEventListener('submit', async event => {
        const checkoutForm = event.target.closest('[data-hosted-checkout]');

        if (!checkoutForm) {
            return;
        }

        // Development controls keep JSON and native form paths testable without disabling JavaScript.
        if (event.submitter?.dataset.checkoutTransport !== 'json') {
            return;
        }

        event.preventDefault();

        const checkoutFieldset = checkoutForm.querySelector('fieldset');
        const checkoutError = checkoutForm.querySelector('[data-checkout-error]');

        if (checkoutFieldset.disabled) {
            return;
        }

        const formData = new FormData(checkoutForm);
        const csrf = formData.get('csrf');

        formData.delete('csrf');

        // This development form uses Cart submission data, whose body fields are scalar.
        const submissionData = Object.fromEntries(formData);

        checkoutError.hidden = true;
        checkoutError.textContent = '';
        checkoutFieldset.disabled = true;
        checkoutForm.setAttribute('aria-busy', 'true');

        let checkoutErrorMessage;

        try {
            const response = await fetch(checkoutForm.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF': String(csrf ?? ''),
                },
                body: JSON.stringify(submissionData),
            });
            const responsePayload = await response.json();

            if (
                responsePayload.ok === true
                && responsePayload.data?.uiMode === 'hosted'
                && typeof responsePayload.data.redirectUrl === 'string'
            ) {
                window.location.assign(responsePayload.data.redirectUrl);

                return;
            }

            checkoutErrorMessage = typeof responsePayload.error?.message === 'string'
                ? responsePayload.error.message
                : 'Checkout is unavailable. Refresh this page before trying again.';
        } catch {
            // A network failure may follow an accepted mutation, so never repeat the submission automatically.
            checkoutErrorMessage = 'Could not confirm whether Checkout started. Refresh this page before trying again.';
        }

        checkoutError.textContent = checkoutErrorMessage;
        checkoutError.hidden = false;
        checkoutError.focus();

        checkoutFieldset.disabled = false;
        checkoutForm.removeAttribute('aria-busy');
    });
</script>
