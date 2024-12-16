<?php

namespace ProgrammatorDev\StripeCheckout\Exception;

class CheckoutWebhookException extends \Exception
{
    protected $code = 400;
}
