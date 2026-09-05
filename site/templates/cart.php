<?php

/** @var Kirby\Cms\Page $page */

snippet('layout', ['title' => $page->title()], slots: true);
?>
<?php slot('content') ?>
    <h1><?= esc($page->title()) ?></h1>
    <p>Add products from the catalogue, then change quantities or remove items here. Checkout submission is not available yet.</p>
<?php endslot() ?>
