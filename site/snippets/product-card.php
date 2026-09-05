<?php

/** @var Kirby\Cms\Page $product */
/** @var Kirby\Cms\Site $site */

use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;

$stripeCheckout = $site->stripeCheckout();
$settings = $stripeCheckout->settings();
$currency = $settings->currency();
$formattedPrice = null;

if ($settings->priceSource() === PriceSource::Stripe) {
    $stripePrice = $product->stripePrice()->toProductStripePrice();
    $formattedPrice = $stripePrice === null
        ? null
        : $stripeCheckout->formatMoney($stripePrice->price());
} else {
    $price = $product->price()->value();

    if ($price !== null && $price !== '') {
        $formattedPrice = $currency === null
            ? $price
            : $stripeCheckout->formatMoney($price, $currency);
    }
}
?>
<article class="card">
    <p class="eyebrow"><?= $product->requiresShipping()->toBool() ? 'Physical' : 'No shipping' ?></p>
    <h2><a href="<?= esc($product->url()) ?>"><?= esc($product->title()) ?></a></h2>
    <p><?= esc($product->description()) ?></p>
    <?php if ($formattedPrice !== null): ?>
        <p><strong><?= esc($formattedPrice) ?></strong></p>
    <?php endif ?>
</article>
