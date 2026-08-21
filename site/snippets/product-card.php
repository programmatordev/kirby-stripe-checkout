<?php

/** @var Kirby\Cms\Page $product */
?>
<article class="card">
    <p class="eyebrow"><?= $product->requiresShipping()->toBool() ? 'Physical' : 'No shipping' ?></p>
    <h2><a href="<?= esc($product->url()) ?>"><?= esc($product->title()) ?></a></h2>
    <p><?= esc($product->summary()) ?></p>
    <p><strong><?= number_format($product->price()->toFloat(), 2) ?> EUR</strong></p>
</article>
