<?php

use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;

/** @var Kirby\Cms\Page $product */
/** @var Kirby\Cms\Site $site */

$stripeCheckout = $site->stripeCheckout();
$settings = $stripeCheckout->settings();
$formattedPrice = null;

try {
    if ($settings->priceSource() === PriceSource::Stripe) {
        $price = $product->stripePrice()->toProductStripePrice()?->price();
        $formattedPrice = $price === null ? null : $stripeCheckout->formatMoney($price);
    } elseif ($settings->currency() !== null && $product->price()->isNotEmpty()) {
        $formattedPrice = $stripeCheckout->formatMoney($product->price()->value(), $settings->currency());
    }
} catch (Throwable) {
    // A missing/invalid test price must not prevent browsing the other fixtures.
    // Never expose provider messages or credentials in storefront HTML.
}
?>
<p class="product-price" data-product-price><?= esc($formattedPrice ?? 'Price unavailable') ?></p>
