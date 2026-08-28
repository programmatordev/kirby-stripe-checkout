<?php

/** @var Kirby\Cms\Page $page */

$fixtureMode = $page->mode()->or('hosted')->value();
?>
<?php snippet('layout', ['title' => $page->title()], slots: true) ?>

<?php slot('content') ?>
    <p class="eyebrow"><?= esc(ucfirst($fixtureMode)) ?> Checkout</p>
    <h1><?= esc($page->title()) ?></h1>
    <?= $page->text()->kt() ?>

    <div class="card">
        <p><strong>Fixture mode:</strong> <code><?= esc($fixtureMode) ?></code></p>
        <p>The fixture records the intended storefront context without assuming a runtime configuration or route contract.</p>
    </div>

    <p>Checkout behavior is not registered in the current foundation. Interactive flows will be added with the redesigned Checkout pipeline.</p>
<?php endslot() ?>
