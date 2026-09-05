<?php

use Kirby\Data\Json;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;

/** @var Kirby\Cms\Page $page */
/** @var Kirby\Cms\Site $site */

$stripeCheckout = $site->stripeCheckout();
$settings = $stripeCheckout->settings();
$currency = $settings->currency();
$productOptions = $page->options()->toProductOptions();
$optionsData = $productOptions->toArray();
$formattedPrice = null;
$cart = $stripeCheckout->cart();

if ($settings->priceSource() === PriceSource::Stripe) {
    $stripePrice = $page->stripePrice()->toProductStripePrice();
    $formattedPrice = $stripePrice === null
        ? null
        : $stripeCheckout->formatMoney($stripePrice->price());
} else {
    $price = $page->price()->value();

    if ($price !== null && $price !== '') {
        $formattedPrice = $currency === null
            ? $price
            : $stripeCheckout->formatMoney($price, $currency);
    }
}

snippet('layout', ['title' => $page->title()], slots: true);
?>

<?php slot('content') ?>
    <p class="eyebrow"><?= $page->requiresShipping()->value() === 'yes' ? 'Physical product' : 'Digital or service product' ?></p>
    <h1><?= esc($page->title()) ?></h1>
    <p><?= esc($page->description()) ?></p>
    <?php if ($formattedPrice !== null): ?>
        <p><strong><?= esc($formattedPrice) ?></strong></p>
    <?php endif ?>

    <?php if ($cart !== null): ?>
        <form
            class="variant-prototype"
            data-variants="<?= esc(Json::encode($optionsData['variants']), 'attr') ?>"
            action="<?= esc($site->url() . '/stripe-checkout/cart/items', 'attr') ?>"
            method="post"
            data-cart-method="POST"
        >
            <input type="hidden" name="csrf" value="<?= esc(csrf(), 'attr') ?>">
            <input type="hidden" name="reference" value="<?= esc($page->id(), 'attr') ?>">
            <?php if ($optionsData['options'] !== []): ?>
            <h2>Available options</h2>
            <?php endif ?>
            <?php foreach ($optionsData['options'] as $option): ?>
                <label>
                    <span><?= esc($option['name']) ?></span>
                    <select name="options[<?= esc($option['id'], 'attr') ?>]" data-option-id="<?= esc($option['id'], 'attr') ?>">
                        <?php foreach ($option['values'] as $value): ?>
                            <option value="<?= esc($value['id'], 'attr') ?>"><?= esc($value['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </label>
            <?php endforeach ?>
            <p data-variant-status aria-live="polite"></p>
            <label>Quantity <input type="number" name="quantity" min="1" step="1" value="1" required></label>
            <button>Add to cart</button>
        </form>
        <script>
            (() => {
                const form = document.currentScript.previousElementSibling;
                const variants = JSON.parse(form.dataset.variants);
                const status = form.querySelector('[data-variant-status]');
                if (!form.querySelector('select')) return;

                const resolve = () => {
                    const selectedOptions = Object.fromEntries(
                        [...form.querySelectorAll('select')].map(select => [
                            select.dataset.optionId,
                            select.value,
                        ]),
                    );
                    const variant = variants.find(candidate =>
                        candidate.enabled === true
                        && Object.entries(selectedOptions).every(([optionId, valueId]) =>
                            candidate.selectedOptions[optionId] === valueId
                        )
                    );

                    // Browser feedback is advisory; the cart resolves options again.
                    form.querySelector('button').disabled = !variant;
                    if (!variant) {
                        status.textContent = 'Unavailable combination';
                        return;
                    }

                    const priceData = variant.price ?? variant.stripePrice?.price;
                    const price = `${priceData.amount} ${priceData.currency}`;
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
