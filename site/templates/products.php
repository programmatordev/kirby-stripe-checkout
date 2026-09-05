<?php

/** @var Kirby\Cms\Page $page */
/** @var Kirby\Cms\Site $site */

snippet('layout', ['title' => $page->title()], slots: true);
?>
<?php slot('content') ?>
    <div class="page-heading">
        <p class="eyebrow">The test collection</p>
        <h1>All products</h1>
        <p>Compare prices, choose options and try different combinations in your cart.</p>
    </div>
    <?php snippet('product-list', ['catalogue' => $page, 'site' => $site]) ?>
<?php endslot() ?>
