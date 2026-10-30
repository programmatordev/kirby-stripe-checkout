<script>
    function checkoutSubmissionData(formData) {
        const submissionData = Object.fromEntries(formData);

        if (submissionData.source !== 'direct') {
            return submissionData;
        }

        // Native Direct fields use PHP bracket names; the JSON boundary expects the equivalent nested item.
        const item = {
            reference: formData.get('items[0][reference]'),
            quantity: Number(formData.get('items[0][quantity]')),
        };
        const options = {};
        const optionNamePattern = /^items\[0]\[options]\[([^\]]+)]$/;

        for (const [name, value] of formData) {
            const optionNameMatch = name.match(optionNamePattern);

            if (optionNameMatch) {
                options[optionNameMatch[1]] = value;
            }
        }

        if (Object.keys(options).length) {
            item.options = options;
        }

        submissionData.items = [item];
        const shippingCountry = formData.get('shippingCountry');

        if (shippingCountry) {
            submissionData.shippingCountry = shippingCountry;
        } else {
            delete submissionData.shippingCountry;
        }

        for (const name of [...Object.keys(submissionData)]) {
            if (name.startsWith('items[')) {
                delete submissionData[name];
            }
        }

        return submissionData;
    }
</script>
