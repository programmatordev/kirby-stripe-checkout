<?php

use Kirby\Data\Json;
use ProgrammatorDev\StripeCheckout\Product\Prototype\VariantProjection;

/** @var Kirby\Cms\App $kirby */
/** @var Kirby\Cms\Page $page */

$defaultLanguageCode = $kirby->defaultLanguage()?->code();
$currentLanguageCode = $kirby->language()?->code();
$canonicalVariants = $defaultLanguageCode === null
    ? $page->stripeCheckoutVariants()->value()
    : $page->content($defaultLanguageCode)->get('stripeCheckoutVariants')->value();
$translatedVariants = $defaultLanguageCode !== null && $currentLanguageCode !== $defaultLanguageCode
    ? $page->content($currentLanguageCode)->get('stripeCheckoutVariants')->value()
    : null;
$variantProjection = (new VariantProjection())->project(
    $canonicalVariants,
    $translatedVariants,
);

snippet('layout', ['title' => $page->title()], slots: true);
?>

<?php slot('content') ?>
    <p class="eyebrow"><?= $page->requiresShipping()->toBool() ? 'Physical product' : 'Digital or service product' ?></p>
    <h1><?= esc($page->title()) ?></h1>
    <p><?= esc($page->summary()) ?></p>
    <p><strong><?= number_format($page->price()->toFloat(), 2) ?> EUR</strong></p>

    <?php if ($variantProjection['groups'] !== []): ?>
        <form
            class="variant-prototype"
            data-variants="<?= esc(Json::encode($variantProjection['variants']), 'attr') ?>"
            onsubmit="return false"
        >
            <h2>Available options</h2>
            <?php foreach ($variantProjection['groups'] as $group): ?>
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
