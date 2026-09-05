<?php

/** @var Kirby\Cms\Page $page */
/** @var Kirby\Cms\Site $site */

$mode = $page->mode()->or('hosted')->value();
snippet('layout', ['title' => $page->title()], slots: true);
?>
<?php slot('content') ?>
    <a class="back-link" href="<?= esc($site->find('products')?->url(), 'attr') ?>">← Continue shopping</a>
    <div class="page-heading">
        <p class="eyebrow">Checkout preview</p>
        <h1><?= esc(ucfirst($mode)) ?> Checkout</h1>
        <p><?= $mode === 'embedded' ? 'Checkout will appear here, inside the store.' : 'This flow will send the buyer to Stripe to complete their order.' ?></p>
    </div>
    <div class="notice">
        <strong>Not connected yet</strong>
        <p>You can test the cart now. Payment, order creation and Checkout submission are not available.</p>
    </div>
<?php endslot() ?>
