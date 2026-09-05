<?php

/** @var Kirby\Cms\App $kirby */
/** @var Kirby\Cms\Page $page */
/** @var Kirby\Cms\Site $site */
/** @var Kirby\Template\Slots $slots */
/** @var Kirby\Content\Field|string|null $title */

$documentTitle = isset($title) ? $title . ' · Test store' : 'Test store';
$languageCode = $kirby->language()?->code() ?? 'en';
$cart = $site->stripeCheckout()->cart();
$settings = $site->stripeCheckout()->settings();
?>
<!doctype html>
<html lang="<?= esc($languageCode, 'attr') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($documentTitle) ?></title>
    <?php snippet('storefront-styles') ?>
</head>
<body>
    <a class="skip-link" href="#content">Skip to content</a>
    <header class="site-header">
        <a class="brand" href="<?= esc($site->url(), 'attr') ?>">Test store<span>Kirby × Stripe Checkout</span></a>
        <nav aria-label="Store navigation">
            <a href="<?= esc($site->find('products')?->url(), 'attr') ?>">Products</a>
            <?php if ($cart !== null): ?><a href="#cart" data-open-cart>Cart</a><?php endif ?>
            <details class="test-menu">
                <summary>Test pages</summary>
                <div>
                    <?php foreach (['checkout-hosted', 'checkout-embedded', 'checkout-success', 'checkout-cancel', 'checkout-return'] as $id): ?>
                        <?php if ($testPage = $site->find($id)): ?>
                            <a href="<?= esc($testPage->url(), 'attr') ?>"><?= esc($testPage->title()) ?></a>
                        <?php endif ?>
                    <?php endforeach ?>
                </div>
            </details>
            <a href="<?= esc($kirby->url('panel'), 'attr') ?>">Panel ↗</a>
        </nav>
        <nav class="languages" aria-label="Language">
            <?php foreach ($kirby->languages() as $language): ?>
                <a href="<?= esc($page->url($language->code()), 'attr') ?>" lang="<?= esc($language->code(), 'attr') ?>" <?= $language->code() === $languageCode ? 'aria-current="true"' : '' ?>><?= esc(strtoupper($language->code())) ?></a>
            <?php endforeach ?>
        </nav>
    </header>
    <div class="store-context">
        <span><span class="status-dot" aria-hidden="true"></span> Development store · No payments</span>
        <span><?= esc(ucfirst($settings->priceSource()->value)) ?> prices · <?= esc($settings->currency() ?? 'Currency not set') ?></span>
    </div>
    <div class="store-layout <?= $cart === null ? 'without-cart' : '' ?>">
        <main id="content" tabindex="-1"><?= $slots->content() ?></main>
        <?php if ($cart !== null): ?>
            <aside id="cart" class="cart-sidebar" aria-label="Shopping cart">
                <?php snippet('cart', ['cart' => $cart, 'site' => $site]) ?>
                <?php // Keep the live region outside the replaced fragment so announcements remain reliable. ?>
                <div class="cart-feedback">
                    <p data-cart-feedback role="status" aria-live="polite" aria-atomic="true"></p>
                    <button class="text-button" type="button" data-cart-refresh>Refresh cart</button>
                </div>
            </aside>
            <?php snippet('cart-script') ?>
        <?php endif ?>
    </div>
    <footer class="site-footer">
        <span>A small store for testing products, options and your cart.</span>
        <span>Checkout is not available yet.</span>
    </footer>
    <noscript><p class="notice">Cart controls need JavaScript in this development storefront.</p></noscript>
</body>
</html>
