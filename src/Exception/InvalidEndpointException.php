<?php

namespace ProgrammatorDev\StripeCheckout\Exception;

class InvalidEndpointException extends \Exception
{
    protected $code = 400;
}
