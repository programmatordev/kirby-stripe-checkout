<?php

/** @var Kirby\Cms\Page $page */

$products = $page->children()->listed()->filter(
    static fn(Kirby\Cms\Page $product): bool => $product->developmentFixture()->toBool()
);
?>
<?php snippet('layout', ['title' => $page->title()], slots: true) ?>

<?php slot('content') ?>
    <p class="eyebrow">Resolver inputs</p>
    <h1><?= esc($page->title()) ?></h1>
    <p>Development products for testing prices, options and cart interactions.</p>

    <div class="grid">
        <?php foreach ($products as $product): ?>
            <?php snippet('product-card', ['product' => $product]) ?>
        <?php endforeach ?>
    </div>
<?php endslot() ?>
