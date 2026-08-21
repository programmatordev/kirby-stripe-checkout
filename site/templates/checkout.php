<?php

/** @var Kirby\Cms\Page $page */

$fixtureMode = $page->mode()->or('hosted')->value();
$configuredMode = option('programmatordev.stripe-checkout.uiMode', 'hosted');
$endpoint = $fixtureMode === 'embedded' ? '/stripe/checkout/embedded' : '/stripe/checkout';
?>
<?php snippet('layout', ['title' => $page->title()], slots: true) ?>

<?php slot('content') ?>
    <p class="eyebrow"><?= esc(ucfirst($fixtureMode)) ?> Checkout</p>
    <h1><?= esc($page->title()) ?></h1>
    <?= $page->text()->kt() ?>

    <div class="card">
        <p><strong>Fixture mode:</strong> <code><?= esc($fixtureMode) ?></code></p>
        <p><strong>Effective local mode:</strong> <code><?= esc($configuredMode) ?></code></p>
        <p><strong>Current endpoint:</strong> <code><?= esc($endpoint) ?></code></p>
        <?php if ($fixtureMode !== $configuredMode): ?>
            <p>Set <code>KIRBY_STRIPE_CHECKOUT_UI_MODE=<?= esc($fixtureMode) ?></code> in <code>.ddev/.env</code> and restart DDEV to exercise this mode.</p>
        <?php endif ?>
    </div>

    <p>The fixture does not start a real Checkout Session with placeholder credentials. Interactive flows will be added with the redesigned Checkout pipeline.</p>
<?php endslot() ?>
