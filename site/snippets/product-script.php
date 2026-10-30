<script>
    (() => {
        const product = document.currentScript.closest('.product-detail');
        const forms = [...product.querySelectorAll('[data-product-selection]')];

        const resolve = form => {
            const selects = [...form.querySelectorAll('[data-option-id]')];

            if (!selects.length) {
                return;
            }

            const variants = JSON.parse(form.dataset.variants);
            const status = form.querySelector('[data-variant-status]');
            const options = Object.fromEntries(selects.map(select => [select.dataset.optionId, select.value]));
            const variant = variants.find(candidate => candidate.enabled
                && Object.entries(options).every(([id, value]) => candidate.options[id] === value));

            // Display-only matching. Prices and availability are resolved again on Cart addition or Checkout submission.
            form.querySelectorAll('[data-product-submit]').forEach(button => {
                button.disabled = !variant || variant.price === null;
            });
            status.dataset.unavailable = String(!variant || variant.price === null);
            status.textContent = !variant
                ? 'This combination is unavailable. Try another option.'
                : variant.price === null ? 'Price unavailable.' : 'This combination is available.';
            product.querySelector('[data-product-price]').textContent = variant?.price ?? 'Unavailable';
            product.querySelector('[data-product-shipping]').textContent = variant
                ? (variant.requiresShipping ? 'Shipping required' : 'No shipping required')
                : 'Choose an available combination';
            product.querySelector('[data-product-sku]').textContent = variant?.sku ?? 'Not set';

            const shippingField = form.querySelector('[data-direct-shipping]');

            if (shippingField) {
                const shippingSelect = shippingField.querySelector('select');
                const requiresShipping = Boolean(variant?.requiresShipping);

                shippingField.hidden = !requiresShipping;
                shippingSelect.disabled = !requiresShipping;
                shippingSelect.required = requiresShipping;
            }
        };

        forms.forEach(form => {
            form.querySelectorAll('[data-option-id]').forEach(select => {
                select.addEventListener('change', () => resolve(form));
            });
            resolve(form);
        });
    })();
</script>
