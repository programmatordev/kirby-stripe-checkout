<?php

/** @var Kirby\Cms\Page $page */
/** @var Kirby\Cms\Site $site */

snippet('layout', ['title' => $page->title()], slots: true);
?>
<?php slot('content') ?>
    <div class="page-heading">
        <p class="eyebrow">The test collection</p>
        <h1>A little of everything.</h1>
        <p>Physical goods, digital products and a few options to try. Pick a product and build your cart.</p>
    </div>
    <?php snippet('product-list', ['catalogue' => $site->find('products'), 'site' => $site]) ?>
<?php endslot() ?>
