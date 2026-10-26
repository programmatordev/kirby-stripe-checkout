<?php

use ProgrammatorDev\StripeCheckout\Checkout\Internal\SubmissionFieldsFormatter;

/** @var ProgrammatorDev\StripeCheckout\Checkout\CheckoutBootstrap $checkoutBootstrap */

$submissionFields = (new SubmissionFieldsFormatter())->format($checkoutBootstrap->submissionData());
?>
<?php foreach ($submissionFields as $name => $value): ?>
    <input type="hidden" name="<?= esc($name, 'attr') ?>" value="<?= esc($value, 'attr') ?>">
<?php endforeach ?>
