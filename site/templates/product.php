<?php

use Kirby\Data\Json;

/** @var Kirby\Cms\Page $page */
/** @var Kirby\Cms\Site $site */

$stripeCheckout = $site->stripeCheckout();
$settings = $stripeCheckout->settings();
$currency = $settings->currency();
$productOptions = $stripeCheckout->productOptions($page);
$optionsData = $productOptions->toArray();
$price = $page->stripeCheckoutPrice()->value();
$formattedPrice = $currency === null
    ? $price
    : $stripeCheckout->formatMoney($price, $currency);

snippet('layout', ['title' => $page->title()], slots: true);
?>

<?php slot('content') ?>
    <p class="eyebrow"><?= $page->stripeCheckoutRequiresShipping()->value() === 'yes' ? 'Physical product' : 'Digital or service product' ?></p>
    <h1><?= esc($page->title()) ?></h1>
    <p><?= esc($page->summary()) ?></p>
    <p><strong><?= esc($formattedPrice) ?></strong></p>

    <?php if ($optionsData['options'] !== []): ?>
        <form
            class="variant-prototype"
            data-variants="<?= esc(Json::encode($optionsData['variants']), 'attr') ?>"
            onsubmit="return false"
        >
            <h2>Available options</h2>
            <?php foreach ($optionsData['options'] as $option): ?>
                <label>
                    <span><?= esc($option['name']) ?></span>
                    <select name="stripeCheckoutSelectedOptions[<?= esc($option['id'], 'attr') ?>]">
                        <?php foreach ($option['values'] as $value): ?>
                            <option value="<?= esc($value['id'], 'attr') ?>"><?= esc($value['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </label>
            <?php endforeach ?>
            <p data-variant-status aria-live="polite"></p>
        </form>
        <script>
            (() => {
                const form = document.currentScript.previousElementSibling;
                const variants = JSON.parse(form.dataset.variants);
                const status = form.querySelector('[data-variant-status]');

                const resolve = () => {
                    const selectedOptions = Object.fromEntries(
                        [...form.querySelectorAll('select')].map(select => [
                            select.name.match(/\[([^\]]+)\]/)[1],
                            select.value,
                        ]),
                    );
                    const variant = variants.find(candidate =>
                        candidate.enabled === true
                        && Object.entries(selectedOptions).every(([optionId, valueId]) =>
                            candidate.selectedOptions[optionId] === valueId
                        )
                    );

                    // The browser resolves feedback only. A future cart endpoint
                    // will rematch the submitted options against canonical data.
                    if (!variant) {
                        status.textContent = 'Unavailable combination';
                        return;
                    }

                    const price = variant.price.source === 'kirby'
                        ? `${variant.price.amount} ${variant.price.currency}`
                        : 'Stripe-managed price';
                    const details = [
                        'Available combination',
                        price,
                        variant.requiresShipping ? 'Shipping required' : 'No shipping required',
                    ];

                    if (variant.sku) {
                        details.push(`SKU ${variant.sku}`);
                    }

                    status.textContent = details.join(' · ');
                };

                form.addEventListener('change', resolve);
                resolve();
            })();
        </script>
    <?php endif ?>

    <p><code><?= esc($page->id()) ?></code></p>
<?php endslot() ?>
