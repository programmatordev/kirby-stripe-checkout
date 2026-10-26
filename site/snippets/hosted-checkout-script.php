<script>
    (() => {
        let checkoutRedirectPending = false;

        document.addEventListener('submit', async event => {
            const checkoutForm = event.target.closest('[data-hosted-checkout]');
            if (!checkoutForm) return;
            if (event.submitter?.dataset.checkoutTransport !== 'json') return;
            event.preventDefault();
            const checkoutFieldset = checkoutForm.querySelector('fieldset');
            const checkoutError = checkoutForm.querySelector('[data-checkout-error]');
            if (checkoutFieldset.disabled) return;
            const formData = new FormData(checkoutForm);
            const csrf = formData.get('csrf');
            formData.delete('csrf');
            // This development form uses Cart submission data, whose body fields are scalar.
            const submissionData = Object.fromEntries(formData);
            checkoutError.hidden = true;
            checkoutError.textContent = '';
            checkoutFieldset.disabled = true;
            checkoutForm.setAttribute('aria-busy', 'true');

            try {
                const response = await fetch(checkoutForm.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF': String(csrf ?? ''),
                    },
                    body: JSON.stringify(submissionData),
                });
                const responsePayload = await response.json();

                if (responsePayload.ok === true
                    && responsePayload.data?.uiMode === 'hosted'
                    && typeof responsePayload.data.redirectUrl === 'string') {
                    // Keep the form disabled when finally runs while browser navigation begins.
                    checkoutRedirectPending = true;
                    window.location.assign(responsePayload.data.redirectUrl);
                    return;
                }

                checkoutError.textContent = typeof responsePayload.error?.message === 'string'
                    ? responsePayload.error.message
                    : 'Checkout is unavailable. Refresh this page before trying again.';
                checkoutError.hidden = false;
                checkoutError.focus();
            } catch {
                // A network failure may follow an accepted mutation, so never repeat the submission automatically.
                checkoutError.textContent = 'Could not confirm whether Checkout started. Refresh this page before trying again.';
                checkoutError.hidden = false;
                checkoutError.focus();
            } finally {
                if (!checkoutRedirectPending) {
                    checkoutFieldset.disabled = false;
                    checkoutForm.removeAttribute('aria-busy');
                }
            }
        });
    })();
</script>
