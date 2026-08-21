<?php

/** @var Kirby\Cms\Page $page */

snippet('layout', ['title' => $page->title()], slots: true);
?>

<?php slot('content') ?>
    <p class="eyebrow">Checkout navigation fixture</p>
    <h1><?= esc($page->title()) ?></h1>
    <?= $page->text()->kt() ?>
    <p>This page never marks an order as paid; verified Stripe webhooks remain authoritative.</p>
<?php endslot() ?>
