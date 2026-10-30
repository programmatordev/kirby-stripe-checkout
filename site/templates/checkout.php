<?php

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;

/** @var Kirby\Cms\Page $page */
/** @var Kirby\Cms\Site $site */

$stripeCheckout = $site->stripeCheckout();
$cart = $stripeCheckout->cart();
$checkoutBootstrap = null;
$checkoutUnavailableMessage = null;

if ($stripeCheckout->settings()->uiMode() !== UiMode::Embedded) {
    $checkoutUnavailableMessage = 'Switch the store to Embedded Checkout mode to use this fixture.';
} elseif ($cart === null || $cart->isEmpty()) {
    $checkoutUnavailableMessage = 'Add a product to the cart before opening Embedded Checkout.';
} else {
    try {
        $checkoutBootstrap = $stripeCheckout->checkout(CheckoutSource::Cart);
    } catch (CheckoutInputException|ConfigurationException) {
        $checkoutUnavailableMessage = 'Embedded Checkout is unavailable. Review the cart and store configuration.';
    }
}

snippet('layout', ['title' => $page->title(), 'cart' => $cart], slots: true);
?>
<?php slot('content') ?>
    <a class="back-link" href="<?= esc($site->find('products')?->url(), 'attr') ?>">← Continue shopping</a>
    <div class="page-heading">
        <p class="eyebrow">Checkout preview</p>
        <h1><?= esc($page->title()) ?></h1>
        <p><?= esc($page->text()) ?></p>
    </div>
    <?php if ($checkoutUnavailableMessage !== null): ?>
        <div class="notice error" role="alert"><?= esc($checkoutUnavailableMessage) ?></div>
    <?php elseif ($checkoutBootstrap !== null): ?>
        <?php snippet('embedded-checkout', ['checkoutBootstrap' => $checkoutBootstrap]) ?>
    <?php endif ?>
<?php endslot() ?>
