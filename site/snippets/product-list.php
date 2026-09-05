<?php

/** @var Kirby\Cms\Page|null $catalogue */
/** @var Kirby\Cms\Site $site */

$products = $catalogue?->children()->listed();
?>
<?php if ($products === null || $products->isEmpty()): ?>
    <p class="notice">No products yet. Add a listed product in the Panel to start testing.</p>
<?php else: ?>
    <div class="product-grid">
        <?php foreach ($products as $product): ?>
            <?php snippet('product-card', ['product' => $product, 'site' => $site]) ?>
        <?php endforeach ?>
    </div>
<?php endif ?>
