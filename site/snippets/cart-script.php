<script>
    (() => {
        // Development storefront only: keep the plugin's HTML renderer authoritative.
        const sidebar = document.querySelector('#cart');
        const feedback = sidebar.querySelector('[data-cart-feedback]');
        const refresh = sidebar.querySelector('[data-cart-refresh]');
        const mobile = window.matchMedia('(max-width: 60rem)');
        let busy = false;
        const cartView = () => sidebar.querySelector('[data-cart-view]');

        cartView().open = !mobile.matches || Boolean(cartView().querySelector('[role="alert"]'));
        mobile.addEventListener('change', () => { cartView().open = !mobile.matches; });

        document.querySelectorAll('[data-open-cart]').forEach(link => {
            link.addEventListener('click', () => {
                cartView().open = true;
                cartView().querySelector('summary').focus();
            });
        });

        const request = async (form = null, submitter = null) => {
            if (busy) return;
            const previous = cartView();
            const method = form?.dataset.cartMethod ?? 'GET';
            // Capture CSRF and the displayed revision before disabling fields;
            // FormData excludes controls inside a disabled fieldset.
            const body = form ? new URLSearchParams(new FormData(form)) : undefined;
            const active = document.activeElement;
            const restoreCartFocus = previous.contains(active);
            const action = form?.action;
            // Lock all controls while pending, preserving already-unavailable variants.
            const fieldsets = [...document.querySelectorAll('[data-cart-method] fieldset')];
            const disabled = fieldsets.map(fieldset => fieldset.disabled);
            busy = true;
            fieldsets.forEach(fieldset => { fieldset.disabled = true; });
            refresh.disabled = true;
            sidebar.setAttribute('aria-busy', 'true');
            feedback.textContent = method === 'GET' ? 'Refreshing cart…' : 'Updating cart…';

            try {
                let response = await fetch(action ?? previous.dataset.cartUrl, {
                    method,
                    headers: { Accept: 'text/html' },
                    body,
                });
                // A 204 means the write succeeded but rendering failed. Never repeat it.
                if (response.status === 204) {
                    response = await fetch(previous.dataset.cartUrl, { headers: { Accept: 'text/html' } });
                }
                const html = await response.text();
                if (!response.headers.get('Content-Type')?.includes('text/html')) {
                    throw new Error('No cart fragment');
                }
                const template = document.createElement('template');
                template.innerHTML = html;
                const next = template.content.querySelector('[data-cart-view]');
                if (!next) throw new Error('No cart fragment');

                next.open = previous.open || method === 'POST' || !response.ok;
                // Replace error fragments too: a 409 carries the current cart
                // and fresh revision inputs, but must never trigger a write retry.
                previous.replaceWith(next);
                feedback.textContent = response.ok
                    ? ({ POST: 'Added to cart.', PATCH: 'Quantity updated.', DELETE: 'Cart updated.', GET: 'Cart refreshed.' }[method])
                    : response.status === 409
                        ? 'The cart changed in another tab. Review it before trying again.'
                        : 'Could not update the cart. Review the message above.';

                if (restoreCartFocus) {
                    // Replacement removes the focused element. If its item was
                    // deleted, return focus to the cart summary instead.
                    const replacementForm = [...next.querySelectorAll('form')].find(candidate =>
                        candidate.action === action && candidate.dataset.cartMethod === method);
                    const target = active?.name
                        ? replacementForm?.elements.namedItem(active.name)
                        : replacementForm?.querySelector('button');
                    (target ?? next.querySelector('summary')).focus({ preventScroll: true });
                }
                if (method === 'POST' && mobile.matches) {
                    sidebar.scrollIntoView({ block: 'start' });
                }
            } catch {
                // A network failure is ambiguous. Offer a read, not a mutation retry.
                feedback.textContent = 'Could not confirm the result. Refresh the cart before trying again.';
            } finally {
                fieldsets.forEach((fieldset, index) => { fieldset.disabled = disabled[index]; });
                refresh.disabled = false;
                busy = false;
                sidebar.removeAttribute('aria-busy');
                if (!restoreCartFocus && submitter?.isConnected) {
                    submitter.focus({ preventScroll: true });
                }
            }
        };

        // Delegation also handles forms introduced by later cart fragments.
        document.addEventListener('submit', event => {
            const form = event.target.closest('form[data-cart-method]');
            if (!form) return;
            event.preventDefault();
            request(form, event.submitter);
        });
        refresh.addEventListener('click', () => request());
    })();
</script>
