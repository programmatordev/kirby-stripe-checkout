<?php

/** @var Kirby\Cms\Page $page */
/** @var Kirby\Cms\Site $site */

snippet('layout', ['title' => $page->title()], slots: true);
?>

<?php slot('content') ?>
    <p class="eyebrow">Kirby 5 · Stripe Checkout</p>
    <h1><?= esc($page->headline()->or($page->title())) ?></h1>
    <?= $page->intro()->kt() ?>

    <div class="grid">
        <article class="card">
            <h2>Product fixtures</h2>
            <p>Paid, free, physical, digital, and option-bearing source pages.</p>
            <a href="<?= esc($site->find('products')?->url()) ?>">Review products</a>
        </article>
        <article class="card">
            <h2>Hosted mode</h2>
            <p>Development context for redirecting customers to Stripe-hosted Checkout.</p>
            <a href="<?= esc($site->find('checkout-hosted')?->url()) ?>">Review hosted mode</a>
        </article>
        <article class="card">
            <h2>Embedded mode</h2>
            <p>Development context for mounting Checkout within a Kirby page.</p>
            <a href="<?= esc($site->find('checkout-embedded')?->url()) ?>">Review embedded mode</a>
        </article>
    </div>
<?php endslot() ?>
