<?php

use Kirby\Data\Json;

/** @var Kirby\Cms\Page $page */
/** @var Kirby\Cms\Site $site */

$stripeCheckout = $site->stripeCheckout();
$settings = $stripeCheckout->settings();
$currency = $settings->currency();
$selectionView = $stripeCheckout->productSelection($page);
$selectionData = $selectionView->toArray();
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

    <?php if ($selectionData['groups'] !== []): ?>
        <form
            class="variant-prototype"
            data-variants="<?= esc(Json::encode($selectionData['variants']), 'attr') ?>"
            onsubmit="return false"
        >
            <h2>Available options</h2>
            <?php foreach ($selectionData['groups'] as $group): ?>
                <label>
                    <span><?= esc($group['label']) ?></span>
                    <select name="stripeCheckoutChoices[<?= esc($group['id'], 'attr') ?>]">
                        <?php foreach ($group['values'] as $value): ?>
                            <option value="<?= esc($value['id'], 'attr') ?>"><?= esc($value['label']) ?></option>
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
                    const choices = Object.fromEntries(
                        [...form.querySelectorAll('select')].map(select => [
                            select.name.match(/\[([^\]]+)\]/)[1],
                            select.value,
                        ]),
                    );
                    const variant = variants.find(candidate =>
                        candidate.enabled === true
                        && Object.entries(choices).every(([groupId, valueId]) =>
                            candidate.choices[groupId] === valueId
                        )
                    );

                    // The browser resolves feedback only. A future cart endpoint
                    // will rematch the submitted choices against canonical data.
                    status.textContent = variant ? 'Available combination' : 'Unavailable combination';
                };

                form.addEventListener('change', resolve);
                resolve();
            })();
        </script>
    <?php endif ?>

    <p><code><?= esc($page->id()) ?></code></p>
<?php endslot() ?>
