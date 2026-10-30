<?php

use Kirby\Data\Json;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;

/** @var Kirby\Cms\Page $page */
/** @var Kirby\Cms\Site $site */

$stripeCheckout = $site->stripeCheckout();
$cart = $stripeCheckout->cart();
$options = [];
$variants = [];
$initialVariant = null;
$optionsAvailable = true;
$directCheckoutBootstrap = null;
$directCheckoutUnavailableMessage = null;
$images = $page->productImages()->toFiles();

try {
    $productOptions = $page->options()->toProductOptions();
    $options = $productOptions->options();
    $initialOptions = [];

    // Match the selects' first values so the initial HTML already shows the selected variant's price, rather than flashing the base product price.
    foreach ($options as $option) {
        $initialOptions[$option->id()] = $option->values()[0]->id();
    }

    $initialVariant = $productOptions->matchVariant($initialOptions);

    // Send presentation data only.
    // PHP formats exact amounts for the active language; JavaScript never calculates money or submits these prices.
    foreach ($productOptions->variants() as $variant) {
        $money = $variant->price() ?? $variant->stripePrice()?->price();
        $variants[] = [
            'options' => $variant->selectedOptions(),
            'enabled' => $variant->enabled(),
            'price' => $money === null ? null : $stripeCheckout->formatMoney($money),
            'requiresShipping' => $variant->requiresShipping(),
            'sku' => $variant->sku(),
        ];
    }
} catch (Throwable) {
    // Incomplete fixtures should stay browsable, without leaking provider errors.
    $optionsAvailable = false;
}

$requiresShipping = $initialVariant?->requiresShipping() ?? $page->requiresShipping()->toBool();
$mayRequireShipping = $requiresShipping;

foreach ($variants as $variant) {
    if ($variant['requiresShipping']) {
        $mayRequireShipping = true;
        break;
    }
}

if ($optionsAvailable) {
    try {
        $directCheckoutBootstrap = $stripeCheckout->checkout(CheckoutSource::Direct);
    } catch (CheckoutInputException|ConfigurationException) {
        $directCheckoutUnavailableMessage = 'Direct Checkout is unavailable. Review the product and store configuration.';
    }
}

snippet('layout', ['title' => $page->title(), 'cart' => $cart], slots: true);
?>
<?php slot('content') ?>
    <a class="back-link" href="<?= esc($site->find('products')?->url(), 'attr') ?>">← All products</a>
    <article class="product-detail">
        <div class="product-media">
            <div class="product-visual <?= $page->requiresShipping()->toBool() ? 'physical' : 'digital' ?>">
                <?php if ($image = $images->first()): ?>
                    <img src="<?= esc($image->resize(1000)->url(), 'attr') ?>" alt="<?= esc($page->title(), 'attr') ?>">
                <?php else: ?>
                    <span class="product-monogram" aria-hidden="true"><?= esc(mb_substr($page->title()->value(), 0, 1)) ?></span>
                    <span class="image-caption">No product image</span>
                <?php endif ?>
            </div>
            <?php if ($images->count() > 1): ?>
                <div class="gallery">
                    <?php foreach ($images->offset(1) as $image): ?>
                        <a href="<?= esc($image->url(), 'attr') ?>"><img src="<?= esc($image->crop(240, 240)->url(), 'attr') ?>" alt="<?= esc($image->alt()->or($page->title()), 'attr') ?>" width="240" height="240" loading="lazy"></a>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
        </div>
        <div>
            <p class="eyebrow" data-product-shipping><?= $requiresShipping ? 'Shipping required' : 'No shipping required' ?></p>
            <h1><?= esc($page->title()) ?></h1>
            <p class="product-description"><?= esc($page->description()) ?></p>
            <?php if ($options !== []): ?>
                <?php $initialPrice = $initialVariant?->price() ?? $initialVariant?->stripePrice()?->price(); ?>
                <p class="product-price" data-product-price><?= $initialPrice === null ? 'Unavailable' : esc($stripeCheckout->formatMoney($initialPrice)) ?></p>
            <?php else: ?>
                <?php snippet('product-price', ['product' => $page, 'site' => $site]) ?>
            <?php endif ?>

            <?php if (!$optionsAvailable): ?>
                <p class="notice error">Product options are unavailable. Check this product and the active price source in the Panel.</p>
            <?php else: ?>
                <?php if ($cart === null): ?>
                    <p class="notice">The cart is disabled in the site configuration. Direct Checkout remains available.</p>
                <?php else: ?>
                    <form class="product-form" data-product-selection data-variants="<?= esc(Json::encode($variants), 'attr') ?>"
                        action="<?= esc($site->url() . '/stripe-checkout/cart/items', 'attr') ?>"
                        method="post" data-cart-method="POST">
                        <fieldset>
                            <input type="hidden" name="csrf" value="<?= esc(csrf(), 'attr') ?>">
                            <input type="hidden" name="reference" value="<?= esc($page->id(), 'attr') ?>">
                            <?php if ($options !== []): ?>
                                <div class="option-fields">
                                    <?php foreach ($options as $option): ?>
                                        <label>
                                            <?= esc($option->name()) ?>
                                            <select name="options[<?= esc($option->id(), 'attr') ?>]" data-option-id="<?= esc($option->id(), 'attr') ?>">
                                                <?php foreach ($option->values() as $value): ?>
                                                    <option value="<?= esc($value->id(), 'attr') ?>"><?= esc($value->name()) ?></option>
                                                <?php endforeach ?>
                                            </select>
                                        </label>
                                    <?php endforeach ?>
                                </div>
                                <p class="variant-status" data-variant-status role="status" aria-live="polite"></p>
                            <?php endif ?>
                            <div class="purchase-row">
                                <label>Quantity <input type="number" name="quantity" min="1" step="1" value="1" required></label>
                                <button type="submit" data-product-submit <?= $options !== [] && $initialVariant === null ? 'disabled' : '' ?>>Add to cart</button>
                            </div>
                        </fieldset>
                    </form>
                <?php endif ?>
                <?php if ($directCheckoutUnavailableMessage !== null): ?>
                    <p class="notice error" role="alert"><?= esc($directCheckoutUnavailableMessage) ?></p>
                <?php elseif ($directCheckoutBootstrap !== null): ?>
                    <?php snippet('direct-checkout', [
                        'checkoutBootstrap' => $directCheckoutBootstrap,
                        'product' => $page,
                        'options' => $options,
                        'variants' => $variants,
                        'requiresShipping' => $requiresShipping,
                        'mayRequireShipping' => $mayRequireShipping,
                    ]) ?>
                <?php endif ?>
            <?php endif ?>
            <details class="technical-details">
                <summary>Product details & testing</summary>
                <p>Reference: <code><?= esc($page->id()) ?></code></p>
                <p>SKU: <span data-product-sku><?= esc($initialVariant?->sku() ?? $page->sku()->or('Not set')) ?></span></p>
                <p><a href="<?= esc($page->panel()->url(), 'attr') ?>">Edit product in Panel ↗</a></p>
            </details>
        </div>
        <?php if ($optionsAvailable): ?>
            <?php snippet('product-script') ?>
        <?php endif ?>
    </article>
<?php endslot() ?>
