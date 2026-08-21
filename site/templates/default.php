<?php

/** @var Kirby\Cms\Page $page */

snippet('layout', ['title' => $page->title()], slots: true);
?>

<?php slot('content') ?>
    <p class="eyebrow">Development fixture</p>
    <h1><?= esc($page->title()) ?></h1>
    <?= $page->text()->kt() ?>
<?php endslot() ?>
