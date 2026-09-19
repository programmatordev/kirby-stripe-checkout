<?php

/** @var Kirby\Cms\Site $site */
/** @var ProgrammatorDev\StripeCheckout\Cart\Cart|null $cart */
/** @var ProgrammatorDev\StripeCheckout\Cart\CartRenderContext|null $context */

$endpoint = $site->url() . '/stripe-checkout/cart';
$error = isset($context) ? $context->error() : null;
$checkout = $site->stripeCheckout();
$shippingQuote = $cart?->shippingQuote();
$destinationCountries = $cart?->destinationCountries() ?? [];
?>
<details class="cart-panel" data-cart-view data-cart-url="<?= esc($endpoint, 'attr') ?>" open>
    <summary>
        <span class="cart-title">Your cart</span>
        <span class="cart-count"><?= $cart?->totalQuantity() ?? '–' ?></span>
        <span class="cart-chevron" aria-hidden="true">⌄</span>
    </summary>
    <div class="cart-content">
        <?php if ($error !== null): ?>
            <p class="notice error" role="alert"><?= esc($error->message()) ?></p>
        <?php endif ?>
        <?php if ($cart === null): ?>
            <p class="notice">The cart is unavailable. Refresh the cart or check the store configuration.</p>
        <?php else: ?>
            <?php foreach ($cart->errors() as $cartError): ?>
                <p class="notice error" role="alert"><?= esc($cartError->message()) ?></p>
            <?php endforeach ?>
            <?php if ($cart->isEmpty()): ?>
                <div class="cart-empty">
                    <strong>Your cart is empty.</strong>
                    <p>Find something you like.<br>It will appear here when you add it.</p>
                </div>
            <?php endif ?>
            <?php foreach ($cart->items() as $item): ?>
                <article class="cart-item" data-cart-item="<?= esc($item->id(), 'attr') ?>">
                    <div class="cart-item-heading">
                        <?php if ($image = $item->image()): ?>
                            <img src="<?= esc($image->crop(96, 96)->url(), 'attr') ?>" alt="" width="48" height="48">
                        <?php endif ?>
                        <div>
                            <h3><?= esc($item->product()?->name() ?? 'Unavailable product') ?></h3>
                            <?php foreach ($item->options() as $option): ?>
                                <p class="cart-options"><?= esc($option->optionName()) ?>: <?= esc($option->valueName()) ?></p>
                            <?php endforeach ?>
                            <?php if ($item->price() !== null): ?>
                                <p class="cart-unit-price"><?= esc($checkout->formatMoney($item->price())) ?> each</p>
                            <?php endif ?>
                        </div>
                        <?php if ($item->subtotal() !== null): ?>
                            <span class="cart-item-total"><?= esc($checkout->formatMoney($item->subtotal())) ?></span>
                        <?php endif ?>
                    </div>
                    <div class="cart-item-controls">
                        <form action="<?= esc($endpoint . '/items/' . rawurlencode($item->id()), 'attr') ?>" method="post" data-cart-method="PATCH">
                            <fieldset class="quantity-controls">
                                <input type="hidden" name="csrf" value="<?= esc(csrf(), 'attr') ?>">
                                <input type="hidden" name="revision" value="<?= esc($cart->revision(), 'attr') ?>">
                                <label>Quantity <input type="number" name="quantity" value="<?= $item->quantity() ?>" min="1" step="1" required></label>
                                <button type="submit" aria-label="Update quantity for <?= esc($item->product()?->name() ?? 'item', 'attr') ?>">Update</button>
                            </fieldset>
                        </form>
                        <form action="<?= esc($endpoint . '/items/' . rawurlencode($item->id()), 'attr') ?>" method="post" data-cart-method="DELETE">
                            <fieldset>
                                <input type="hidden" name="csrf" value="<?= esc(csrf(), 'attr') ?>">
                                <input type="hidden" name="revision" value="<?= esc($cart->revision(), 'attr') ?>">
                                <button class="text-button" type="submit" aria-label="Remove <?= esc($item->product()?->name() ?? 'item', 'attr') ?>">Remove</button>
                            </fieldset>
                        </form>
                    </div>
                </article>
            <?php endforeach ?>
            <div class="cart-total">
                <span>Subtotal</span>
                <span><?= $cart->subtotal() === null ? 'Unavailable' : esc($checkout->formatMoney($cart->subtotal())) ?></span>
            </div>
            <?php if ($shippingQuote !== null): ?>
                <section class="cart-shipping" aria-labelledby="cart-shipping-title">
                    <h3 id="cart-shipping-title">Shipping preview</h3>
                    <form action="<?= esc($endpoint, 'attr') ?>" method="post" data-cart-method="PATCH" data-cart-success="Shipping destination updated.">
                        <fieldset>
                            <input type="hidden" name="csrf" value="<?= esc(csrf(), 'attr') ?>">
                            <input type="hidden" name="revision" value="<?= esc($cart->revision(), 'attr') ?>">
                            <label>
                                Destination country
                                <select name="destinationCountry">
                                    <option value="">Choose a country</option>
                                    <?php foreach ($destinationCountries as $country => $name): ?>
                                        <option value="<?= esc($country, 'attr') ?>"<?= $cart->destinationCountry() === $country ? ' selected' : '' ?>><?= esc($name) ?></option>
                                    <?php endforeach ?>
                                </select>
                            </label>
                            <button type="submit">Update shipping</button>
                        </fieldset>
                    </form>
                    <?php if ($shippingQuote->status() === ProgrammatorDev\StripeCheckout\Shipping\ShippingQuoteStatus::DestinationRequired): ?>
                        <p class="cart-note">Choose a destination to preview the available shipping options.</p>
                    <?php elseif ($shippingQuote->status() === ProgrammatorDev\StripeCheckout\Shipping\ShippingQuoteStatus::Available): ?>
                        <ul class="cart-shipping-options">
                            <?php foreach ($shippingQuote->options() as $shippingOption): ?>
                                <li>
                                    <span>
                                        <strong><?= esc($shippingOption->label()) ?></strong>
                                        <?php if ($deliveryEstimate = $shippingOption->deliveryEstimate()): ?>
                                            <?php
                                            $bounds = array_values(array_filter([
                                                $deliveryEstimate->minimum(),
                                                $deliveryEstimate->maximum(),
                                            ], static fn(?int $bound): bool => $bound !== null));
                                            $unit = str_replace('_', ' ', $deliveryEstimate->unit()->value);
                                            $plural = max($bounds) === 1 ? $unit : $unit . 's';
                                            $range = match (true) {
                                                $deliveryEstimate->minimum() === null => 'Up to ' . $deliveryEstimate->maximum(),
                                                $deliveryEstimate->maximum() === null => 'From ' . $deliveryEstimate->minimum(),
                                                default => implode('–', array_unique($bounds)),
                                            };
                                            ?>
                                            <small><?= esc($range . ' ' . $plural) ?></small>
                                        <?php endif ?>
                                    </span>
                                    <strong><?= esc($checkout->formatMoney($shippingOption->amount())) ?></strong>
                                </li>
                            <?php endforeach ?>
                        </ul>
                        <p class="cart-note">The customer chooses the final shipping option in Checkout.</p>
                    <?php endif ?>
                </section>
            <?php endif ?>
            <p class="cart-note">Tax is calculated later in Checkout when configured.</p>
            <?php if (!$cart->isEmpty()): ?>
                <button class="cart-checkout" type="button" disabled>Checkout — coming later</button>
                <form class="cart-clear" action="<?= esc($endpoint, 'attr') ?>" method="post" data-cart-method="DELETE">
                    <fieldset>
                        <input type="hidden" name="csrf" value="<?= esc(csrf(), 'attr') ?>">
                        <input type="hidden" name="revision" value="<?= esc($cart->revision(), 'attr') ?>">
                        <button class="text-button" type="submit">Clear cart</button>
                    </fieldset>
                </form>
            <?php endif ?>
        <?php endif ?>
    </div>
</details>
