<?php

namespace ProgrammatorDev\StripeCheckout\Exception;

class InvalidWebhookException extends \Exception
{
    protected $code = 400;
}
