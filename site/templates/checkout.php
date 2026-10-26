<?php

/** @var Kirby\Cms\Page $page */
/** @var Kirby\Cms\Site $site */

$cart = $site->stripeCheckout()->cart();
snippet('layout', ['title' => $page->title(), 'cart' => $cart], slots: true);
?>
<?php slot('content') ?>
    <a class="back-link" href="<?= esc($site->find('products')?->url(), 'attr') ?>">← Continue shopping</a>
    <div class="page-heading">
        <p class="eyebrow">Checkout preview</p>
        <h1><?= esc($page->title()) ?></h1>
        <p><?= esc($page->text()) ?></p>
    </div>
    <div class="notice">
        <strong>Embedded Checkout is not connected yet.</strong>
        <p>Hosted Checkout starts directly from the cart while the embedded storefront step remains pending.</p>
    </div>
<?php endslot() ?>
