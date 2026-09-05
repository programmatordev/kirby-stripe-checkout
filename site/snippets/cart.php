<?php

/** @var Kirby\Cms\Site $site */
/** @var ProgrammatorDev\StripeCheckout\Cart\Cart|null $cart */
/** @var ProgrammatorDev\StripeCheckout\Cart\CartRenderContext|null $context */

$endpoint = $site->url() . '/stripe-checkout/cart';
$error = isset($context) ? $context->error() : null;
$checkout = $site->stripeCheckout();
?>
<section class="card" data-cart-view data-cart-url="<?= esc($endpoint, 'attr') ?>" aria-label="Cart">
    <h2>Your cart</h2>
    <?php if ($error !== null): ?>
        <p role="alert"><?= esc($error->message()) ?></p>
    <?php endif ?>
    <?php if ($cart === null): ?>
        <p>The cart is unavailable. If it is enabled, refresh the page and try again.</p>
    <?php else: ?>
        <?php foreach ($cart->errors() as $cartError): ?>
            <p role="alert"><?= esc($cartError->message()) ?></p>
        <?php endforeach ?>
        <?php if ($cart->isEmpty()): ?>
            <p>Your cart is empty.</p>
        <?php endif ?>
        <?php foreach ($cart->items() as $item): ?>
            <article class="cart-item">
                <?php if ($image = $item->image()): ?>
                    <img src="<?= esc($image->crop(100, 100)->url(), 'attr') ?>" alt="" width="100" height="100">
                <?php endif ?>
                <h3><?= esc($item->product()?->name() ?? 'Unavailable product') ?></h3>
                <?php foreach ($item->options() as $option): ?>
                    <p><?= esc($option->optionName()) ?>: <?= esc($option->valueName()) ?></p>
                <?php endforeach ?>
                <?php if ($item->price() !== null): ?>
                    <p><?= esc($checkout->formatMoney($item->price())) ?> each · <?= esc($checkout->formatMoney($item->subtotal())) ?> subtotal</p>
                <?php endif ?>
                <form action="<?= esc($endpoint . '/items/' . rawurlencode($item->id()), 'attr') ?>" method="post" data-cart-method="PATCH">
                    <input type="hidden" name="csrf" value="<?= esc(csrf(), 'attr') ?>">
                    <input type="hidden" name="revision" value="<?= esc($cart->revision(), 'attr') ?>">
                    <label>Quantity <input type="number" name="quantity" value="<?= $item->quantity() ?>" min="1" step="1" required></label>
                    <button>Update</button>
                </form>
                <form action="<?= esc($endpoint . '/items/' . rawurlencode($item->id()), 'attr') ?>" method="post" data-cart-method="DELETE">
                    <input type="hidden" name="csrf" value="<?= esc(csrf(), 'attr') ?>">
                    <input type="hidden" name="revision" value="<?= esc($cart->revision(), 'attr') ?>">
                    <button>Remove</button>
                </form>
            </article>
        <?php endforeach ?>
        <?php if ($cart->subtotal() !== null): ?>
            <p><strong>Subtotal: <?= esc($checkout->formatMoney($cart->subtotal())) ?></strong></p>
        <?php endif ?>
        <?php if ($cart->isEmpty() === false): ?>
            <form action="<?= esc($endpoint, 'attr') ?>" method="post" data-cart-method="DELETE">
                <input type="hidden" name="csrf" value="<?= esc(csrf(), 'attr') ?>">
                <input type="hidden" name="revision" value="<?= esc($cart->revision(), 'attr') ?>">
                <button>Clear cart</button>
            </form>
        <?php endif ?>
    <?php endif ?>
</section>
