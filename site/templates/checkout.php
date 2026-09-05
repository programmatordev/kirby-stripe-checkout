<?php

/** @var Kirby\Cms\Page $page */
/** @var Kirby\Cms\Site $site */

$fixtureMode = $page->mode()->or('hosted')->value();
$example = $site->find('products/digital-product');
?>
<?php snippet('layout', ['title' => $page->title()], slots: true) ?>

<?php slot('content') ?>
    <p class="eyebrow"><?= esc(ucfirst($fixtureMode)) ?> Checkout</p>
    <h1><?= esc($page->title()) ?></h1>
    <?= $page->text()->kt() ?>

    <div class="card">
        <p><strong>Fixture mode:</strong> <code><?= esc($fixtureMode) ?></code></p>
        <p>This is a Checkout context fixture, not a working payment form.</p>
    </div>

    <p>Checkout behavior is not registered in the current foundation. Interactive flows will be added with the redesigned Checkout pipeline.</p>
    <?php if ($example !== null): ?>
        <h2>Cartless input example</h2>
        <p>A buy-now flow uses the same product selection shape without adding anything to the browser cart. This example only displays input; it does not submit a Checkout request or create an attempt token.</p>
        <pre><code><?= esc(json_encode([
            'source' => 'direct',
            'items' => [[
                'reference' => $example->id(),
                'quantity' => 1,
                'options' => (object) [],
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?></code></pre>
    <?php endif ?>
<?php endslot() ?>
