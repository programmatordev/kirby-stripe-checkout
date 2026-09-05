<script>
    (() => {
        const product = document.currentScript.closest('.product-detail');
        const form = product.querySelector('.product-form');
        const selects = [...form.querySelectorAll('[data-option-id]')];
        if (!selects.length) return;
        const variants = JSON.parse(form.dataset.variants);
        const status = form.querySelector('[data-variant-status]');

        const resolve = () => {
            const options = Object.fromEntries(selects.map(select => [select.dataset.optionId, select.value]));
            const variant = variants.find(candidate => candidate.enabled
                && Object.entries(options).every(([id, value]) => candidate.options[id] === value));

            // Display-only matching. Prices and availability are resolved again on add.
            form.querySelector('button').disabled = !variant || variant.price === null;
            status.dataset.unavailable = String(!variant || variant.price === null);
            status.textContent = !variant
                ? 'This combination is unavailable. Try another option.'
                : variant.price === null ? 'Price unavailable.' : 'This combination is available.';
            product.querySelector('[data-product-price]').textContent = variant?.price ?? 'Unavailable';
            product.querySelector('[data-product-shipping]').textContent = variant
                ? (variant.requiresShipping ? 'Shipping required' : 'No shipping required')
                : 'Choose an available combination';
            product.querySelector('[data-product-sku]').textContent = variant?.sku ?? 'Not set';
        };

        selects.forEach(select => select.addEventListener('change', resolve));
        resolve();
    })();
</script>
