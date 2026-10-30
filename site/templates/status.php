<?php

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutResultReason;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;

/** @var Kirby\Cms\Page $page */
/** @var Kirby\Cms\Site $site */

$stripeCheckout = $site->stripeCheckout();
$checkoutResult = $stripeCheckout->checkoutResult();
$checkoutResultMessage = null;
$embeddedCheckoutBootstrap = null;
$embeddedResumeMessage = null;

if ($checkoutResult?->reason() === CheckoutResultReason::HostedSuccess) {
    $checkoutResultMessage = match ([$checkoutResult->checkoutStatus(), $checkoutResult->paymentStatus()]) {
        [CheckoutStatus::Complete, PaymentStatus::Paid] => 'Checkout completed and payment is confirmed.',
        [CheckoutStatus::Complete, PaymentStatus::NoPaymentRequired] => 'Checkout completed with no payment required.',
        [CheckoutStatus::Complete, PaymentStatus::Pending] => 'Checkout completed and payment is still processing.',
        [CheckoutStatus::Complete, PaymentStatus::Failed] => 'Checkout completed, but payment failed.',
        default => 'The browser returned from Checkout. Review the current status below.',
    };
} elseif ($checkoutResult?->reason() === CheckoutResultReason::HostedCancel) {
    $checkoutResultMessage = 'Checkout was cancelled. The cart and Order remain unchanged.';
} elseif ($checkoutResult?->reason() === CheckoutResultReason::EmbeddedReturn) {
    $checkoutResultMessage = 'The browser returned from Embedded Checkout. Review the current status below.';
}

if (($checkoutSource = $checkoutResult?->resumableCheckoutSource()) !== null) {
    if ($stripeCheckout->settings()->uiMode() !== UiMode::Embedded) {
        $embeddedResumeMessage = 'This Checkout cannot be resumed while the store uses hosted mode.';
    } else {
        try {
            $embeddedCheckoutBootstrap = $stripeCheckout->checkout($checkoutSource);
        } catch (CheckoutInputException $error) {
            $embeddedResumeMessage = match ([$error->errorCode(), $checkoutSource]) {
                [CheckoutErrorCode::ATTEMPT_CONFLICT, CheckoutSource::Cart] => 'The cart changed after Checkout started. Return to the cart to start a new Checkout.',
                default => 'This Checkout can no longer be resumed.',
            };
        } catch (ConfigurationException) {
            $embeddedResumeMessage = 'This Checkout cannot be resumed until the store configuration is complete.';
        }
    }
}

snippet('layout', ['title' => $page->title()], slots: true);
?>
<?php slot('content') ?>
    <div class="page-heading">
        <p class="eyebrow">Checkout result</p>
        <h1><?= esc($page->title()) ?></h1>
        <p><?= esc($checkoutResultMessage ?? $page->text()) ?></p>
    </div>
    <?php if ($checkoutResult === null): ?>
        <div class="notice">No current browser Checkout result is available. Result details are session-bound, destination-bound and consumed once.</div>
    <?php elseif ($checkoutResult->isAvailable() === false): ?>
        <div class="notice">The return was received, but current Order facts are unavailable. Check the Order in the Panel or wait for its webhook update.</div>
    <?php else: ?>
        <dl class="checkout-result">
            <div><dt>Navigation</dt><dd><?= esc(str_replace('_', ' ', $checkoutResult->reason()->value)) ?></dd></div>
            <div><dt>Order</dt><dd><?= esc($checkoutResult->order()?->title() ?? 'Unavailable') ?></dd></div>
            <div><dt>Checkout status</dt><dd><?= esc($checkoutResult->checkoutStatus()?->value ?? 'Unavailable') ?></dd></div>
            <div><dt>Payment status</dt><dd><?= esc($checkoutResult->paymentStatus()?->value ?? 'Unavailable') ?></dd></div>
        </dl>
        <?php if ($checkoutResult->payment()?->nextAction() !== null): ?>
            <p class="notice">The payment method requires another customer action. This browser result contains the current provider instructions.</p>
        <?php endif ?>
    <?php endif ?>
    <?php if ($embeddedCheckoutBootstrap !== null): ?>
        <h2>Resume Checkout</h2>
        <p class="checkout-note">The original Stripe Checkout Session is still open, so it can continue without creating another Order.</p>
        <?php snippet('embedded-checkout', ['checkoutBootstrap' => $embeddedCheckoutBootstrap]) ?>
    <?php elseif ($embeddedResumeMessage !== null): ?>
        <p class="notice"><?= esc($embeddedResumeMessage) ?></p>
    <?php endif ?>
    <p class="checkout-note">Browser navigation is not payment proof. Verified Stripe events remain authoritative for later payment changes.</p>
    <p><a class="button" href="<?= esc($site->find('products')?->url(), 'attr') ?>">Back to products →</a></p>
<?php endslot() ?>
