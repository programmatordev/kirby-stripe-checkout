<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use InvalidArgumentException;
use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\InvalidSessionRequestException;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestErrorCode;
use Throwable;

/** Applies registered filters before auditing the complete request. */
final class SessionRequestCustomizer
{
    public const FILTER = 'programmatordev.stripe-checkout.session.parameters';

    public function __construct(
        private readonly App $kirby,
        private readonly SessionRequestValidator $validator = new SessionRequestValidator(),
    ) {}

    public function customize(
        SessionRequestContext $context,
        SessionRequest $request,
    ): SessionRequest {
        try {
            $parameters = $this->kirby->apply(self::FILTER, [
                'parameters' => $request->parameters(),
                'context' => $context,
            ], 'parameters');
        } catch (Throwable $error) {
            throw new InvalidSessionRequestException(
                SessionRequestErrorCode::FILTER_FAILED,
                previous: $error,
            );
        }

        if (is_array($parameters) === false || ($parameters !== [] && array_is_list($parameters))) {
            throw new InvalidSessionRequestException(SessionRequestErrorCode::FILTER_INVALID);
        }

        try {
            $customizedRequest = new SessionRequest($parameters);
        } catch (InvalidArgumentException $error) {
            throw new InvalidSessionRequestException(
                SessionRequestErrorCode::FILTER_INVALID,
                previous: $error,
            );
        }

        return $this->validator->validate($request, $customizedRequest);
    }
}
