<?php

/** @var Kirby\Cms\Page $page */

snippet('layout', ['title' => $page->title()], slots: true);
?>

<?php slot('content') ?>
    <p class="eyebrow"><?= $page->requiresShipping()->toBool() ? 'Physical product' : 'Digital or service product' ?></p>
    <h1><?= esc($page->title()) ?></h1>
    <p><?= esc($page->summary()) ?></p>
    <p><strong><?= number_format($page->price()->toFloat(), 2) ?> EUR</strong></p>

    <?php if ($page->productOptions()->isNotEmpty()): ?>
        <h2>Available options</h2>
        <ul>
            <?php foreach ($page->productOptions()->toStructure() as $option): ?>
                <li><strong><?= esc($option->label()) ?>:</strong> <?= esc($option->values()) ?></li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>

    <p><code><?= esc($page->id()) ?></code></p>
<?php endslot() ?>
