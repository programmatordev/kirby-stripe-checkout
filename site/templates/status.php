<?php

/** @var Kirby\Cms\Page $page */
/** @var Kirby\Cms\Site $site */

snippet('layout', ['title' => $page->title()], slots: true);
?>
<?php slot('content') ?>
    <div class="page-heading">
        <p class="eyebrow">Return-page preview</p>
        <h1><?= esc($page->title()) ?></h1>
        <p>This is a navigation preview, not a payment result.</p>
    </div>
    <div class="notice">No order was created or changed. Payment status will come from verified Stripe events once Checkout is implemented.</div>
    <p><a class="button" href="<?= esc($site->find('products')?->url(), 'attr') ?>">Back to products →</a></p>
<?php endslot() ?>
