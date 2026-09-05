<?php

/** @var Kirby\Cms\Page $page */
/** @var Kirby\Cms\Site $site */

snippet('layout', ['title' => $page->title()], slots: true);
?>
<?php slot('content') ?>
    <div class="page-heading">
        <p class="eyebrow">Your selection</p>
        <h1>Make it your own.</h1>
        <p>Your cart is alongside every page. Change quantities, remove items or keep exploring the collection.</p>
    </div>
    <a class="button" href="<?= esc($site->find('products')?->url(), 'attr') ?>">Continue shopping →</a>
<?php endslot() ?>
