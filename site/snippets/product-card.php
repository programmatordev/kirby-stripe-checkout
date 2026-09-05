<?php

/** @var Kirby\Cms\Page $product */
/** @var Kirby\Cms\Site $site */

$image = $product->productImages()->toFiles()->first();
?>
<article class="product-card">
    <a class="product-card-link" href="<?= esc($product->url(), 'attr') ?>">
        <div class="product-visual <?= $product->requiresShipping()->toBool() ? 'physical' : 'digital' ?>">
            <?php if ($image !== null): ?>
                <img src="<?= esc($image->crop(600, 450)->url(), 'attr') ?>" alt="" width="600" height="450" loading="lazy">
            <?php else: ?>
                <span class="product-monogram" aria-hidden="true"><?= esc(mb_substr($product->title()->value(), 0, 1)) ?></span>
                <span class="image-caption">No product image</span>
            <?php endif ?>
        </div>
        <div class="product-card-body">
            <span class="eyebrow"><?= $product->requiresShipping()->toBool() ? 'Physical product' : 'Digital & more' ?></span>
            <h2><?= esc($product->title()) ?></h2>
            <?php snippet('product-price', ['product' => $product, 'site' => $site]) ?>
            <span class="product-card-action">View product <span aria-hidden="true">↗</span></span>
        </div>
    </a>
</article>
