<script>
    // Development example only: no bundled frontend library is required.
    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('form[data-cart-method]');
        if (!form) return;
        event.preventDefault();
        const feedback = document.querySelector('[data-cart-feedback]');
        const button = event.submitter;
        const body = new URLSearchParams(new FormData(form));
        if (button) button.disabled = true;
        feedback.textContent = 'Updating cart…';

        try {
            let response = await fetch(form.action, {
                method: form.dataset.cartMethod,
                headers: { 'Accept': 'text/html' },
                body,
            });
            // The write succeeded but its fragment failed. Read again, never
            // retry the mutation: doing so could add the product twice.
            if (response.status === 204) {
                response = await fetch(document.querySelector('[data-cart-view]').dataset.cartUrl, {
                    headers: { 'Accept': 'text/html' },
                });
            }
            const html = await response.text();
            if (html && response.headers.get('Content-Type')?.includes('text/html')) {
                document.querySelector('[data-cart-view]').outerHTML = html;
            }
            feedback.textContent = response.ok ? 'Cart updated.' : 'The cart could not be updated. Review the message or refresh the page.';
        } catch {
            feedback.textContent = 'The result could not be confirmed. Refresh the cart before trying again.';
        } finally {
            if (button) button.disabled = false;
        }
    });
</script>
